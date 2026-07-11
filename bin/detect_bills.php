#!/usr/bin/env php
<?php

declare(strict_types=1);

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
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;
$pdo = $app->pdo;

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
$pdoFactory = fn() => AppBootstrap::createFreshConnection();
$queue = new BillDetectionJobQueue($pdo);

$processor = new BillDetectionProcessor(
    new ScreenshotManifestLoader(),
    new ScreenshotFetcher(),
    new BillTextExtractor(new TesseractOcrEngine()),
    new BillParser(),
    new BillResultWriter($pdo, $pdoFactory),
    new ChamberConfig(),
    new AgendaExtractor(),
    $log
);

if (isset($options['enqueue'])) {
    $log->put('--enqueue is deprecated (SQS removed); processing jobs directly from the database.', 2);
}

$jobs = $queue->fetch($limit);
if (empty($jobs)) {
    $log->put('No files pending bill detection.', 3);
    exit(0);
}
processBillJobs($jobs, $processor, $log);

function processBillJobs(array $jobs, BillDetectionProcessor $processor, Log $log): void
{
    foreach ($jobs as $job) {
        try {
            $processor->process($job);
        } catch (Throwable $e) {
            $log->put('Bill detection failed for file #' . $job->fileId . ': ' . $e->getMessage(), 6);
        }
    }
}
