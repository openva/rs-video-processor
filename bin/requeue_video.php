#!/usr/bin/env php
<?php

/**
 * Send one or more videos through the whole pipeline again.
 *
 * Usage:
 *   php bin/requeue_video.php --id=18797 --id=18798           # show what would happen
 *   php bin/requeue_video.php --id=18797 --id=18798 --apply   # do it
 *
 * Requeueing discards the video's screenshots record, transcript, bill and
 * speaker results, and job history. Nothing is deleted from S3 or the
 * Internet Archive.
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Maintenance\VideoRequeuer;

$app = require __DIR__ . '/bootstrap.php';

$options = getopt('', ['id:', 'apply']);
$ids = array_map('intval', (array) ($options['id'] ?? []));
$apply = isset($options['apply']);

if (empty($ids)) {
    fwrite(STDERR, "Usage: php bin/requeue_video.php --id=N [--id=N ...] [--apply]\n");
    exit(1);
}

$requeuer = new VideoRequeuer($app->pdo);
$failures = 0;

foreach ($ids as $id) {
    try {
        if ($apply) {
            $url = $requeuer->requeue($id);
            echo "File #{$id}: requeued; will be downloaded again from {$url}\n";
        } else {
            $url = $requeuer->sourceUrl($id);
            echo "File #{$id}: would be requeued and downloaded again from {$url}\n";
        }
    } catch (Throwable $e) {
        $failures++;
        echo "File #{$id}: NOT requeued. " . $e->getMessage() . "\n";
    }
}

if (!$apply) {
    echo "\nNothing was changed. Add --apply to requeue.\n";
}

exit($failures > 0 ? 1 : 0);
