<?php
declare(strict_types=1);

namespace Atlas;

use RuntimeException;

/**
 * Start / stop buttons for the hidden background parts (AI, Memory, Learner).
 * Works for the XAMPP copy (C:\xampp + X:\ATLAS-AI) and for an installed copy (C:\ATLAS\...).
 */
final class Services
{
    /** C:\xampp, or C:\ATLAS\xampp for an installed copy */
    private static function xampp(): string
    {
        $bin = PHP_OS_FAMILY === 'Windows' && is_file(PHP_BINDIR . '\\php.exe') ? PHP_BINDIR : 'C:\\xampp\\php';
        return dirname($bin);
    }

    private static function php(): string { return self::xampp() . '\\php\\php.exe'; }
    private static function app(): string { return (string)realpath(__DIR__ . '/..'); }

    /** [ollama.exe, models folder] */
    private static function ai(): array
    {
        $installed = dirname(self::xampp());                         // C:\ATLAS when installed
        if (is_file("$installed\\ollama\\ollama.exe") && is_dir("$installed\\models")) return ["$installed\\ollama\\ollama.exe", "$installed\\models"];
        foreach (range('D', 'Z') as $d) if (is_file("$d:\\ATLAS-AI\\ollama\\ollama.exe")) return ["$d:\\ATLAS-AI\\ollama\\ollama.exe", "$d:\\ATLAS-AI\\models"];
        if (is_file('C:\\ATLAS-AI\\ollama\\ollama.exe')) return ['C:\\ATLAS-AI\\ollama\\ollama.exe', 'C:\\ATLAS-AI\\models'];
        $local = (getenv('LOCALAPPDATA') ?: '') . '\\Programs\\Ollama\\ollama.exe';
        if (is_file($local)) return [$local, ''];
        throw new RuntimeException('The AI program (ollama.exe) was not found.');
    }

    private static function ps(string $script): void
    {
        if (PHP_OS_FAMILY !== 'Windows') throw new RuntimeException('Start / stop buttons work on Windows.');
        @exec('powershell -NoProfile -ExecutionPolicy Bypass -Command "' . str_replace('"', '\"', $script) . '" 2>&1');
    }

    private static function q(string $s): string { return "'" . str_replace("'", "''", $s) . "'"; }

    public static function control(string $part, string $do): array
    {
        if (!in_array($do, ['start', 'stop', 'restart'], true)) throw new RuntimeException('Unknown action');
        if ($do === 'restart') { self::control($part, 'stop'); sleep(2); return self::control($part, 'start'); }
        $logs = atlas_config()['storage_root'] . '/logs';
        @mkdir($logs, 0777, true);
        $logs = str_replace('/', '\\', $logs);

        switch ($part) {
            case 'ai':
                if ($do === 'stop') { self::ps("Get-Process ollama,'ollama app' -ErrorAction SilentlyContinue | Stop-Process -Force"); break; }
                [$exe, $models] = self::ai();
                $env = '$env:OLLAMA_KEEP_ALIVE=\'30m\'; $env:OLLAMA_FLASH_ATTENTION=\'1\'; $env:OLLAMA_KV_CACHE_TYPE=\'q8_0\'; ' . ($models ? '$env:OLLAMA_MODELS=' . self::q($models) . '; ' : '');
                self::ps($env . 'if (-not (Get-NetTCPConnection -LocalPort 11434 -State Listen -ErrorAction SilentlyContinue)) { Start-Process -FilePath ' . self::q($exe)
                    . " -ArgumentList 'serve' -WindowStyle Hidden -RedirectStandardOutput " . self::q("$logs\\ollama-out.log") . ' -RedirectStandardError ' . self::q("$logs\\ollama.log") . ' }');
                break;

            case 'memory':
                $x = self::xampp();
                $port = (int)(atlas_config()['db']['port'] ?? 3306);
                if ($do === 'stop') { @exec('"' . $x . '\\mysql\\bin\\mysqladmin.exe" -u root --host=127.0.0.1 --port=' . $port . ' shutdown 2>&1'); break; }
                self::ps('if (-not (Get-NetTCPConnection -LocalPort ' . $port . ' -State Listen -ErrorAction SilentlyContinue)) { Start-Process -FilePath ' . self::q("$x\\mysql\\bin\\mysqld.exe")
                    . ' -ArgumentList ' . self::q("--defaults-file=$x\\mysql\\bin\\my.ini") . ",'--standalone' -WorkingDirectory " . self::q($x) . ' -WindowStyle Hidden }');
                break;

            case 'learner':
                $j = json_decode((string)@file_get_contents(atlas_config()['storage_root'] . '/worker.json'), true);
                $pid = (int)($j['pid'] ?? 0);
                if ($do === 'stop') {
                    if ($pid) self::ps("\$p = Get-Process -Id $pid -ErrorAction SilentlyContinue; if (\$p -and \$p.ProcessName -eq 'php') { Stop-Process -Id $pid -Force }");
                    break;
                }
                // The learner itself makes sure only one runs at a time
                self::ps('Start-Process -FilePath ' . self::q(self::php()) . " -ArgumentList 'worker\\ingest.php','--watch' -WorkingDirectory " . self::q(self::app())
                    . ' -WindowStyle Hidden -RedirectStandardOutput ' . self::q("$logs\\worker-out.log") . ' -RedirectStandardError ' . self::q("$logs\\worker-err.log"));
                break;

            default:
                throw new RuntimeException('Unknown part');
        }
        sleep($do === 'start' ? 3 : 1);
        return ['ok' => true];
    }
}
