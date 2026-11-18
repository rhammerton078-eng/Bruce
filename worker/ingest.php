<?php
// Processes the upload queue until empty. Usage:  php worker/ingest.php [--watch]
// --watch keeps running and checks storage/inbox every 30 seconds.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");
atlas_migrate();

$watch = in_array('--watch', $argv, true);
$ing = new Atlas\Ingest();
do {
    $r = $ing->step(30);
    if (!empty($r['busy'])) { sleep(10); continue; } // the browser tab is learning right now
    foreach ($r['parsed'] as $t) echo date('H:i:s') . "  read     $t\n";
    foreach ($r['completed'] as $t) echo date('H:i:s') . "  learned  $t\n";
    foreach ($r['errors'] as $e) echo date('H:i:s') . "  error    $e\n";
    $p = $r['pending'];
    $busy = $p['queued'] || $p['chunks_left'];
    if ($busy) echo date('H:i:s') . "  pending  {$p['queued']} queued, {$p['chunks_left']} passages\n";
    if (!$busy && $watch) sleep(30);
    if ($busy && !$r['extracted_chunks'] && !$r['parsed'] && $r['errors']) {
        echo "Stopping: errors with no progress (is Ollama running?)\n";
        if (!$watch) exit(1);
        sleep(60);
    }
} while ($busy || $watch);
echo "Queue empty.\n";
