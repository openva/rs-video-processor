#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;
use RichmondSunlight\VideoProcessor\Analysis\Bills\TesseractOcrEngine;
use RichmondSunlight\VideoProcessor\Analysis\Classification\ClassificationCorrectionWriter;
use RichmondSunlight\VideoProcessor\Analysis\Classification\ClassificationVerificationJob;
use RichmondSunlight\VideoProcessor\Analysis\Classification\ClassificationVerificationJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Classification\ClassificationVerificationPayloadMapper;
use RichmondSunlight\VideoProcessor\Analysis\Classification\ClassificationVerificationProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Classification\FrameClassifier;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
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

$queue = new ClassificationVerificationJobQueue($pdo);
$mapper = new ClassificationVerificationPayloadMapper();

$processor = new ClassificationVerificationProcessor(
    new ScreenshotManifestLoader(),
    new ScreenshotFetcher(),
    new FrameClassifier(new TesseractOcrEngine()),
    new ClassificationCorrectionWriter($pdo),
    new CommitteeDirectory($pdo),
    $log
);

if ($mode === 'enqueue') {
    $jobs = $queue->fetch($limit);
    if (empty($jobs)) {
        $log->put('No files pending classification verification.', 3);
        exit(0);
    }
    if ($dispatcher->usesInMemoryQueue()) {
        processClassificationJobs($jobs, $processor, $log);
        exit(0);
    }
    foreach ($jobs as $job) {
        $dispatcher->dispatch($mapper->toPayload($job));
    }
    $log->put('Enqueued ' . count($jobs) . ' classification-verification jobs.', 3);
    exit(0);
}

if ($dispatcher->usesInMemoryQueue()) {
    $jobs = $queue->fetch($limit);
    if (empty($jobs)) {
        $log->put('No files pending classification verification.', 3);
        exit(0);
    }
    processClassificationJobs($jobs, $processor, $log);
    exit(0);
}

$messages = $dispatcher->receive($limit, 10);
if (empty($messages)) {
    $log->put('No classification-verification jobs in queue.', 3);
    exit(0);
}

foreach ($messages as $message) {
    try {
        if ($message->payload->type !== JobType::CLASSIFICATION_VERIFICATION) {
            $log->put('Skipping job of type ' . $message->payload->type, 4);
            continue;
        }
        $job = $mapper->fromPayload($message->payload);
        $processor->process($job);
    } catch (Throwable $e) {
        $log->put('Classification verification failed for file #' . $message->payload->fileId . ': ' . $e->getMessage(), 6);
    } finally {
        $dispatcher->acknowledge($message);
    }
}

function processClassificationJobs(array $jobs, ClassificationVerificationProcessor $processor, Log $log): void
{
    foreach ($jobs as $job) {
        try {
            $processor->process($job);
        } catch (Throwable $e) {
            $log->put('Classification verification failed for file #' . $job->fileId . ': ' . $e->getMessage(), 6);
        }
    }
}
