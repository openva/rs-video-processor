#!/usr/bin/env php
<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Log;
use RichmondSunlight\VideoProcessor\Queue\JobType;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAITranscriber;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobQueue;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobPayloadMapper;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptProcessor;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

$app = require __DIR__ . '/bootstrap.php';

if (!defined('OPENAI_KEY') || OPENAI_KEY === '') {
    fwrite(STDERR, "OPENAI_KEY is not defined.\n");
    exit(1);
}

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

$dispatcher = $app->dispatcher;
$log = $app->log;
$pdo = $app->pdo;
$jobQueue = new TranscriptJobQueue($pdo);
$mapper = new TranscriptJobPayloadMapper();
$writer = new TranscriptWriter($pdo);
$httpClient = new Client(['timeout' => 120]);
$transcriber = new OpenAITranscriber($httpClient, OPENAI_KEY);
$processor = new TranscriptProcessor($writer, $transcriber, new CaptionParser(), null, $log);

if ($mode === 'enqueue') {
    $jobs = $jobQueue->fetch($limit);
    if (empty($jobs)) {
        $log->put('No files pending transcript generation.', 3);
        exit(0);
    }
    if ($dispatcher->usesInMemoryQueue()) {
        processTranscriptJobs($jobs, $processor, $log);
        exit(0);
    }
    foreach ($jobs as $job) {
        $dispatcher->dispatch($mapper->toPayload($job));
    }
    $log->put('Enqueued ' . count($jobs) . ' transcript jobs.', 3);
    exit(0);
}

if ($dispatcher->usesInMemoryQueue()) {
    $jobs = $jobQueue->fetch($limit);
    if (empty($jobs)) {
        $log->put('No files pending transcript generation.', 3);
        exit(0);
    }
    processTranscriptJobs($jobs, $processor, $log);
    exit(0);
}

$messages = $dispatcher->receive($limit, 10);
if (empty($messages)) {
    $log->put('No transcript jobs in queue.', 3);
    exit(0);
}

foreach ($messages as $message) {
    try {
        if ($message->payload->type !== JobType::TRANSCRIPT) {
            $log->put('Skipping job of type ' . $message->payload->type, 4);
            continue;
        }
        $job = $mapper->fromPayload($message->payload);
        $processor->process($job);
    } catch (Throwable $e) {
        $log->put('Transcript generation failed for file #' . $message->payload->fileId . ': ' . $e->getMessage(), 6);
    } finally {
        $dispatcher->acknowledge($message);
    }
}

function processTranscriptJobs(array $jobs, TranscriptProcessor $processor, Log $log): void
{
    foreach ($jobs as $job) {
        try {
            $processor->process($job);
        } catch (Throwable $e) {
            $log->put('Transcript generation failed for file #' . $job->fileId . ': ' . $e->getMessage(), 6);
        }
    }
}
