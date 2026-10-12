#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\S3Storage;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotGenerator;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJob;
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

$s3Client = S3ClientFactory::create(AWS_ACCESS_KEY, AWS_SECRET_KEY, AWS_REGION);
$storage = new S3Storage($s3Client, 'video.richmondsunlight.com');
$keyBuilder = new S3KeyBuilder();

$pdoFactory = AppBootstrap::connectionFactory();
$runner = new StageRunner(new JobLedger($app->pdo, $pdoFactory), JobLedger::STAGE_SCREENSHOTS, $log);

$processed = $runner->run(
    $limit,
    static fn() => (new ScreenshotJobQueue($pdoFactory()))->fetch(1)[0] ?? null,
    static fn(ScreenshotJob $job): int => $job->id,
    // A failed job keeps its '/pending' claim on the files row; StaleClaimCleaner
    // releases it, and the ledger decides when the job may be tried again.
    static function (ScreenshotJob $job) use ($pdoFactory, $storage, $keyBuilder, $log): void {
        $pdo = $pdoFactory();
        $generator = new ScreenshotGenerator(
            $pdo,
            $storage,
            new CommitteeDirectory($pdo),
            $keyBuilder,
            $log,
            null,
            null,
            $pdoFactory
        );
        $generator->process($job);
    }
);

$log->put("Processed {$processed} screenshot job(s).", 3);
