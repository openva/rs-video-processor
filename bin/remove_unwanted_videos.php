#!/usr/bin/env php
<?php

/**
 * Remove videos of meetings that do not advance legislation (task forces, work
 * groups, councils, retreats, ...; see VideoFilter) that nothing has been done
 * with yet. The scraper skips such meetings now; these were imported before it did.
 *
 * Usage:
 *   php bin/remove_unwanted_videos.php           # list what would be removed
 *   php bin/remove_unwanted_videos.php --apply   # remove it
 *
 * Videos that have already been downloaded, screenshotted, indexed or
 * transcribed, or that came from the old site, are listed and left alone.
 * Nothing is deleted from S3 or the Internet Archive.
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Maintenance\UnwantedVideoCleaner;

$app = require __DIR__ . '/bootstrap.php';

$apply = in_array('--apply', $argv, true);

$cleaner = new UnwantedVideoCleaner($app->pdo);
$plan = $cleaner->plan();

$print = static function (array $rows): void {
    foreach ($rows as $row) {
        printf("  #%d  %s  %s\n", $row['id'], $row['date'], $row['title']);
    }
};

printf("Not worked on yet, %s (%d):\n", $apply ? 'removing' : 'would be removed', count($plan['remove']));
$print($plan['remove']);

printf("\nAlready worked on, or from the old site, so left alone (%d):\n", count($plan['processed']));
$print($plan['processed']);

if (!$apply) {
    echo "\nNothing was changed. Add --apply to remove the first list.\n";
    exit(0);
}

$removed = $cleaner->remove(array_column($plan['remove'], 'id'));
printf("\nRemoved %d video(s).\n", $removed);
if ($removed < count($plan['remove'])) {
    printf("%d were worked on since the list was made and were kept.\n", count($plan['remove']) - $removed);
}
$app->log?->put(sprintf('Removed %d video(s) of meetings that do not advance legislation.', $removed), 3);
