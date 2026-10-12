#!/usr/bin/env php
<?php

declare(strict_types=1);

use Aws\TranscribeService\TranscribeServiceClient;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\AwsTranscribeDiarizer;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\LegislatorDirectory;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\OcrSpeakerExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerChamberConfig;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJob;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerMetadataExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerNameOcrEngine;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerNameParser;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerResultWriter;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerTextExtractor;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

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

$pdoFactory = AppBootstrap::connectionFactory();

$minSegmentSeconds = getenv('SPEAKER_OCR_MIN_SEGMENT_SECONDS');
$minSegmentSeconds = is_numeric($minSegmentSeconds) ? (int) $minSegmentSeconds : 3;

// AWS Transcribe diarization is billed per audio hour and returns anonymous
// speaker labels, so it is off unless SPEAKER_DIARIZATION is set to 1.
$diarizationEnabled = filter_var(getenv('SPEAKER_DIARIZATION'), FILTER_VALIDATE_BOOLEAN);

$s3Client = S3ClientFactory::create(AWS_ACCESS_KEY, AWS_SECRET_KEY, AWS_REGION);
$transcribeClient = new TranscribeServiceClient([
    'credentials' => [
        'key' => AWS_ACCESS_KEY,
        'secret' => AWS_SECRET_KEY,
    ],
    'region' => AWS_REGION,
    'version' => '2017-10-26',
]);

$diarizer = new AwsTranscribeDiarizer($transcribeClient, $s3Client, 'video.richmondsunlight.com', new AudioExtractor());
$ocrExtractor = new OcrSpeakerExtractor(
    new ScreenshotManifestLoader(),
    new ScreenshotFetcher(),
    new SpeakerTextExtractor(new SpeakerNameOcrEngine()),
    new SpeakerNameParser(),
    new SpeakerChamberConfig(),
    max(1, $minSegmentSeconds),
    $log
);

$processor = new SpeakerDetectionProcessor(
    new SpeakerMetadataExtractor(),
    $diarizer,
    $ocrExtractor,
    new LegislatorDirectory($app->pdo),
    new SpeakerResultWriter($app->pdo, $pdoFactory),
    $log,
    $diarizationEnabled
);

$runner = new StageRunner(new JobLedger($app->pdo, $pdoFactory), JobLedger::STAGE_SPEAKERS, $log);

$processed = $runner->run(
    $limit,
    static fn() => (new SpeakerJobQueue($pdoFactory()))->fetch(1)[0] ?? null,
    static fn(SpeakerJob $job): int => $job->fileId,
    // process() returns false when it could not finish; StageRunner counts that as a failure.
    static fn(SpeakerJob $job): bool => $processor->process($job)
);

$log->put("Finished speaker detection for {$processed} file(s).", 3);
