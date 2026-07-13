#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\S3Storage;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotGenerator;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJobQueue;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

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
if (isset($options['enqueue'])) {
    $log->put('--enqueue is deprecated (SQS removed); processing jobs directly from the database.', 2);
}

$s3Client = S3ClientFactory::create(AWS_ACCESS_KEY, AWS_SECRET_KEY, AWS_REGION);

$bucket = 'video.richmondsunlight.com';
$storage = new S3Storage($s3Client, $bucket);
$keyBuilder = new S3KeyBuilder();

$processed = 0;
for ($i = 0; $i < $limit; $i++) {
    // Fresh connection before each job — screenshot jobs take minutes
    // (download from S3 + ffmpeg + upload frames) and the connection times out.
    $pdo = AppBootstrap::createFreshConnection();
    $committeeDirectory = new CommitteeDirectory($pdo);
    $pdoFactory = fn() => AppBootstrap::createFreshConnection();
    $generator = new ScreenshotGenerator($pdo, $storage, $committeeDirectory, $keyBuilder, $log, null, null, $pdoFactory);
    $queue = new ScreenshotJobQueue($pdo);

    $jobs = $queue->fetch(1);
    if (empty($jobs)) {
        $log->put("No more screenshot jobs after processing {$processed}.", 3);
        break;
    }
    try {
        $generator->process($jobs[0]);
        $processed++;
    } catch (Throwable $e) {
        // The claim ('/pending') stays on this file; StaleClaimCleaner releases
        // it after the stale-claim threshold so the job is retried in a later session.
        $log->put('Screenshot job failed for file #' . $jobs[0]->id . ': ' . $e->getMessage(), 6);
    }
}
$log->put("Processed {$processed} screenshot job(s).", 3);
