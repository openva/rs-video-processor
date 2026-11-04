#!/usr/bin/env php
<?php

/**
 * Send Senate floor videos back through bill detection. Their chyron reads
 * "S.B. 956", which BillParser did not accept until November 2026, so bill
 * detection found no bills in them. The pipeline's bill stage
 * (bin/detect_bills.php) then reads them again, from their screenshots.
 *
 * Usage:
 *   php bin/redo_senate_floor_bills.php                     # count them
 *   php bin/redo_senate_floor_bills.php --apply --limit=50  # send the newest 50 back
 *   php bin/redo_senate_floor_bills.php --apply --all       # send them all back
 *
 * Only bill results are removed; speakers, transcripts and screenshots are kept.
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Maintenance\BillDetectionReset;

$app = require __DIR__ . '/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$limit = in_array('--all', $argv, true) ? null : 50;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$reset = new BillDetectionReset($app->pdo);
$ids = $reset->senateFloorVideos();
printf("%d Senate floor video(s) from 2020 on have been through bill detection.\n", count($ids));
if (!$apply) {
    echo "Nothing was changed. Add --apply (with --limit=N or --all) to send them back through bill detection.\n";
    exit(0);
}

$ids = $limit === null ? $ids : array_slice($ids, 0, $limit);
foreach ($ids as $id) {
    $reset->reset($id);
}
printf("Sent %d back through bill detection, newest first.\n", count($ids));
$app->log?->put(sprintf('Sent %d Senate floor video(s) back through bill detection.', count($ids)), 3);
