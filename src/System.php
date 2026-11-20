<?php
declare(strict_types=1);

namespace Atlas;

use Throwable;

/** Health of the background parts (AI, memory, learner) so they can run hidden and still be watched from ATLAS. */
final class System
{
    public static function status(bool $withLogs = false): array
    {
        $cfg = atlas_config();
        $root = $cfg['storage_root'];
        $out = ['ai' => self::ai($cfg), 'memory' => self::memory(), 'learner' => self::learner($root)];
        if ($withLogs) {
            $out['logs'] = [
                'ai' => self::tail($root . '/logs/ollama.log'),
                'memory' => self::tail(self::mysqlLog()),
                'learner' => self::tail($root . '/logs/worker.log'),
            ];
        }
        return $out;
    }

    private static function ai(array $cfg): array
    {
        $url = rtrim($cfg['ollama']['url'], '/');
        $get = function (string $path) use ($url): ?array {
            $ch = curl_init($url . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2]);
            $res = curl_exec($ch);
            curl_close($ch);
            $j = is_string($res) ? json_decode($res, true) : null;
            return is_array($j) ? $j : null;
        };
        $ver = $get('/api/version');
        if (!$ver) return ['ok' => false, 'text' => 'Not running'];
        $loaded = [];
        foreach (($get('/api/ps')['models'] ?? []) as $m) {
            $size = (int)($m['size'] ?? 0); $vram = (int)($m['size_vram'] ?? 0);
            $gpu = $size > 0 ? (int)round($vram / $size * 100) : 0;
            $loaded[] = ['name' => $m['name'] ?? '?', 'size' => $size, 'gpu' => $gpu,
                         'where' => $gpu >= 100 ? '100% GPU' : ($gpu <= 0 ? '100% CPU' : (100 - $gpu) . '% CPU / ' . $gpu . '% GPU')];
        }
        $chat = $cfg['ollama']['chat_model'];
        $main = null;
        foreach ($loaded as $l) if ($l['name'] === $chat || str_starts_with($l['name'], $chat . ':')) $main = $l;
        return ['ok' => true, 'version' => $ver['version'] ?? '', 'model' => $chat, 'loaded' => $loaded,
                'text' => $main ? $main['where'] : (count($loaded) ? 'Ready' : 'Ready (sleeping)')];
    }

    private static function memory(): array
    {
        try {
            $db = Db::get();
            $up = (int)($db->one("SHOW GLOBAL STATUS LIKE 'Uptime'")['Value'] ?? 0);
            return ['ok' => true, 'version' => (string)$db->val('SELECT VERSION()'), 'uptime' => $up,
                    'text' => 'Running · up ' . self::dur($up)];
        } catch (Throwable $e) {
            return ['ok' => false, 'text' => 'Not reachable'];
        }
    }

    private static function learner(string $root): array
    {
        $j = json_decode((string)@file_get_contents($root . '/worker.json'), true);
        if (!is_array($j)) return ['ok' => false, 'text' => 'Not started', 'hint' => 'The ATLAS tab learns while it is open.'];
        $age = time() - (int)($j['ts'] ?? 0);
        $alive = ($j['status'] ?? '') !== 'stopped' && (self::pidAlive((int)($j['pid'] ?? 0)) ?? ($age < 180));
        $labels = ['working' => 'Learning', 'idle' => 'Waiting for new files', 'waiting' => 'Waiting', 'error' => 'Paused (problem)', 'starting' => 'Starting'];
        return ['ok' => $alive, 'pid' => (int)($j['pid'] ?? 0), 'last' => (string)($j['last'] ?? ''), 'seen' => $age,
                'text' => $alive ? ($labels[$j['status'] ?? ''] ?? 'Running') : 'Stopped', 'hint' => $alive ? '' : 'The ATLAS tab learns while it is open.'];
    }

    /** true/false when we can tell, null when we cannot (then the heartbeat age decides). */
    private static function pidAlive(int $pid): ?bool
    {
        if ($pid <= 0) return false;
        if (PHP_OS_FAMILY === 'Windows') {
            $o = @shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');
            return $o === null || $o === false ? null : str_contains($o, (string)$pid);
        }
        return function_exists('posix_kill') ? @posix_kill($pid, 0) : null;
    }

    private static function mysqlLog(): string
    {
        try {
            $db = Db::get();
            $log = (string)$db->val('SELECT @@log_error');
            if ($log === '' || $log === 'stderr') return '';
            if (!preg_match('~^([a-zA-Z]:|/|\\\\)~', $log)) $log = rtrim((string)$db->val('SELECT @@datadir'), '/\\') . '/' . ltrim($log, './\\');
            return $log;
        } catch (Throwable) { return ''; }
    }

    private static function tail(string $file, int $lines = 40): string
    {
        if ($file === '' || !is_file($file) || !is_readable($file)) return '';
        $size = filesize($file);
        $fh = fopen($file, 'rb');
        if (!$fh) return '';
        fseek($fh, max(0, $size - 16384));
        $txt = (string)stream_get_contents($fh);
        fclose($fh);
        $txt = preg_replace('/\x1b\[[0-9;]*m/', '', $txt) ?? $txt;          // colour codes from Ollama
        $all = preg_split('/\r?\n/', rtrim($txt));
        return implode("\n", array_slice($all, -$lines));
    }

    private static function dur(int $s): string
    {
        return $s < 3600 ? max(1, intdiv($s, 60)) . ' min' : ($s < 86400 ? round($s / 3600, 1) . ' h' : round($s / 86400, 1) . ' days');
    }
}
