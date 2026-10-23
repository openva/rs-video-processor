#!/usr/bin/env php
<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Contract\ContractValidator;
use RichmondSunlight\VideoProcessor\Fetcher\BucketReader;
use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionAligner;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAITranscriber;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJob;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobQueue;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptProcessor;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

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

$pdoFactory = AppBootstrap::connectionFactory();
$writer = new TranscriptWriter($app->pdo, $pdoFactory);
$transcriber = new OpenAITranscriber(new Client(['timeout' => 1800]), OPENAI_KEY);
// Videos are read straight from the bucket, not through CloudFront.
$audioExtractor = new AudioExtractor(null, new BucketReader(
    S3ClientFactory::create(AWS_ACCESS_KEY, AWS_SECRET_KEY, AWS_REGION),
    'video.richmondsunlight.com',
    $log
));
// Captions are checked against the audio, and shifted when they run early or late.
$aligner = new CaptionAligner($transcriber, $audioExtractor);
$processor = new TranscriptProcessor($writer, $transcriber, new CaptionParser(), $audioExtractor, $log, $aligner);

$runner = new StageRunner(new JobLedger($app->pdo, $pdoFactory), JobLedger::STAGE_TRANSCRIPT, $log);

$processed = $runner->run(
    $limit,
    static fn() => (new TranscriptJobQueue($pdoFactory()))->fetch(1)[0] ?? null,
    static fn(TranscriptJob $job): int => $job->fileId,
    static function (TranscriptJob $job) use ($processor, $pdoFactory, $log): void {
        $processor->process($job);

        $issues = (new ContractValidator($pdoFactory()))->validateFile($job->fileId);
        foreach ($issues as $issue) {
            if ($issue['level'] !== 'error' && $issue['level'] !== 'warning') {
                continue;
            }
            $log->put(
                sprintf('Contract %s for file #%d: %s', strtoupper($issue['level']), $job->fileId, $issue['message']),
                $issue['level'] === 'error' ? 5 : 2
            );
        }
    }
);

$log->put("Generated {$processed} transcript(s).", 3);
