#!/usr/bin/env php
<?php

/**
 * Generates a manifest of Senate YouTube videos that are missing from S3
 * and uploads it to s3://video.richmondsunlight.com/uploads/manifest.json.
 *
 * The manifest is consumed by scripts/fetch_youtube_uploads.sh, which runs
 * locally to download the videos and upload them back to the uploads/ prefix.
 */

declare(strict_types=1);

use Aws\S3\S3Client;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;
$log = $app->log;

$stmt = $pdo->query("
    SELECT id, title, date, video_index_cache
    FROM files
    WHERE chamber = 'senate'
      AND (path IS NULL OR path = '' OR path NOT LIKE 'https://video.richmondsunlight.com/%')
      AND (html IS NULL OR html = '')
      AND video_index_cache IS NOT NULL
      AND video_index_cache LIKE '%senate-youtube%'
    ORDER BY date DESC
");

$videos = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $cache = json_decode($row['video_index_cache'], true);
    if (!is_array($cache)) {
        continue;
    }
    $youtubeId = $cache['youtube_id'] ?? null;
    $youtubeUrl = $cache['youtube_url'] ?? $cache['video_url'] ?? null;
    if (!$youtubeId || !$youtubeUrl) {
        continue;
    }
    $videos[] = [
        'file_id'     => (int) $row['id'],
        'youtube_id'  => $youtubeId,
        'youtube_url' => $youtubeUrl,
        'title'       => $row['title'],
        'date'        => $row['date'],
        'event_type'  => $cache['event_type'] ?? 'committee',
    ];
}

// Sort: floor first, then committee, then subcommittee; within each group by date DESC
$typePriority = ['floor' => 0, 'committee' => 1, 'subcommittee' => 2];
usort($videos, function (array $a, array $b) use ($typePriority): int {
    $pa = $typePriority[$a['event_type']] ?? 3;
    $pb = $typePriority[$b['event_type']] ?? 3;
    if ($pa !== $pb) {
        return $pa - $pb;
    }
    return strcmp($b['date'], $a['date']);
});

if (empty($videos)) {
    $log->put('Upload manifest: no missing Senate YouTube videos.', 3);
    exit(0);
}

$manifest = [
    'generated_at' => date(DATE_ATOM),
    'count'        => count($videos),
    'videos'       => $videos,
];

$s3Client = new S3Client([
    'key'     => AWS_ACCESS_KEY,
    'secret'  => AWS_SECRET_KEY,
    'region'  => AWS_REGION,
    'version' => '2006-03-01',
]);

$s3Client->putObject([
    'Bucket'      => 'video.richmondsunlight.com',
    'Key'         => 'uploads/manifest.json',
    'Body'        => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    'ContentType' => 'application/json',
    'ACL'         => 'public-read',
]);

$log->put(sprintf('Upload manifest written: %d video(s) pending.', count($videos)), 3);
