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

// 2. Install the database the first time
try {
    Atlas\Db::get()->val('SELECT 1 FROM documents LIMIT 1');
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), "doesn't exist in engine")) {
        // Broken database files (copied while not consistent): start this database fresh
        $say('Repairing the memory database...');
        try { Atlas\Db::server()->pdo->exec('DROP DATABASE IF EXISTS `' . $cfg['db']['name'] . '`'); } catch (Throwable) {}
        @unlink(is_string($pkg) && $pkg !== '' ? "$pkg/.done" : '');
    }
    $say('Setting up the memory for the first time...');
    $sql = (string)file_get_contents(__DIR__ . '/../sql/schema.sql');
    $sql = str_replace(['CREATE DATABASE IF NOT EXISTS atlas', 'USE atlas;'], ['CREATE DATABASE IF NOT EXISTS `' . $cfg['db']['name'] . '`', 'USE `' . $cfg['db']['name'] . '`;'], $sql);
    $pdo = Atlas\Db::server()->pdo;
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $sql)))) as $stmt) $pdo->exec($stmt);
    Atlas\Db::reset();
    Atlas\Schema::ensure(true);
}

// 3. Memory + name from "Download my AI" (only once)
if ($pkg === '' || !is_file("$pkg/identity.json") || is_file("$pkg/.done")) exit(0);
$id = json_decode((string)file_get_contents("$pkg/identity.json"), true) ?: [];
$name = trim((string)($id['ai_name'] ?? 'Mnemo')) ?: 'Mnemo';
try {
    if (is_file("$pkg/memory.atlascard")) {
        $say("Giving $name its memory (this can take a minute)...");
        Atlas\Cards::import("$pkg/memory.atlascard", $name);
        Atlas\Db::reset();
        atlas_config(true);
    }
    $db = Atlas\Db::get();
    $db->run('REPLACE INTO settings (k, v) VALUES (?, ?)', ['app.ai_name', $name]);
    $db->run('REPLACE INTO settings (k, v) VALUES (?, ?)', ['ollama.chat_model', 'mnemo:7b']);
    if (array_key_exists('vision', $id) && !$id['vision']) $db->run('REPLACE INTO settings (k, v) VALUES (?, ?)', ['ollama.vision_model', '']);
    file_put_contents("$pkg/.done", date('c'));
    $say("[ok] $name is ready.");
} catch (Throwable $e) {
    $say('[!] Could not set up the memory: ' . $e->getMessage());
    exit(1);
}

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
