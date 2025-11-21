#!/usr/bin/env php
<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Log;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\LegislatorDirectory;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\OpenAIDiarizer;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerMetadataExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerResultWriter;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;

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

$queue = new SpeakerJobQueue($pdo);
$jobs = $queue->fetch($limit);
if (empty($jobs)) {
    $log->put('No files pending speaker detection.', 3);
    exit(0);
}

$metadataExtractor = new SpeakerMetadataExtractor();
$legislators = new LegislatorDirectory($pdo);
$writer = new SpeakerResultWriter($pdo);
$httpClient = new Client(['timeout' => 300]);
$diarizer = new OpenAIDiarizer($httpClient, OPENAI_KEY, new AudioExtractor());
$processor = new SpeakerDetectionProcessor($metadataExtractor, $diarizer, $legislators, $writer, $log);

foreach ($jobs as $job) {
    try {
        $processor->process($job);
    } catch (Throwable $e) {
        $log->put('Speaker detection failed for file #' . $job->fileId . ': ' . $e->getMessage(), 6);
    }
}
