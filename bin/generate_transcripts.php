#!/usr/bin/env php
<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAITranscriber;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobQueue;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptProcessor;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Contract\ContractValidator;
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
$log = $app->log;
$pdo = $app->pdo;
$pdoFactory = fn() => AppBootstrap::createFreshConnection();
$jobQueue = new TranscriptJobQueue($pdo);
$writer = new TranscriptWriter($pdo, $pdoFactory);
$httpClient = new Client(['timeout' => 1800]);
$transcriber = new OpenAITranscriber($httpClient, OPENAI_KEY);
$processor = new TranscriptProcessor($writer, $transcriber, new CaptionParser(), null, $log);

if (isset($options['enqueue'])) {
    $log->put('--enqueue is deprecated (SQS removed); processing jobs directly from the database.', 2);
}

// TranscriptJobQueue::fetch() already selects webvtt/srt, so there is no need
// to re-fetch large columns from the DB (as the old SQS payload path did).
$jobs = $jobQueue->fetch($limit);
if (empty($jobs)) {
    $log->put('No files pending transcript generation.', 3);
    exit(0);
}
processTranscriptJobs($jobs, $processor, $log);

function processTranscriptJobs(array $jobs, TranscriptProcessor $processor, Log $log): void
{
    foreach ($jobs as $job) {
        try {
            $processor->process($job);
            validateTranscriptContract(AppBootstrap::createFreshConnection(), $job->fileId, $log);
        } catch (Throwable $e) {
            $log->put('Transcript generation failed for file #' . $job->fileId . ': ' . $e->getMessage(), 6);
        }
    }
}

function validateTranscriptContract(PDO $pdo, int $fileId, Log $log): void
{
    $validator = new ContractValidator($pdo);
    $issues = $validator->validateFile($fileId);
    $problems = array_filter($issues, fn($i) => $i['level'] === 'error' || $i['level'] === 'warning');

    foreach ($problems as $issue) {
        $log->put(
            sprintf('Contract %s for file #%d: %s', strtoupper($issue['level']), $fileId, $issue['message']),
            $issue['level'] === 'error' ? 5 : 2
        );
    }
}
