#!/usr/bin/env php
<?php

/**
 * Move the S3 copies of videos that are no longer played from S3 to Glacier
 * Instant Retrieval. These are videos on the Internet Archive (whose files.path
 * points there) and copies no row points at any more. The archive stage does
 * this for each video it uploads; this catches the ones uploaded before it did.
 *
 * A video any files row plays from S3 is left alone, and so is one uploaded in
 * the last two days, which may belong to a download still being recorded.
 *
 * Usage:
 *   php bin/move_archived_to_cold_storage.php              # list what would move, and the saving
 *   php bin/move_archived_to_cold_storage.php --apply      # move it
 *   php bin/move_archived_to_cold_storage.php --apply --limit=50
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Fetcher\BucketReader;
use RichmondSunlight\VideoProcessor\Fetcher\ColdStorage;
use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;
$log = $app->log;

$options = getopt('', ['apply', 'limit:']);
$apply = isset($options['apply']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 0;

/** Standard and Glacier Instant Retrieval, per GB-month, us-east-1. */
const STANDARD_PER_GB = 0.023;
const COLD_PER_GB = 0.004;

$cold = new ColdStorage(S3ClientFactory::create(AWS_ACCESS_KEY, AWS_SECRET_KEY, AWS_REGION));

echo "Listing the videos in the bucket...\n";
$videos = $cold->videos();

$played = [];
foreach ($pdo->query("SELECT path FROM files WHERE path LIKE 'https://video.richmondsunlight.com/%'")->fetchAll(PDO::FETCH_COLUMN) as $path) {
    $key = BucketReader::keyOf((string) $path);
    if ($key !== null) {
        $played[] = $key;
    }
}

$toMove = ColdStorage::toMove($videos, $played, new DateTimeImmutable('now'));
if ($limit > 0) {
    $toMove = array_slice($toMove, 0, $limit);
}
$gb = array_sum(array_column($toMove, 'size')) / 1e9;

printf(
    "%d videos in the bucket; %d played from S3. %d to move (%.0f GB), saving about $%.2f a month.\n",
    count($videos),
    count($played),
    count($toMove),
    $gb,
    $gb * (STANDARD_PER_GB - COLD_PER_GB)
);

if (!$apply) {
    foreach (array_slice($toMove, 0, 20) as $video) {
        printf("  %s (%.1f GB, %s)\n", $video['key'], $video['size'] / 1e9, $video['storageClass']);
    }
    if (count($toMove) > 20) {
        printf("  ... and %d more\n", count($toMove) - 20);
    }
    echo "\nNothing was changed. Add --apply to move them.\n";
    exit(0);
}

// Each copy runs inside S3 and takes about half a minute for a video, so the
// whole run takes hours; say what is happening as it goes.
$moved = 0;
$failed = 0;
foreach ($toMove as $i => $video) {
    try {
        $result = $cold->move($video['key']);
        if ($result === 'moved') {
            $moved++;
        }
        printf("  [%d/%d] %s (%.1f GB): %s\n", $i + 1, count($toMove), $video['key'], $video['size'] / 1e9, $result);
    } catch (Throwable $e) {
        $failed++;
        printf("  [%d/%d] FAILED %s: %s\n", $i + 1, count($toMove), $video['key'], $e->getMessage());
    }
}

printf("Moved %d video(s) to Glacier Instant Retrieval; %d failed.\n", $moved, $failed);
$log->put(sprintf('Moved %d archived video(s) on S3 to Glacier Instant Retrieval; %d failed.', $moved, $failed), 3);
