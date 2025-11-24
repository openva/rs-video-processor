#!/usr/bin/env php
<?php

declare(strict_types=1);

use Log;
use RichmondSunlight\VideoProcessor\Analysis\Bills\AgendaExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJob;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJobPayloadMapper;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillParser;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillResultWriter;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillTextExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ChamberConfig;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;
use RichmondSunlight\VideoProcessor\Analysis\Bills\TesseractOcrEngine;
use RichmondSunlight\VideoProcessor\Queue\JobType;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;
$pdo = $app->pdo;
$dispatcher = $app->dispatcher;

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
$mode = isset($options['enqueue']) ? 'enqueue' : 'worker';

$queue = new BillDetectionJobQueue($pdo);
$mapper = new BillDetectionJobPayloadMapper();

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

if ($mode === 'enqueue') {
    $jobs = $queue->fetch($limit);
    if (empty($jobs)) {
        $log->put('No files pending bill detection.', 3);
        exit(0);
    }
    if ($dispatcher->usesInMemoryQueue()) {
        processBillJobs($jobs, $processor, $log);
        exit(0);
    }
    foreach ($jobs as $job) {
        $dispatcher->dispatch($mapper->toPayload($job));
    }
    $log->put('Enqueued ' . count($jobs) . ' bill-detection jobs.', 3);
    exit(0);
}

if ($dispatcher->usesInMemoryQueue()) {
    $jobs = $queue->fetch($limit);
    if (empty($jobs)) {
        $log->put('No files pending bill detection.', 3);
        exit(0);
    }
    processBillJobs($jobs, $processor, $log);
    exit(0);
}

$messages = $dispatcher->receive($limit, 10);
if (empty($messages)) {
    $log->put('No bill-detection jobs in queue.', 3);
    exit(0);
}

foreach ($messages as $message) {
    try {
        if ($message->payload->type !== JobType::BILL_DETECTION) {
            $log->put('Skipping job of type ' . $message->payload->type, 4);
            continue;
        }
        $job = $mapper->fromPayload($message->payload);
        $processor->process($job);
    } catch (Throwable $e) {
        $log->put('Bill detection failed for file #' . $message->payload->fileId . ': ' . $e->getMessage(), 6);
    } finally {
        $dispatcher->acknowledge($message);
    }
}

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
