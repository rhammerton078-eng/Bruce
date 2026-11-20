<?php
// Build helper: writes the installed copy's config.local.php (own MySQL port 3307).
// Usage: php make-config.php <source config.local.php or ""> <target config.local.php> <keep|newborn>
[$_, $src, $dst, $mode] = $argv + [null, '', '', 'newborn'];
$c = ($mode === 'keep' && $src !== '' && is_file($src)) ? (include $src) : [];
if (!is_array($c)) $c = [];
$c['db']['port'] = 3307;
$c['db']['host'] = '127.0.0.1';
if ($mode !== 'keep') { $c['db']['user'] = 'root'; $c['db']['pass'] = ''; }
file_put_contents($dst, "<?php\n// Installed ATLAS: its own MySQL on port 3307.\nreturn " . var_export($c, true) . ";\n");
echo "  [ok] config.local.php written\n";
