#!/usr/bin/env php
<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use Log;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\S3Storage;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotGenerator;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJobQueue;

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/functions.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';
require_once __DIR__ . '/../includes/class.Database.php';

$log = new Log();
$database = new Database();
$pdo = $database->connect();
if (!$pdo) {
    throw new RuntimeException('Unable to obtain database connection.');
}

$limit = isset($argv[1]) ? (int) $argv[1] : 3;

$s3Client = new S3Client([
    'key' => AWS_ACCESS_KEY,
    'secret' => AWS_SECRET_KEY,
    'region' => 'us-east-1',
    'version' => '2006-03-01',
]);

$bucket = 'video.richmondsunlight.com';
$storage = new S3Storage($s3Client, $bucket);
$committeeDirectory = new CommitteeDirectory($pdo);
$keyBuilder = new S3KeyBuilder();
$generator = new ScreenshotGenerator($pdo, $storage, $committeeDirectory, $keyBuilder, $log);
$queue = new ScreenshotJobQueue($pdo);

$jobs = $queue->fetch($limit);
if (empty($jobs)) {
    $log->put('No videos pending screenshot generation.', 3);
    exit(0);
}

foreach ($jobs as $job) {
    try {
        $generator->process($job);
    } catch (Throwable $e) {
        $log->put('Screenshot job failed for file #' . $job->id . ': ' . $e->getMessage(), 6);
    }
}
