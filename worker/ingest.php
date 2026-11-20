<?php
// Processes the upload queue until empty. Usage:  php worker/ingest.php [--watch]
// --watch keeps running and checks storage/inbox every 30 seconds.
// Only one worker runs at a time; it reports a heartbeat that ATLAS shows in the status bar.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit("CLI only\n");

$root = atlas_config()['storage_root'];
@mkdir($root . '/logs', 0777, true);
$lock = fopen($root . '/worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo date('H:i:s') . "  another learner is already running. Exiting.\n"; exit(0); }

$beatFile = $root . '/worker.json';
$state = ['pid' => getmypid(), 'started' => time(), 'ts' => time(), 'status' => 'starting', 'last' => ''];
$beat = function (string $status, string $last = '') use (&$state, $beatFile) {
    $state['ts'] = time(); $state['status'] = $status;
    if ($last !== '') $state['last'] = date('H:i:s') . '  ' . $last;
    @file_put_contents($beatFile, json_encode($state));
};
$logFile = $root . '/logs/worker.log';
if (is_file($logFile) && filesize($logFile) > 1048576) @rename($logFile, $logFile . '.old');   // keep the log small
$say = function (string $line) use ($beat, &$state, $logFile) {
    $l = date('Y-m-d H:i:s') . "  $line\n";
    echo $l; @file_put_contents($logFile, $l, FILE_APPEND);
    $beat($state['status'], $line);
};
register_shutdown_function(function () use ($beat) { $beat('stopped'); });

$watch = in_array('--watch', $argv, true);
$say('learner started' . ($watch ? ' (watching for new files)' : ''));
$ing = null;
do {
    $beat('working');
    try {
        if ($ing === null) { atlas_migrate(); $ing = new Atlas\Ingest(); }
        $r = $ing->step(30);
    } catch (Throwable $e) {
        // MySQL restarting, Ollama down...: wait and try again instead of crashing
        $say('waiting: ' . $e->getMessage());
        if (!$watch) exit(1);
        $ing = null; Atlas\Db::reset(); atlas_config(true); $beat('error'); sleep(30); $busy = true; continue;
    }
    if (!empty($r['busy'])) { $beat('waiting', ''); sleep(10); $busy = true; continue; } // the browser tab is learning right now
    foreach ($r['parsed'] as $t) $say("read     $t");
    foreach ($r['completed'] as $t) $say("learned  $t");
    foreach ($r['errors'] as $e) $say("error    $e");
    $p = $r['pending'];
    $busy = $p['queued'] || $p['chunks_left'] || $p['claims_left'];
    if ($busy) $say("pending  {$p['queued']} files, {$p['chunks_left']} passages, {$p['claims_left']} facts to check");
    $progress = $r['extracted_chunks'] || $r['parsed'] || $r['completed'] || !empty($r['checked_claims']) || !empty($r['embedded']);
    if ($busy && !$progress && !$r['errors']) { $beat('waiting'); sleep(10); }   // nothing could be done right now: don't spin
    if (!$busy && $watch) { $beat('idle'); for ($i = 0; $i < 6; $i++) { sleep(5); $beat('idle'); } }
    if ($busy && !$r['extracted_chunks'] && !$r['parsed'] && empty($r['checked_claims']) && $r['errors']) {
        $say('paused: errors with no progress (is Ollama running?)');
        if (!$watch) exit(1);
        $beat('error'); sleep(60);
    }
} while ($busy || $watch);
$say('queue empty');
