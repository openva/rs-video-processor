#!/usr/bin/env php
<?php

/**
 * Lists files rows that share a video, share a screenshot directory, or were
 * imported twice. Read-only.
 *
 * Rows that share media were produced before every video had a unique S3 key.
 * At most one row in each group shows the right video. Send the rest (or all
 * of them, if unsure) through the pipeline again with bin/requeue_video.php.
 *
 * Usage:
 *   php bin/report_shared_media.php          # list the groups
 *   php bin/report_shared_media.php --plan   # print what to delete and what to requeue
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Maintenance\SharedMediaPlanner;
use RichmondSunlight\VideoProcessor\Sync\VideoSourceBackfill;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;

if (in_array('--plan', $argv, true)) {
    $plan = (new SharedMediaPlanner($pdo))->plan();

    echo sprintf("\n1. Delete %d row(s) that are later copies of a video already imported:\n\n", count($plan['delete']));
    if ($plan['delete']) {
        $ids = implode(', ', $plan['delete']);
        echo "DELETE FROM video_transcript WHERE file_id IN ({$ids});\n";
        echo "DELETE FROM files WHERE id IN ({$ids});\n";
    }

    echo sprintf("\n2. Then requeue %d row(s) that still share media with a different meeting:\n\n", count($plan['requeue']));
    foreach (array_chunk($plan['requeue'], 20) as $chunk) {
        echo 'php bin/requeue_video.php --apply --id=' . implode(' --id=', $chunk) . "\n";
    }

    echo sprintf("\n3. %d row(s) share media but have no source URL, so they cannot be downloaded again:\n\n", count($plan['unrecoverable']));
    echo $plan['unrecoverable'] ? '   ' . implode(', ', $plan['unrecoverable']) . "\n\n" : "\n";
    exit(0);
}

$checks = [
    'Rows sharing one screenshot directory' => [
        'column' => 'capture_directory',
        'where' => "capture_directory LIKE '/%/%'",
    ],
    'Rows sharing one video on S3' => [
        'column' => 'path',
        'where' => "path LIKE 'https://video.richmondsunlight.com/%'",
    ],
];

foreach ($checks as $heading => $check) {
    $column = $check['column'];
    $groups = $pdo->query(
        "SELECT {$column} AS shared, COUNT(*) AS n, COUNT(DISTINCT title) AS titles
         FROM files
         WHERE {$check['where']}
         GROUP BY {$column}
         HAVING COUNT(*) > 1
         ORDER BY n DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo sprintf("\n%s: %d group(s)\n", $heading, count($groups));

    $members = $pdo->prepare("SELECT id, date, title, length FROM files WHERE {$column} = :shared ORDER BY id");
    foreach ($groups as $group) {
        echo sprintf(
            "  %s  (%d rows, %s)\n",
            $group['shared'],
            (int) $group['n'],
            (int) $group['titles'] > 1 ? 'DIFFERENT meetings' : 'same title'
        );
        $members->execute([':shared' => $group['shared']]);
        foreach ($members->fetchAll(PDO::FETCH_ASSOC) as $row) {
            echo sprintf("      #%-6d %s  %-9s %s\n", (int) $row['id'], $row['date'], $row['length'] ?? '-', $row['title']);
        }
    }
}

// Safe to run repeatedly: it only adds registry rows that are missing.
$duplicates = (new VideoSourceBackfill($pdo))->run()['duplicates'];
echo sprintf("\nRows imported more than once: %d\n", count($duplicates));
foreach ($duplicates as $duplicate) {
    echo sprintf(
        "  #%d duplicates #%d (%s %s)\n",
        $duplicate['file_id'],
        $duplicate['duplicate_of'],
        $duplicate['source'],
        $duplicate['source_id']
    );
}
echo "\n";
