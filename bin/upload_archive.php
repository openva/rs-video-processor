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

$limit = isset($argv[1]) ? (int) $argv[1] : 2;

$metadataBuilder = new MetadataBuilder();
$uploader = new InternetArchiveUploader($log);
$queue = new ArchiveJobQueue($pdo);
$processor = new ArchiveJobProcessor($queue, $metadataBuilder, $uploader, $pdo, $log);
$processor->run($limit);
