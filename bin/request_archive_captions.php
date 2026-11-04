#!/usr/bin/env php
<?php

/**
 * Ask for the captions of every archived video to be sent to its Internet
 * Archive item, for those archived before the archive_captions stage existed:
 * items uploaded without captions, and captions shifted by
 * bin/align_transcripts.php after archiving. bin/sync_archive_captions.php,
 * which the pipeline runs, then sends them a few at a time. An item that
 * already has identical captions under the right name costs one metadata
 * request and no upload.
 *
 * Run bin/fix_archive_caption_names.php first: renaming the existing caption
 * files is cheaper than uploading them again, and afterwards most items match.
 *
 * Usage:
 *   php bin/request_archive_captions.php           # count them
 *   php bin/request_archive_captions.php --apply   # ask for them
 *
 * Only captions whose timing has been checked are sent (ArchiveCaptions::sendable()).
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Jobs\JobLedger;

$app = require __DIR__ . '/bootstrap.php';

$apply = in_array('--apply', $argv, true);

$ids = $app->pdo->query(
    "SELECT f.id FROM files f
     WHERE f.path LIKE 'https://archive.org/download/%'
       AND f.webvtt IS NOT NULL AND f.webvtt != ''
       AND f.caption_alignment IS NOT NULL
     ORDER BY f.date DESC, f.id DESC"
)->fetchAll(PDO::FETCH_COLUMN);

printf("%d archived video(s) have captions whose timing has been checked.\n", count($ids));
if (!$apply) {
    echo "Nothing was changed. Add --apply to ask for their captions to be sent.\n";
    exit(0);
}

$ledger = new JobLedger($app->pdo);
foreach ($ids as $id) {
    $ledger->request((int) $id, JobLedger::STAGE_ARCHIVE_CAPTIONS);
}
printf("Asked for %d. The pipeline sends them with bin/sync_archive_captions.php.\n", count($ids));
$app->log?->put(sprintf('Asked for the captions of %d archived video(s) to be sent to the Internet Archive.', count($ids)), 3);
