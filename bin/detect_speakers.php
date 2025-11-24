#!/usr/bin/env php
<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Log;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\LegislatorDirectory;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\OpenAIDiarizer;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJob;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJobPayloadMapper;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerMetadataExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerResultWriter;
use RichmondSunlight\VideoProcessor\Queue\JobType;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;

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

$log = $app->log;
$pdo = $app->pdo;
$dispatcher = $app->dispatcher;

$queue = new SpeakerJobQueue($pdo);
$mapper = new SpeakerJobPayloadMapper();
$metadataExtractor = new SpeakerMetadataExtractor();
$legislators = new LegislatorDirectory($pdo);
$writer = new SpeakerResultWriter($pdo);
$httpClient = new Client(['timeout' => 300]);
$diarizer = new OpenAIDiarizer($httpClient, OPENAI_KEY, new AudioExtractor());
$processor = new SpeakerDetectionProcessor($metadataExtractor, $diarizer, $legislators, $writer, $log);

if ($mode === 'enqueue') {
    $jobs = $queue->fetch($limit);
    if (empty($jobs)) {
        $log->put('No files pending speaker detection.', 3);
        exit(0);
    }
    if ($dispatcher->usesInMemoryQueue()) {
        processSpeakerJobs($jobs, $processor, $log);
        exit(0);
    }
    foreach ($jobs as $job) {
        $dispatcher->dispatch($mapper->toPayload($job));
    }
    $log->put('Enqueued ' . count($jobs) . ' speaker-detection jobs.', 3);
    exit(0);
}

if ($dispatcher->usesInMemoryQueue()) {
    $jobs = $queue->fetch($limit);
    if (empty($jobs)) {
        $log->put('No files pending speaker detection.', 3);
        exit(0);
    }
    processSpeakerJobs($jobs, $processor, $log);
    exit(0);
}

$messages = $dispatcher->receive($limit, 10);
if (empty($messages)) {
    $log->put('No speaker-detection jobs in queue.', 3);
    exit(0);
}

foreach ($messages as $message) {
    try {
        if ($message->payload->type !== JobType::SPEAKER_DETECTION) {
            $log->put('Skipping job of type ' . $message->payload->type, 4);
            continue;
        }
        $job = $mapper->fromPayload($message->payload);
        $processor->process($job);
    } catch (Throwable $e) {
        $log->put('Speaker detection failed for file #' . $message->payload->fileId . ': ' . $e->getMessage(), 6);
    } finally {
        $dispatcher->acknowledge($message);
    }
}

function processSpeakerJobs(array $jobs, SpeakerDetectionProcessor $processor, Log $log): void
{
    foreach ($jobs as $job) {
        try {
            $processor->process($job);
        } catch (Throwable $e) {
            $log->put('Speaker detection failed for file #' . $job->fileId . ': ' . $e->getMessage(), 6);
        }
    }
}
