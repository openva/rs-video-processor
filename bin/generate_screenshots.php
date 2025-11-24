#!/usr/bin/env php
<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use RichmondSunlight\VideoProcessor\Queue\JobType;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\S3Storage;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotGenerator;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJob;
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
$committeeDirectory = new CommitteeDirectory($pdo);
$keyBuilder = new S3KeyBuilder();
$generator = new ScreenshotGenerator($pdo, $storage, $committeeDirectory, $keyBuilder, $log);
$queue = new ScreenshotJobQueue($pdo);
$mapper = new ScreenshotJobPayloadMapper();

if ($mode === 'enqueue') {
    $jobs = $queue->fetch($limit);
    if (empty($jobs)) {
        $log->put('No videos pending screenshot generation.', 3);
        exit(0);
    }
    if ($dispatcher->usesInMemoryQueue()) {
        processScreenshotJobs($jobs, $generator, $log);
        exit(0);
    }
    foreach ($jobs as $job) {
        $dispatcher->dispatch($mapper->toPayload($job));
    }
    $log->put('Enqueued ' . count($jobs) . ' screenshot jobs.', 3);
    exit(0);
}

if ($dispatcher->usesInMemoryQueue()) {
    $jobs = $queue->fetch($limit);
    if (empty($jobs)) {
        $log->put('No videos pending screenshot generation.', 3);
        exit(0);
    }
    processScreenshotJobs($jobs, $generator, $log);
    exit(0);
}

$messages = $dispatcher->receive($limit, 10);
if (empty($messages)) {
    $log->put('No screenshot jobs in queue.', 3);
    exit(0);
}

foreach ($messages as $message) {
    try {
        if ($message->payload->type !== JobType::SCREENSHOTS) {
            $log->put('Skipping job of type ' . $message->payload->type, 4);
            continue;
        }
        $job = $mapper->fromPayload($message->payload);
        $generator->process($job);
    } catch (Throwable $e) {
        $log->put('Screenshot job failed for file #' . $message->payload->fileId . ': ' . $e->getMessage(), 6);
    } finally {
        $dispatcher->acknowledge($message);
    }
}

function processScreenshotJobs(array $jobs, ScreenshotGenerator $generator, Log $log): void
{
    foreach ($jobs as $job) {
        try {
            $generator->process($job);
        } catch (Throwable $e) {
            $log->put('Screenshot job failed for file #' . $job->id . ': ' . $e->getMessage(), 6);
        }
    }
}
