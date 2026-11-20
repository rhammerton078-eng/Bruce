<?php
// Background job for "Download my AI" (started by the ATLAS page). Usage: php tools/make-package.php <job.json>
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli' || empty($argv[1])) exit("CLI only\n");
@set_time_limit(0);
Atlas\Package::run($argv[1]);
