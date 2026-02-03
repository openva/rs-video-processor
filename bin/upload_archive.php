#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Archive\ArchiveJobProcessor;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;
use RichmondSunlight\VideoProcessor\Archive\MetadataBuilder;
use RichmondSunlight\VideoProcessor\Archive\InternetArchiveUploader;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;
$pdo = $app->pdo;

$limit = 10;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (is_numeric($arg)) {
        $limit = (int) $arg;
    }
}

$metadataBuilder = new MetadataBuilder();
$uploader = new InternetArchiveUploader($log);
$queue = new ArchiveJobQueue($pdo);
$processor = new ArchiveJobProcessor($queue, $metadataBuilder, $uploader, $pdo, $log);
$processor->run($limit);

// After uploads complete, check once for any videos that processed quickly
// Videos that take longer will be caught at the start of the next processing session
require_once __DIR__ . '/repair_archive_urls_helper.php';

$log?->put('Checking for Archive.org videos that finished processing...', 3);
$pendingCount = 0;
$repairCount = repairArchiveUrls($pdo, $log, $pendingCount);

if ($repairCount > 0) {
    $log?->put("Resolved $repairCount Archive.org URL(s) that finished processing quickly", 3);
}

if ($pendingCount > 0) {
    $log?->put(sprintf('%d video(s) still processing on Archive.org. These will be resolved at the start of the next processing session.', $pendingCount), 3);
}
