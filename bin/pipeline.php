#!/usr/bin/env php
<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Scraper\House\HouseScraper;
use RichmondSunlight\VideoProcessor\Scraper\Http\GuzzleHttpClient;
use RichmondSunlight\VideoProcessor\Scraper\Http\RateLimitedHttpClient;
use RichmondSunlight\VideoProcessor\Scraper\Senate\SenateScraper;
use RichmondSunlight\VideoProcessor\Sync\ExistingFilesRepository;
use RichmondSunlight\VideoProcessor\Sync\MissingVideoFilter;
use RichmondSunlight\VideoProcessor\Sync\VideoImporter;

$app = require __DIR__ . '/bootstrap.php';
$logger = $app->log ?? (class_exists('Log') ? new Log() : null);
$pdo = $app->pdo ?? null;

if (!$pdo) {
    throw new RuntimeException('Unable to connect to the database.');
}
$pdoFactory = static function () {
    $db = new \Database();
    return $db->connect();
};

$outputDir = __DIR__ . '/../storage/pipeline';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}
$snapshotPath = $outputDir . '/scraped-latest.json';

$http = new RateLimitedHttpClient(
    new GuzzleHttpClient(new Client([
        'timeout' => 60,
        'connect_timeout' => 10,
        'headers' => [
            'User-Agent' => 'rs-video-processor (+https://richmondsunlight.com/)',
        ],
    ])),
    1.0,
    3,
    5.0
);

$records = [];
if (file_exists($snapshotPath)) {
    $loaded = json_decode(file_get_contents($snapshotPath), true);
    if (is_array($loaded)) {
        $records = $loaded;
        $logger?->put(sprintf('Loaded %d scraped records from snapshot %s', count($records), $snapshotPath), 3);
    }
}

if (empty($records)) {
    $houseScraper = new HouseScraper($http, logger: $logger);
    $senateScraper = new SenateScraper($http, logger: $logger);
    $records = array_merge(
        $houseScraper->scrape(),
        $senateScraper->scrape()
    );
    file_put_contents($snapshotPath, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $logger?->put(sprintf('Total scraped records: %d (snapshot saved to %s)', count($records), $snapshotPath), 3);
} else {
    $logger?->put(sprintf('Skipping scrape; using existing snapshot %s', $snapshotPath), 3);
}

$repository = new ExistingFilesRepository($pdo, $pdoFactory);
$filter = new MissingVideoFilter($repository);
$missing = $filter->filter($records);

$logger?->put(sprintf('Videos missing from database: %d', count($missing)), 3);

$committees = new CommitteeDirectory($pdo);
$importer = new VideoImporter($pdo, $committees, $logger);
$importedCount = $importer->import($missing);
$logger?->put(sprintf('Imported %d new videos into files table', $importedCount), 3);

$outputPath = $outputDir . '/missing-' . date('Ymd_His') . '.json';
file_put_contents(
    $outputPath,
    json_encode(
        [
            'generated_at' => date(DATE_ATOM),
            'scraped_count' => count($records),
            'missing_count' => count($missing),
            'records' => $missing,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    )
);

echo sprintf(
    "Pipeline complete. Scraped: %d, Missing: %d, Inserted: %d, Output: %s\n",
    count($records),
    count($missing),
    $importedCount,
    $outputPath
);
