#!/usr/bin/env php
<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\S3Storage;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotGenerator;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJobPayloadMapper;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJobQueue;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;
$pdo = $app->pdo;
$dispatcher = $app->dispatcher;

$options = getopt('', ['limit::', 'enqueue']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 3;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--')) {
        continue;
    }
    if (is_numeric($arg)) {
        $limit = (int) $arg;
        break;
    }
}
$mode = isset($options['enqueue']) ? 'enqueue' : 'worker';

$s3Client = new S3Client([
    'key' => AWS_ACCESS_KEY,
    'secret' => AWS_SECRET_KEY,
    'region' => 'us-east-1',
    'version' => '2006-03-01',
]);

$bucket = 'video.richmondsunlight.com';
$storage = new S3Storage($s3Client, $bucket);
$keyBuilder = new S3KeyBuilder();
$mapper = new ScreenshotJobPayloadMapper();

if ($mode === 'enqueue') {
    $committeeDirectory = new CommitteeDirectory($pdo);
    $generator = new ScreenshotGenerator($pdo, $storage, $committeeDirectory, $keyBuilder, $log);
    $queue = new ScreenshotJobQueue($pdo);
    $jobs = $queue->fetch($limit);
    if (empty($jobs)) {
        $log->put('No videos pending screenshot generation.', 3);
        exit(0);
    }
    if ($dispatcher->usesInMemoryQueue()) {
        foreach ($jobs as $job) {
            try {
                $generator->process($job);
            } catch (Throwable $e) {
                $log->put('Screenshot job failed for file #' . $job->id . ': ' . $e->getMessage(), 6);
            }
        }
        exit(0);
    }
    foreach ($jobs as $job) {
        $dispatcher->dispatch($mapper->toPayload($job));
    }
    $log->put('Enqueued ' . count($jobs) . ' screenshot jobs.', 3);
    exit(0);
}

$processed = 0;
while ($processed < $limit) {
    // Fresh connection before each job — screenshot jobs take minutes
    // (download from S3 + ffmpeg + upload frames) and the connection times out.
    $pdo = AppBootstrap::createFreshConnection();
    $committeeDirectory = new CommitteeDirectory($pdo);
    $generator = new ScreenshotGenerator($pdo, $storage, $committeeDirectory, $keyBuilder, $log);
    $queue = new ScreenshotJobQueue($pdo);

    $jobs = $queue->fetch(1);
    if (empty($jobs)) {
        $log->put("No more jobs after processing {$processed}.", 3);
        break;
    }
    try {
        $generator->process($jobs[0]);
        $processed++;
    } catch (Throwable $e) {
        $log->put('Screenshot job failed for file #' . $jobs[0]->id . ': ' . $e->getMessage(), 6);
    }
}
