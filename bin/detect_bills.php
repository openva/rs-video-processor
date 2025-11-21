#!/usr/bin/env php
<?php

declare(strict_types=1);

use Log;
use RichmondSunlight\VideoProcessor\Analysis\Bills\AgendaExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillParser;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillResultWriter;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillTextExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ChamberConfig;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;
use RichmondSunlight\VideoProcessor\Analysis\Bills\TesseractOcrEngine;

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';
require_once __DIR__ . '/../includes/class.Database.php';

$log = new Log();
$database = new Database();
$pdo = $database->connect();
if (!$pdo) {
    throw new RuntimeException('Unable to connect to database.');
}

$limit = isset($argv[1]) ? (int) $argv[1] : 2;

$s3 = new S3Client([
    'key' => AWS_ACCESS_KEY,
    'secret' => AWS_SECRET_KEY,
    'region' => 'us-east-1',
    'version' => '2006-03-01',
]);
$storage = new S3Storage($s3, 'video.richmondsunlight.com');

$queue = new BillDetectionJobQueue($pdo);
$jobs = $queue->fetch($limit);
if (empty($jobs)) {
    $log->put('No files pending bill detection.', 3);
    exit(0);
}

$processor = new BillDetectionProcessor(
    new ScreenshotManifestLoader(),
    new ScreenshotFetcher(),
    new BillTextExtractor(new TesseractOcrEngine()),
    new BillParser(),
    new BillResultWriter($pdo),
    new ChamberConfig(),
    new AgendaExtractor(),
    $log
);

foreach ($jobs as $job) {
    try {
        $processor->process($job);
    } catch (Throwable $e) {
        $log->put('Bill detection failed for file #' . $job->fileId . ': ' . $e->getMessage(), 6);
    }
}
