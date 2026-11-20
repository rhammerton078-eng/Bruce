<?php
// First start of an installed ATLAS (run by "Start ATLAS.bat"):
//  - installs the database if needed
//  - inserts the memory that came with "Download my AI" and sets the AI's name
//  - renames the desktop icon to the AI's name
// Usage: php tools/first-run.php <package folder>     (does nothing once finished)
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");
@set_time_limit(0);

$pkg = rtrim($argv[1] ?? '', '\\/');
$say = fn(string $m) => print('  ' . $m . "\n");

// 1. Wait for MySQL (it was just started)
$cfg = atlas_config();
for ($i = 0; $i < 40; $i++) {
    try { Atlas\Db::server()->pdo->query('SELECT 1'); break; } catch (Throwable) { sleep(1); }
}

// 2 + 3. Database (repairs leftover files), then the memory and the name that came with it
$hadPackage = $pkg !== '' && is_file("$pkg/identity.json") && !is_file("$pkg/.done");
try {
    Atlas\Installer::run();
    $name = Atlas\Installer::importPackage($pkg);     // no-op when run() already did it
} catch (Throwable $e) {
    $say('[!] Could not set up the memory: ' . $e->getMessage());
    exit(1);
}
if (!$hadPackage) exit(0);
$id = json_decode((string)@file_get_contents("$pkg/identity.json"), true) ?: [];
$name = trim((string)($id['ai_name'] ?? 'Mnemo')) ?: 'Mnemo';
$say("[ok] $name is ready.");

// 4. Desktop icon with the AI's name (Windows)
if (PHP_OS_FAMILY === 'Windows') {
    $root = realpath(__DIR__ . '/../../../..');          // C:\ATLAS  (xampp\htdocs\atlas\tools -> up 4)
    $safe = preg_replace('/[\\\\\/:*?"<>|]/', '', $name);
    $target = is_file($root . '\\ATLAS.exe') ? $root . '\\ATLAS.exe' : $root . '\\Start ATLAS.bat';
    $ps = '$d=[Environment]::GetFolderPath(\'CommonDesktopDirectory\');'
        . '$s=(New-Object -ComObject WScript.Shell).CreateShortcut((Join-Path $d \'' . str_replace("'", "''", $safe) . '.lnk\'));'
        . '$s.TargetPath=\'' . $target . '\';$s.WorkingDirectory=\'' . $root . '\';$s.IconLocation=\'' . $root . '\\atlas.ico\';$s.Save();'
        . 'Remove-Item (Join-Path $d \'ATLAS - Mnemo AI.lnk\') -ErrorAction SilentlyContinue';
    @shell_exec('powershell -NoProfile -Command "' . str_replace('"', '\"', $ps) . '"');
}
