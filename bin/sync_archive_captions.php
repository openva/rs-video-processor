#!/usr/bin/env php
<?php

/**
 * Send new captions to videos already on the Internet Archive: the
 * archive_captions stage. Something asks for each one when it changes an
 * archived video's captions (ArchiveCaptions::requestIfArchived()), or
 * bin/request_archive_captions.php asks for them all.
 *
 * Usage:
 *   php bin/sync_archive_captions.php            # up to 10 videos
 *   php bin/sync_archive_captions.php --limit=50
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Archive\ArchiveCaptionQueue;
use RichmondSunlight\VideoProcessor\Archive\ArchiveCaptionUploader;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

$limit = 10;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

// Without credentials every upload fails; stop before using up any attempts.
$iaConfig = getenv('HOME') . '/.config/internetarchive/ia.ini';
if (!file_exists($iaConfig)) {
    $log->put('Internet Archive configuration missing at ' . $iaConfig . '. Run `ia configure`.', 6);
    exit(1);
}

$pdoFactory = AppBootstrap::connectionFactory();
$uploader = new ArchiveCaptionUploader($pdoFactory, $log);
$runner = new StageRunner(new JobLedger($app->pdo, $pdoFactory), JobLedger::STAGE_ARCHIVE_CAPTIONS, $log);

$counts = [ArchiveCaptionUploader::SENT => 0, ArchiveCaptionUploader::UNCHANGED => 0, ArchiveCaptionUploader::NOTHING => 0];
$runner->run(
    $limit,
    static fn() => (new ArchiveCaptionQueue($pdoFactory()))->fetch(1)[0] ?? null,
    static fn(int $fileId): int => $fileId,
    static function (int $fileId) use ($uploader, &$counts): void {
        $counts[$uploader->send($fileId)]++;
    }
);

$log->put(sprintf(
    'Sent captions for %d video(s) to the Internet Archive; %d already had them.',
    $counts[ArchiveCaptionUploader::SENT],
    $counts[ArchiveCaptionUploader::UNCHANGED]
), 3);
