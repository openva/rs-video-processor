#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Analysis\Bills\AgendaExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJob;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillParser;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillResultWriter;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillTextExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ChamberConfig;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;
use RichmondSunlight\VideoProcessor\Analysis\Bills\TesseractOcrEngine;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

$options = getopt('', ['limit::', 'enqueue']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 2;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--')) {
        continue;
    }
    if (is_numeric($arg)) {
        $limit = (int) $arg;
        break;
    }
}

$pdoFactory = AppBootstrap::connectionFactory();

// Read every Nth screenshot. 2 halves the OCR work and still places each bill
// to within two seconds. 1 reads every frame.
$frameStep = getenv('BILL_OCR_FRAME_STEP');
$frameStep = is_numeric($frameStep) ? max(1, (int) $frameStep) : 2;

// Frames are read straight from the bucket, not through Cloudflare.
$s3Client = S3ClientFactory::create(AWS_ACCESS_KEY, AWS_SECRET_KEY, AWS_REGION);

$processor = new BillDetectionProcessor(
    new ScreenshotManifestLoader(),
    new ScreenshotFetcher(null, null, [2, 5], $s3Client),
    new BillTextExtractor(new TesseractOcrEngine()),
    new BillParser(),
    new BillResultWriter($app->pdo, $pdoFactory),
    new ChamberConfig(),
    new AgendaExtractor(),
    $log,
    $frameStep
);

$runner = new StageRunner(new JobLedger($app->pdo, $pdoFactory), JobLedger::STAGE_BILLS, $log);

$processed = $runner->run(
    $limit,
    static fn() => (new BillDetectionJobQueue($pdoFactory()))->fetch(1)[0] ?? null,
    static fn(BillDetectionJob $job): int => $job->fileId,
    // process() returns false when it could not finish; StageRunner counts that as a failure.
    static fn(BillDetectionJob $job): bool => $processor->process($job)
);

$log->put("Finished bill detection for {$processed} file(s).", 3);
