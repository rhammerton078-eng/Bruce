<?php
// Copies only the chosen AI models into the installer's stage folder, and only files that changed.
// Usage: php tools/stage-models.php <source models dir> <stage models dir> lite|full
//   lite = without the picture model (qwen2.5vl), full = everything
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) < 4) exit("usage: stage-models.php <src> <dst> lite|full\n");
@set_time_limit(0);
[$_, $src, $dst, $mode] = $argv;
$src = rtrim(str_replace('\\', '/', $src), '/');
$dst = rtrim(str_replace('\\', '/', $dst), '/');
$skip = fn(string $rel) => $mode === 'lite' && preg_match('~/library/qwen2\.5vl/~i', $rel);

// 1. Manifests to keep, and the blobs they use
$keep = [];          // relative paths of files to copy
$digests = [];
$all = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
$unparsed = false;
foreach ($all as $f) {
    $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($src) + 1);
    if (!preg_match('~^manifests~', $rel)) continue;
    if ($skip($rel)) continue;
    $keep[$rel] = true;
    $j = json_decode((string)file_get_contents($f->getPathname()), true);
    if (!is_array($j)) { $unparsed = true; continue; }
    foreach (array_merge($j['layers'] ?? [], [$j['config'] ?? []]) as $l) {
        if (!empty($l['digest'])) $digests[str_replace(':', '-', $l['digest'])] = true;
    }
}
foreach ($all as $f) {
    $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($src) + 1);
    if (preg_match('~^manifests~', $rel)) continue;
    $name = basename($rel);
    $hash = preg_replace('/\.json$/', '', $name);
    // Blobs and metadata belong to a kept model; anything else (unknown layout) is copied to be safe
    if ($unparsed || $mode !== 'lite' || isset($digests[$hash]) || !preg_match('~^(blobs|metadata)/~', $rel)) $keep[$rel] = true;
}

// 2. Copy what is missing or changed
$copied = 0; $bytes = 0;
foreach (array_keys($keep) as $rel) {
    $a = "$src/$rel"; $b = "$dst/$rel";
    if (is_file($b) && filesize($a) === filesize($b) && filemtime($b) >= filemtime($a)) continue;
    @mkdir(dirname($b), 0777, true);
    if (!copy($a, $b)) { fwrite(STDERR, "  [!] could not copy $rel\n"); exit(1); }
    touch($b, filemtime($a));
    $copied++; $bytes += filesize($a);
}
// 3. Remove models that are no longer wanted
$removed = 0;
if (is_dir($dst)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dst, FilesystemIterator::SKIP_DOTS)) as $f) {
        $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($dst) + 1);
        if (!isset($keep[$rel])) { @unlink($f->getPathname()); $removed++; }
    }
}
printf("  [ok] AI models: %d copied (%.1f GB), %d unchanged, %d removed%s\n", $copied, $bytes / 1073741824, count($keep) - $copied, $removed, $mode === 'lite' ? ' (without picture model)' : '');
