#!/usr/bin/env php
<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Log;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAITranscriber;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobQueue;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptProcessor;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';
require_once __DIR__ . '/../includes/class.Database.php';

if (!defined('OPENAI_KEY') || OPENAI_KEY === '') {
    fwrite(STDERR, "OPENAI_KEY is not defined.\n");
    exit(1);
}

$log = new Log();
$database = new Database();
$pdo = $database->connect();
if (!$pdo) {
    throw new RuntimeException('Unable to connect to database.');
}

$limit = isset($argv[1]) ? (int) $argv[1] : 3;

$queue = new TranscriptJobQueue($pdo);
$jobs = $queue->fetch($limit);
if (empty($jobs)) {
    $log->put('No files pending transcript generation.', 3);
    exit(0);
}

$writer = new TranscriptWriter($pdo);
$httpClient = new Client(['timeout' => 120]);
$transcriber = new OpenAITranscriber($httpClient, OPENAI_KEY);
$processor = new TranscriptProcessor($writer, $transcriber, new CaptionParser(), null, $log);

foreach ($jobs as $job) {
    try {
        $processor->process($job);
    } catch (Throwable $e) {
        $log->put('Transcript generation failed for file #' . $job->fileId . ': ' . $e->getMessage(), 6);
    }
}
