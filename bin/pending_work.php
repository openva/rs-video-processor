#!/usr/bin/env php
<?php

/**
 * Prints how much work each pipeline stage could do right now.
 *
 * The per-stage breakdown goes to stderr. The total goes to stdout, alone on
 * the last line, which is what deploy/check-pending-work.sh's callers read.
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Jobs\PendingWork;

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';
require_once __DIR__ . '/../includes/class.Database.php';

$pdo = AppBootstrap::createFreshConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Unable to connect to the database.\n");
    exit(1);
}

$counts = (new PendingWork($pdo))->counts();
foreach ($counts as $stage => $count) {
    if ($count > 0) {
        fwrite(STDERR, "  - {$count} videos pending {$stage}\n");
    }
}

echo array_sum($counts) . "\n";
