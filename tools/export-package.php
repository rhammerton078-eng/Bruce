<?php
// Writes <dir>\mnemo-package\ (memory.atlascard + identity.json) from the active memory.
// Used by "Build ATLAS installer.bat" option 2, so the database is moved safely as a memory card.
// Usage: php tools/export-package.php <output dir> [AI name]
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli' || empty($argv[1])) exit("CLI only\n");
@set_time_limit(0);
$pkg = rtrim($argv[1], '\\/') . DIRECTORY_SEPARATOR . 'mnemo-package';
@mkdir($pkg, 0777, true);
[$token] = Atlas\Cards::export(Atlas\Cards::activeId());
rename(Atlas\Cards::exportPath($token), $pkg . DIRECTORY_SEPARATOR . 'memory.atlascard');
$name = trim($argv[2] ?? '') ?: Atlas\Mnemo::aiName();
file_put_contents($pkg . DIRECTORY_SEPARATOR . 'identity.json', json_encode(['ai_name' => $name, 'model' => 'mnemo:7b', 'created' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "  [ok] memory card for $name\n";
