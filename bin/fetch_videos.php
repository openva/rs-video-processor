#!/usr/bin/env php
<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3Storage;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadProcessor;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadQueue;
use RichmondSunlight\VideoProcessor\Fetcher\VideoMetadataExtractor;

$app = require __DIR__ . '/bootstrap.php';

$log = $app->log;
$pdo = $app->pdo;

$limit = 5;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (is_numeric($arg)) {
        $limit = (int) $arg;
    }
}

$s3Client = new S3Client([
    'key' => AWS_ACCESS_KEY,
    'secret' => AWS_SECRET_KEY,
    'region' => AWS_REGION,
    'version' => '2006-03-01',
]);

$bucket = 'video.richmondsunlight.com';
$storage = new S3Storage($s3Client, $bucket);
$directory = new CommitteeDirectory($pdo);
$metadataExtractor = new VideoMetadataExtractor();
$keyBuilder = new S3KeyBuilder();
$processor = new VideoDownloadProcessor($pdo, $storage, $directory, $metadataExtractor, $keyBuilder, null, $log);
$queue = new VideoDownloadQueue($pdo);

$jobs = $queue->fetch($limit);
if (empty($jobs)) {
    $log->put('No videos require downloading.', 3);
    exit(0);
}

foreach ($jobs as $job) {
    try {
        $processor->process($job);
    } catch (Throwable $e) {
        $log->put('Failed to process video #' . $job->id . ': ' . $e->getMessage(), 6);
    }
}
