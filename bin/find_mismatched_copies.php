#!/usr/bin/env php
<?php

/**
 * List videos whose copy on archive.org or S3 (files.path) is not the length of
 * the original recording, and so is probably another meeting's video. Read-only.
 *
 * This is the cheap first check: it reads only each copy's header. A copy it
 * lists still needs a look, and the fix (downloading the right video again)
 * is a separate decision.
 *
 * Usage:
 *   php bin/find_mismatched_copies.php                    # every copy
 *   php bin/find_mismatched_copies.php --since=2026-01-01 # copies of recent videos only
 *   php bin/find_mismatched_copies.php --id=15141         # one video
 *   php bin/find_mismatched_copies.php --verbose          # list the ones that could not be checked too
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Maintenance\CopyDurationCheck;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;

$options = getopt('', ['since:', 'id:', 'verbose']);
$sql = "SELECT id, chamber, date, title, path, length, video_index_cache FROM files
        WHERE type = 'video'
          AND (path LIKE 'https://archive.org/%' OR path LIKE 'https://video.richmondsunlight.com/%')";
$params = [];
if (isset($options['id'])) {
    $sql .= ' AND id = :id';
    $params[':id'] = (int) $options['id'];
}
if (isset($options['since'])) {
    $sql .= ' AND date >= :since';
    $params[':since'] = (string) $options['since'];
}
$stmt = $pdo->prepare($sql . ' ORDER BY date DESC, id DESC');
$stmt->execute($params);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

printf("Checking %d cop%s.\n\n", count($files), count($files) === 1 ? 'y' : 'ies');

$check = new CopyDurationCheck();
$tally = ['match' => 0, 'mismatch' => 0, 'unknown' => 0];
$mismatched = [];
foreach ($files as $file) {
    $result = $check->check($file);
    $tally[$result['status']]++;
    if ($result['status'] === 'mismatch' || ($result['status'] === 'unknown' && isset($options['verbose']))) {
        printf("#%d %s %s %s: %s\n   %s\n", $file['id'], $file['chamber'], $file['date'], $file['title'], $result['reason'], $file['path']);
    }
    if ($result['status'] === 'mismatch') {
        $mismatched[] = (int) $file['id'];
    }
}

printf("\nSame length: %d. Different length: %d. Could not check: %d.\n", $tally['match'], $tally['mismatch'], $tally['unknown']);
if ($mismatched) {
    echo "\nIDs of copies that are a different length:\n" . implode(', ', $mismatched) . "\n";
}
