<?php
declare(strict_types=1);

namespace Atlas;

use RuntimeException;

/**
 * "Download my AI": makes a folder with the installer plus this PC's memory and the chosen AI name.
 * The big base installer (web server, AI, models) is built once; every download only adds the memory card.
 * The model is always Mnemo and the logo is always the ATLAS · Mnemo logo.
 */
final class Package
{
    private static function jobFile(): string { return atlas_config()['storage_root'] . '/package.json'; }

    /** Base installer folder: ...\base (with the picture model) or ...\base-lite (without, about 3 GB smaller). */
    public static function baseDir(bool $vision = false): string
    {
        return rtrim(str_replace('\\', '/', (string)(atlas_config()['package']['base_dir'] ?? 'C:/ATLAS-build/base')), '/') . ($vision ? '' : '-lite');
    }

    /** Files of the base installer: ATLAS-Setup.exe and its ATLAS-Setup-N.bin parts. */
    public static function baseFiles(bool $vision = false): array
    {
        $d = self::baseDir($vision);
        if (!is_file("$d/ATLAS-Setup.exe")) return [];
        $files = ["$d/ATLAS-Setup.exe"];
        foreach (glob("$d/ATLAS-Setup-*.bin") ?: [] as $f) $files[] = $f;
        return $files;
    }

    /** Is the job's background process still running? (no pid yet: allow 30 s to start) */
    private static function alive(array $job): bool
    {
        $pid = (int)($job['pid'] ?? 0);
        if (!$pid) return time() - (int)($job['ts'] ?? 0) < 30;
        if (PHP_OS_FAMILY === 'Windows') {
            $o = (string)@shell_exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL');
            return stripos($o, 'php') !== false;
        }
        return function_exists('posix_kill') ? @posix_kill($pid, 0) : time() - (int)($job['ts'] ?? 0) < 120;
    }

    private static function isccFound(): bool
    {
        foreach ([getenv('ProgramFiles'), getenv('ProgramFiles(x86)'), getenv('LOCALAPPDATA') . '/Programs'] as $d)
            foreach (['Inno Setup 7', 'Inno Setup 6'] as $v) if ($d && is_file("$d/$v/ISCC.exe")) return true;
        return false;
    }

    public static function info(): array
    {
        $sizes = [];
        foreach (['lite' => false, 'full' => true] as $k => $v) { $n = 0; foreach (self::baseFiles($v) as $f) $n += (int)@filesize($f); $sizes[$k] = $n; }
        $default = PHP_OS_FAMILY === 'Windows' ? (is_dir('D:/') ? 'D:\\ATLAS-Downloads' : 'C:\\ATLAS-Downloads') : sys_get_temp_dir() . '/ATLAS-Downloads';
        $job = json_decode((string)@file_get_contents(self::jobFile()), true);
        // A job whose background process is gone (PC restarted, crashed, old version) is shown as stopped, never as "working" forever
        if (is_array($job) && ($job['state'] ?? '') === 'working' && !self::alive($job)) {
            $job['state'] = 'error'; $job['msg'] = 'The download stopped (the PC was restarted or it never started). Click Download to try again.';
            @file_put_contents(self::jobFile(), json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $db = Db::get();
        return [
            'base_ready' => ['lite' => $sizes['lite'] > 0, 'full' => $sizes['full'] > 0], 'base_size' => $sizes,
            'default_dest' => $default, 'ai_name' => Mnemo::aiName(),
            'knows' => ['sources' => (int)$db->val("SELECT COUNT(*) FROM documents WHERE sha256 <> 'taught-knowledge'"), 'concepts' => (int)$db->val('SELECT COUNT(*) FROM entities')],
            'job' => is_array($job) ? $job : null,
            'log' => is_array($job) && (($job['state'] ?? '') === 'error' || (($job['pct'] ?? 0) == 0 && time() - (int)($job['ts'] ?? 0) > 20))
                ? trim(@file_get_contents(atlas_config()['storage_root'] . '/logs/package-err.log') . "\n" . @file_get_contents(atlas_config()['storage_root'] . '/logs/package.log')) : '',
        ];
    }

    /** Stops a running download (and a first-time build it started). */
    public static function cancel(): array
    {
        $job = json_decode((string)@file_get_contents(self::jobFile()), true);
        if (!is_array($job)) return ['ok' => true];
        $pid = (int)($job['pid'] ?? 0);
        if ($pid && PHP_OS_FAMILY === 'Windows') @exec("taskkill /PID $pid /T /F 2>&1");
        elseif ($pid && function_exists('posix_kill')) @posix_kill($pid, 9);
        $job['state'] = 'error'; $job['msg'] = 'Cancelled.'; $job['ts'] = time();
        file_put_contents(self::jobFile(), json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['ok' => true];
    }

    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') throw new RuntimeException('Type a name for your AI.');
        if (mb_strlen($name) > 40) throw new RuntimeException('Use a name of at most 40 characters.');
        if (!preg_match('/^[\p{L}\p{N} ._\'-]+$/u', $name)) throw new RuntimeException('Use only letters, numbers, spaces, dots, dashes and apostrophes in the name.');
        return $name;
    }

    /** Starts the copy in the background so the page stays responsive. */
    public static function start(string $name, string $dest, bool $vision = false): array
    {
        $name = self::cleanName($name);
        $dest = rtrim(trim($dest), '\\/');
        if ($dest === '') throw new RuntimeException('Choose where to save it, for example D:\\ATLAS-Downloads');
        $build = !self::baseFiles($vision);
        if ($build && PHP_OS_FAMILY === 'Windows' && !self::isccFound()) throw new RuntimeException('One free tool is missing: install Inno Setup 7 from jrsoftware.org/isdl.php, then click Download again.');
        $job = json_decode((string)@file_get_contents(self::jobFile()), true);
        if (is_array($job) && ($job['state'] ?? '') === 'working' && self::alive($job)) throw new RuntimeException('A download is already being prepared. Open the progress at the bottom right, or click Cancel there.');
        if (!is_dir($dest) && !@mkdir($dest, 0777, true)) throw new RuntimeException("Cannot create the folder $dest. Check the drive letter.");
        $need = $build ? 11 * 1073741824 : 0;
        foreach (self::baseFiles($vision) as $f) $need += (int)filesize($f);
        $free = @disk_free_space($dest);
        if ($free !== false && $free < $need + 200 * 1048576) throw new RuntimeException('Not enough free space on that drive: ' . round($need / 1073741824, 1) . ' GB needed, ' . round($free / 1073741824, 1) . ' GB free.');

        // Everything else (memory card, first-time base build, copy) runs in the background with a progress bar
        $job = ['state' => 'working', 'pct' => 0, 'msg' => 'Starting…', 'eta' => '', 'ts' => time(), 'started' => time(), 'name' => $name,
                'dest' => $dest, 'card' => '', 'files' => self::baseFiles($vision), 'build' => $build, 'vision' => $vision, 'folder' => ''];
        file_put_contents(self::jobFile(), json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $php = PHP_OS_FAMILY === 'Windows' ? (is_file(PHP_BINDIR . '\\php.exe') ? PHP_BINDIR . '\\php.exe' : 'C:\\xampp\\php\\php.exe') : (PHP_BINARY ?: 'php');
        $script = (string)realpath(__DIR__ . '/../tools/make-package.php');
        $logs = atlas_config()['storage_root'] . '/logs';
        @mkdir($logs, 0777, true);
        @unlink("$logs/package.log");
        if (PHP_OS_FAMILY === 'Windows') {
            $q = fn(string $x) => "'" . str_replace("'", "''", str_replace('/', '\\', $x)) . "'";
            $ps = 'Start-Process -FilePath ' . $q($php) . ' -ArgumentList ' . $q($script) . ',' . $q(self::jobFile())
                . ' -WindowStyle Hidden -RedirectStandardOutput ' . $q("$logs/package.log") . ' -RedirectStandardError ' . $q("$logs/package-err.log");
            @exec('powershell -NoProfile -ExecutionPolicy Bypass -Command "' . str_replace('"', '\"', $ps) . '" 2>&1', $out, $rc);
            if ($rc !== 0) pclose(popen('start "" /B "' . $php . '" "' . $script . '" "' . self::jobFile() . '"', 'r'));   // fallback
        } else {
            exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(self::jobFile()) . ' > ' . escapeshellarg("$logs/package.log") . ' 2>&1 &');
        }
        return ['started' => true];
    }

    /** Runs in the background (tools/make-package.php): memory card, base build (first time), copy. */
    public static function run(string $jobFile): void
    {
        $job = json_decode((string)file_get_contents($jobFile), true);
        $t0 = time();
        $job['pid'] = getmypid();
        register_shutdown_function(function () use ($jobFile) {
            $e = error_get_last();
            $j = json_decode((string)@file_get_contents($jobFile), true);
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && is_array($j) && ($j['state'] ?? '') === 'working') {
                $j['state'] = 'error'; $j['msg'] = 'Stopped: ' . $e['message']; $j['ts'] = time();
                file_put_contents($jobFile, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        });
        $save = function (array $set) use (&$job, $jobFile, $t0) {
            $job = $set + $job; $job['ts'] = time();
            $p = (int)($job['pct'] ?? 0); $el = time() - $t0;
            $job['eta'] = ($job['state'] ?? '') === 'working' && $p >= 3 && $el > 20 ? self::eta((int)round($el / $p * (100 - $p))) : '';
            file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        };
        $build = !empty($job['build']);
        // Overall percent: with a first-time build the build is 0-75 % and the copy 75-98 %
        $copyFrom = $build ? 75 : 3; $copyTo = 98;
        try {
            $folder = $job['dest'] . DIRECTORY_SEPARATOR . $job['name'] . ' - Setup';
            $pkg = $folder . DIRECTORY_SEPARATOR . 'mnemo-package';
            // Same name again: replace the earlier download in place (no extra dated copies filling the drive)
            if (is_dir($folder)) {
                foreach (array_merge(glob($folder . '/ATLAS-Setup*') ?: [], glob($pkg . '/*') ?: [], [$folder . '/READ ME FIRST.txt']) as $old) if (is_file($old)) @unlink($old);
            }
            if (!is_dir($pkg) && !mkdir($pkg, 0777, true)) throw new RuntimeException("Cannot create $folder");
            $save(['folder' => $folder, 'pct' => 1, 'msg' => 'Saving the memory (what it has learned)…']);

            // 1. Memory + name
            [$token] = Cards::export(Cards::activeId());
            if (!rename(Cards::exportPath($token), $pkg . DIRECTORY_SEPARATOR . 'memory.atlascard')) throw new RuntimeException('Could not save the memory.');
            file_put_contents($pkg . DIRECTORY_SEPARATOR . 'identity.json', json_encode(['ai_name' => $job['name'], 'model' => 'mnemo:7b', 'vision' => !empty($job['vision']), 'created' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $save(['pct' => $build ? 2 : 3]);

            // 2. First download on this PC: prepare the AI installer once
            if ($build) self::buildBase($save, !empty($job['vision']));
            $job['files'] = self::baseFiles(!empty($job['vision']));
            if (!$job['files']) throw new RuntimeException('Preparing the AI installer failed. Details: storage\logs\build.log');

            // 3. Copy the installer
            $total = 0;
            foreach ($job['files'] as $f) $total += (int)filesize($f);
            $done = 0; $last = 0;
            foreach ($job['files'] as $f) {
                $in = fopen($f, 'rb'); $out = fopen($folder . DIRECTORY_SEPARATOR . basename($f), 'wb');
                if (!$in || !$out) throw new RuntimeException('Cannot copy ' . basename($f));
                while (!feof($in)) {
                    $buf = fread($in, 8 * 1048576);
                    if ($buf === false || fwrite($out, $buf) === false) throw new RuntimeException('Copy failed. Is the drive full?');
                    $done += strlen($buf);
                    if (time() !== $last) {
                        $last = time();
                        $save(['pct' => $copyFrom + (int)floor($done / max(1, $total) * ($copyTo - $copyFrom)),
                               'msg' => 'Copying the AI… ' . round($done / 1073741824, 1) . ' of ' . round($total / 1073741824, 1) . ' GB']);
                    }
                }
                fclose($in); fclose($out);
            }

            file_put_contents($folder . DIRECTORY_SEPARATOR . 'READ ME FIRST.txt', str_replace("\n", "\r\n",
                "{$job['name']} (powered by Mnemo)\n" .
                "=========================================\n\n" .
                "An offline AI that already knows what it was taught.\n\n" .
                "INSTALL\n  1. Keep ALL files in this folder together (copy the whole folder).\n" .
                "  2. Double-click ATLAS-Setup.exe and click Install.\n" .
                "  3. Open \"{$job['name']}\" from the desktop. The first start takes a minute:\n" .
                "     it sets up the memory and the name by itself.\n\n" .
                "NEEDS  Windows 10/11 (64-bit), 16 GB RAM, about 20 GB free disk space.\n" .
                "       Faster with an NVIDIA graphics card.\n\n" .
                "It keeps learning: teach it more files and facts on the new PC.\n"));
            $save(['state' => 'done', 'pct' => 100, 'msg' => 'Ready']);
        } catch (\Throwable $e) {
            $save(['state' => 'error', 'msg' => $e->getMessage()]);
        }
    }

    /** Runs "Build ATLAS installer.bat newborn" and turns its progress into 2-75 %. */
    private static function buildBase(callable $save, bool $vision): void
    {
        $bat = realpath(__DIR__ . '/../installer/Build ATLAS installer.bat');
        $log = atlas_config()['storage_root'] . '/logs/build.log';
        @mkdir(dirname($log), 0777, true);
        @unlink($log);
        $stage = 'C:/ATLAS-build/stage';
        $models = self::dirSize('C:/ATLAS-AI/models') ?: 9 * 1073741824;
        $proc = proc_open('cmd /c ""' . $bat . '" newborn ' . ($vision ? 'full' : 'lite') . ' < NUL > "' . $log . '" 2>&1"', [], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('Could not start preparing the AI installer.');
        $stageTotal = 0;
        while (proc_get_status($proc)['running']) {
            sleep(2);
            $txt = (string)@file_get_contents($log);
            if (str_contains($txt, 'Compiling') || str_contains($txt, 'Compressing')) {
                // Inno Setup writes ATLAS-Setup-1.bin; it ends up about as big as the staged files
                if (!$stageTotal) $stageTotal = self::dirSize($stage) ?: $models;
                $bin = 0;
                foreach (glob(self::baseDir($vision) . '/ATLAS-Setup-*.bin') ?: [] as $f) { clearstatcache(true, $f); $bin += (int)@filesize($f); }
                $save(['pct' => 35 + (int)floor(min(1, $bin / $stageTotal) * 40), 'msg' => 'Preparing the AI installer: packing ' . round($bin / 1073741824, 1) . ' of ' . round($stageTotal / 1073741824, 1) . ' GB (first time only)']);
            } elseif (str_contains($txt, '[5/5]')) {
                $got = self::dirSize("$stage/models");
                $save(['pct' => 12 + (int)floor(min(1, $got / $models) * 23), 'msg' => 'Preparing the AI installer: copying the AI ' . round($got / 1073741824, 1) . ' of ' . round($models / 1073741824, 1) . ' GB (first time only)']);
            } else {
                $step = str_contains($txt, 'desktop app') ? 11 : (str_contains($txt, '[4/5]') ? 9 : (str_contains($txt, '[3/5]') ? 7 : (str_contains($txt, '[2/5]') ? 4 : 2)));
                $save(['pct' => $step, 'msg' => 'Preparing the AI installer (first time only)…']);
            }
        }
        proc_close($proc);
    }

    private static function dirSize(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $n = 0;
        try {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) $n += (int)@$f->getSize();
        } catch (\Throwable) {}
        return $n;
    }

    private static function eta(int $sec): string
    {
        if ($sec < 60) return 'less than a minute left';
        $m = (int)round($sec / 60);
        return $m < 90 ? "about $m min left" : 'about ' . round($m / 60, 1) . ' h left';
    }
}
