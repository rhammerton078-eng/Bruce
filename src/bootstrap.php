<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Atlas\\')) {
        $file = __DIR__ . '/' . substr($class, 6) . '.php';
        if (is_file($file)) require $file;
    }
});

/** Runs pending schema migrations once per process (cheap check). */
function atlas_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try { Atlas\Schema::ensure(); } catch (Throwable) { /* not installed yet */ }
}

function atlas_config(bool $reset = false): array
{
    static $cfg = null;
    if ($reset) $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config.php';
        // Each memory card has its own files folder; the inbox stays shared
        $cfg['storage_root'] = $cfg['storage'];
        $cfg['storage'] = Atlas\Cards::activeDir();
        // Settings stored in the DB override the file (models etc.)
        try {
            foreach (Atlas\Db::get()->all('SELECT k, v FROM settings') as $row) {
                [$group, $key] = array_pad(explode('.', $row['k'], 2), 2, null);
                if ($key !== null && isset($cfg[$group]) && is_array($cfg[$group])) {
                    $cfg[$group][$key] = $row['v'];
                }
            }
        } catch (Throwable) {
            // DB not installed yet
        }
    }
    return $cfg;
}
