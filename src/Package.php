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

    public static function baseDir(): string
    {
        return rtrim(str_replace('\\', '/', (string)(atlas_config()['package']['base_dir'] ?? 'C:/ATLAS-build/base')), '/');
    }

    /** Files of the base installer: ATLAS-Setup.exe and its ATLAS-Setup-N.bin parts. */
    public static function baseFiles(): array
    {
        $d = self::baseDir();
        if (!is_file("$d/ATLAS-Setup.exe")) return [];
        $files = ["$d/ATLAS-Setup.exe"];
        foreach (glob("$d/ATLAS-Setup-*.bin") ?: [] as $f) $files[] = $f;
        return $files;
    }

    private static function isccFound(): bool
    {
        foreach ([getenv('ProgramFiles'), getenv('ProgramFiles(x86)'), getenv('LOCALAPPDATA') . '/Programs'] as $d)
            foreach (['Inno Setup 7', 'Inno Setup 6'] as $v) if ($d && is_file("$d/$v/ISCC.exe")) return true;
        return false;
    }

    public static function info(): array
    {
        $files = self::baseFiles();
        $size = 0;
        foreach ($files as $f) $size += (int)@filesize($f);
        $default = PHP_OS_FAMILY === 'Windows' ? (is_dir('D:/') ? 'D:\\ATLAS-Downloads' : 'C:\\ATLAS-Downloads') : sys_get_temp_dir() . '/ATLAS-Downloads';
        $job = json_decode((string)@file_get_contents(self::jobFile()), true);
        $db = Db::get();
        return [
            'base_ready' => (bool)$files, 'base_dir' => str_replace('/', '\\', self::baseDir()), 'base_size' => $size,
            'default_dest' => $default, 'ai_name' => Mnemo::aiName(),
            'knows' => ['sources' => (int)$db->val("SELECT COUNT(*) FROM documents WHERE sha256 <> 'taught-knowledge'"), 'concepts' => (int)$db->val('SELECT COUNT(*) FROM entities')],
            'job' => is_array($job) ? $job : null,
        ];
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
    public static function start(string $name, string $dest): array
    {
        $name = self::cleanName($name);
        $dest = rtrim(trim($dest), '\\/');
        if ($dest === '') throw new RuntimeException('Choose where to save it, for example D:\\ATLAS-Downloads');
        $build = !self::baseFiles();
        if ($build && PHP_OS_FAMILY === 'Windows' && !self::isccFound()) throw new RuntimeException('One free tool is missing: install Inno Setup 7 from jrsoftware.org/isdl.php, then click Download again.');
        $job = json_decode((string)@file_get_contents(self::jobFile()), true);
        if (is_array($job) && ($job['state'] ?? '') === 'working' && time() - (int)($job['ts'] ?? 0) < (!empty($job['build']) ? 3600 : 120)) throw new RuntimeException('A download is already being prepared.');
        if (!is_dir($dest) && !@mkdir($dest, 0777, true)) throw new RuntimeException("Cannot create the folder $dest. Check the drive letter.");
        $need = $build ? 11 * 1073741824 : 0;
        foreach (self::baseFiles() as $f) $need += (int)filesize($f);
        $free = @disk_free_space($dest);
        if ($free !== false && $free < $need + 200 * 1048576) throw new RuntimeException('Not enough free space on that drive: ' . round($need / 1073741824, 1) . ' GB needed, ' . round($free / 1073741824, 1) . ' GB free.');

        // The memory card (what this AI has learned, right now)
        [$token] = Cards::export(Cards::activeId());
        $job = ['state' => 'working', 'pct' => 0, 'msg' => 'Starting…', 'ts' => time(), 'name' => $name,
                'dest' => $dest, 'card' => Cards::exportPath($token), 'files' => self::baseFiles(), 'build' => $build, 'folder' => ''];
        file_put_contents(self::jobFile(), json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $php = PHP_OS_FAMILY === 'Windows' ? (is_file(PHP_BINDIR . '\\php.exe') ? PHP_BINDIR . '\\php.exe' : 'php') : (PHP_BINARY ?: 'php');
        $script = realpath(__DIR__ . '/../tools/make-package.php');
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" /B "' . $php . '" "' . $script . '" "' . self::jobFile() . '"', 'r'));
        } else {
            exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(self::jobFile()) . ' > /dev/null 2>&1 &');
        }
        return ['started' => true];
    }

    /** Runs in the background (tools/make-package.php): copies the installer, adds memory + name. */
    public static function run(string $jobFile): void
    {
        $job = json_decode((string)file_get_contents($jobFile), true);
        $save = function (array $set) use (&$job, $jobFile) { $job = $set + $job; $job['ts'] = time(); file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); };
        try {
            $folder = $job['dest'] . DIRECTORY_SEPARATOR . $job['name'] . ' - Setup';
            if (is_dir($folder)) $folder .= ' ' . date('Y-m-d His');
            if (!mkdir($folder . DIRECTORY_SEPARATOR . 'mnemo-package', 0777, true)) throw new RuntimeException("Cannot create $folder");
            $save(['folder' => $folder]);
            $save(['msg' => 'Adding the memory and the name…']);
            $pkg = $folder . DIRECTORY_SEPARATOR . 'mnemo-package';
            if (!copy($job['card'], $pkg . DIRECTORY_SEPARATOR . 'memory.atlascard')) throw new RuntimeException('Could not add the memory card.');
            @unlink($job['card']);
            file_put_contents($pkg . DIRECTORY_SEPARATOR . 'identity.json', json_encode(['ai_name' => $job['name'], 'model' => 'mnemo:7b', 'created' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if (!empty($job['build'])) {
                // First download on this PC: prepare the AI installer once (later downloads skip this)
                $save(['pct' => 1, 'msg' => 'First download: preparing the AI installer (one time only, about 10–30 minutes)…']);
                $bat = realpath(__DIR__ . '/../installer/Build ATLAS installer.bat');
                $log = atlas_config()['storage_root'] . '/logs/build.log';
                @mkdir(dirname($log), 0777, true);
                exec('cmd /c ""' . $bat . '" newborn < NUL > "' . $log . '" 2>&1"');
                $job['files'] = self::baseFiles();
                if (!$job['files']) throw new RuntimeException('Preparing the AI installer failed. Details: storage\\logs\\build.log');
            }
            $save(['msg' => 'Copying the AI…']);

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
                    if (time() !== $last) { $last = time(); $save(['pct' => (int)floor($done / max(1, $total) * 97), 'msg' => 'Copying the AI… ' . round($done / 1073741824, 1) . ' of ' . round($total / 1073741824, 1) . ' GB']); }
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
}
