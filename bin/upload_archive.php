#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Archive\ArchiveJob;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobProcessor;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;
use RichmondSunlight\VideoProcessor\Archive\InternetArchiveUploader;
use RichmondSunlight\VideoProcessor\Archive\MetadataBuilder;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

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
$processor = new ArchiveJobProcessor(
    new ArchiveJobQueue($app->pdo),
    new MetadataBuilder(),
    new InternetArchiveUploader($log),
    $app->pdo,
    $log,
    $pdoFactory
);

$runner = new StageRunner(new JobLedger($app->pdo, $pdoFactory), JobLedger::STAGE_ARCHIVE, $log);

$uploaded = $runner->run(
    $limit,
    static fn() => (new ArchiveJobQueue($pdoFactory()))->fetch(1)[0] ?? null,
    static fn(ArchiveJob $job): int => $job->fileId,
    static function (ArchiveJob $job) use ($processor): void {
        $processor->processJob($job);
    }
);

$log->put("Uploaded {$uploaded} video(s) to the Internet Archive.", 3);

// After uploads complete, check once for any videos that processed quickly.
// Videos that take longer are caught at the start of the next session.
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
