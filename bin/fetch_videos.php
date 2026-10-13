#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\S3Storage;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadJob;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadProcessor;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadQueue;
use RichmondSunlight\VideoProcessor\Fetcher\VideoMetadataExtractor;
use RichmondSunlight\VideoProcessor\Fetcher\VideoValidator;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

$limit = 20;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (is_numeric($arg)) {
        $limit = (int) $arg;
    }
}

$s3Client = S3ClientFactory::create(AWS_ACCESS_KEY, AWS_SECRET_KEY, AWS_REGION);
$storage = new S3Storage($s3Client, 'video.richmondsunlight.com');

$pdoFactory = AppBootstrap::connectionFactory();
$runner = new StageRunner(new JobLedger($app->pdo, $pdoFactory), JobLedger::STAGE_DOWNLOAD, $log);

$downloaded = $runner->run(
    $limit,
    static fn() => (new VideoDownloadQueue($pdoFactory()))->fetch(1)[0] ?? null,
    static fn(VideoDownloadJob $job): int => $job->id,
    static function (VideoDownloadJob $job) use ($pdoFactory, $storage, $log): void {
        $pdo = $pdoFactory();
        $processor = new VideoDownloadProcessor(
            $pdo,
            $storage,
            new CommitteeDirectory($pdo),
            new VideoMetadataExtractor(),
            new S3KeyBuilder(),
            null,
            $log,
            null,
            new VideoValidator(),
            $pdoFactory
        );
        $processor->process($job);
    }
);

$log->put("Downloaded {$downloaded} video(s).", 3);
