#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;
use Aws\TranscribeService\TranscribeServiceClient;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\AwsTranscribeDiarizer;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\LegislatorDirectory;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\OcrSpeakerExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerChamberConfig;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerMetadataExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerNameOcrEngine;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerNameParser;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerResultWriter;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerTextExtractor;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;

$app = require __DIR__ . '/bootstrap.php';

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

$queue = new SpeakerJobQueue($pdo);
$metadataExtractor = new SpeakerMetadataExtractor();
$legislators = new LegislatorDirectory($pdo);
$writer = new SpeakerResultWriter($pdo, $pdoFactory);
$minSegmentSeconds = getenv('SPEAKER_OCR_MIN_SEGMENT_SECONDS');
$minSegmentSeconds = is_numeric($minSegmentSeconds) ? (int) $minSegmentSeconds : 3;

$s3Client = S3ClientFactory::create(AWS_ACCESS_KEY, AWS_SECRET_KEY, AWS_REGION);

$transcribeClient = new TranscribeServiceClient([
    'credentials' => [
        'key' => AWS_ACCESS_KEY,
        'secret' => AWS_SECRET_KEY,
    ],
    'region' => AWS_REGION,
    'version' => '2017-10-26',
]);

$bucket = 'video.richmondsunlight.com';
$diarizer = new AwsTranscribeDiarizer($transcribeClient, $s3Client, $bucket, new AudioExtractor());
$manifestLoader = new ScreenshotManifestLoader();
$screenshotFetcher = new ScreenshotFetcher();
$ocrEngine = new SpeakerNameOcrEngine();
$textExtractor = new SpeakerTextExtractor($ocrEngine);
$nameParser = new SpeakerNameParser();
$chamberConfig = new SpeakerChamberConfig();
$ocrExtractor = new OcrSpeakerExtractor(
    $manifestLoader,
    $screenshotFetcher,
    $textExtractor,
    $nameParser,
    $chamberConfig,
    max(1, $minSegmentSeconds),
    $log
);

$processor = new SpeakerDetectionProcessor($metadataExtractor, $diarizer, $ocrExtractor, $legislators, $writer, $log);

if (isset($options['enqueue'])) {
    $log->put('--enqueue is deprecated (SQS removed); processing jobs directly from the database.', 2);
}

$jobs = $queue->fetch($limit);
if (empty($jobs)) {
    $log->put('No files pending speaker detection.', 3);
    exit(0);
}
processSpeakerJobs($jobs, $processor, $log);

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
