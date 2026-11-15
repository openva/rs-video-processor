#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Archive\ArchiveJob;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobProcessor;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;
use RichmondSunlight\VideoProcessor\Archive\InternetArchiveUploader;
use RichmondSunlight\VideoProcessor\Archive\MetadataBuilder;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Fetcher\BucketReader;
use RichmondSunlight\VideoProcessor\Fetcher\ColdStorage;
use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

/** The most waiting uploads one run checks on. */
const CHECK_LIMIT = 200;

$limit = 10;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (is_numeric($arg)) {
        $limit = (int) $arg;
    }
}

// Without credentials every upload fails. Stop here, so a missing config file
// does not use up the attempts of every video waiting to be archived.
$iaConfig = getenv('HOME') . '/.config/internetarchive/ia.ini';
if (!file_exists($iaConfig)) {
    $log->put('Internet Archive configuration missing at ' . $iaConfig . '. Run `ia configure`.', 6);
    exit(1);
}

$pdoFactory = AppBootstrap::connectionFactory();
$s3 = S3ClientFactory::create(AWS_ACCESS_KEY, AWS_SECRET_KEY, AWS_REGION);
$processor = new ArchiveJobProcessor(
    new ArchiveJobQueue($app->pdo),
    new MetadataBuilder(),
    // Videos are read straight from the bucket, not through CloudFront.
    new InternetArchiveUploader($log, null, null, null, null, new BucketReader($s3, 'video.richmondsunlight.com', $log)),
    $app->pdo,
    $log,
    $pdoFactory,
    // Once archived, the S3 copy is a backup, kept in a cheaper storage class.
    new ColdStorage($s3)
);

$runner = new StageRunner(new JobLedger($app->pdo, $pdoFactory), JobLedger::STAGE_ARCHIVE, $log);

$process = static function (ArchiveJob $job) use ($processor): void {
    $processor->processJob($job);
};
$fileIdOf = static fn(ArchiveJob $job): int => $job->fileId;

// First, the uploads waiting for the archive to list them, whose time to be
// checked has come. Each check is one question to the archive, so they don't
// count against --limit: counted with the uploads, as they were from October
// 2026, they took half of every run's places, and archiving slowed from 25
// videos a day to 7. The cap only bounds a run if something goes wrong.
$confirmed = $runner->run(
    CHECK_LIMIT,
    static fn() => (new ArchiveJobQueue($pdoFactory()))->fetch(1, ArchiveJobQueue::WAITING)[0] ?? null,
    $fileIdOf,
    $process
);
if ($confirmed > 0) {
    $log->put("Confirmed {$confirmed} earlier upload(s) to the Internet Archive.", 3);
}

$uploaded = $runner->run(
    $limit,
    static fn() => (new ArchiveJobQueue($pdoFactory()))->fetch(1, ArchiveJobQueue::NOT_WAITING)[0] ?? null,
    $fileIdOf,
    $process
);

$log->put("Uploaded {$uploaded} video(s) to the Internet Archive.", 3);

// Uploads before November 2026 stored a details URL when the archive was slow,
// and these are swapped for the MP4 once it is listed. New uploads keep their
// S3 path until then instead, so this only finds those older ones.
require_once __DIR__ . '/repair_archive_urls_helper.php';

$log->put('Checking for Archive.org videos that finished processing...', 3);
$pendingCount = 0;
$repairCount = repairArchiveUrls($pdoFactory(), $log, $pendingCount);

if ($repairCount > 0) {
    $log->put("Resolved $repairCount Archive.org URL(s) that finished processing quickly", 3);
}

if ($pendingCount > 0) {
    $log->put(sprintf('%d video(s) still processing on Archive.org. These will be resolved at the start of the next processing session.', $pendingCount), 3);
}
