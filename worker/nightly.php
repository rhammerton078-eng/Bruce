<?php
// Mnemo's sleep cycle: infer links, discover topics, recompute confidence, write the daily digest.
// Schedule daily (Windows Task Scheduler -> worker\run-nightly.bat).
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");
atlas_migrate();

// Finish any pending learning first
$ing = new Atlas\Ingest();
do {
    $r = $ing->step(60);
} while (($r['pending']['queued'] || $r['pending']['chunks_left']) && ($r['extracted_chunks'] || $r['parsed']));

$res = (new Atlas\Nightly())->run();
foreach ($res['log'] as $l) echo "- $l\n";
echo 'Digest: ' . json_encode($res['digest'], JSON_UNESCAPED_UNICODE) . "\n";
