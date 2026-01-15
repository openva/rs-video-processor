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
use RichmondSunlight\VideoProcessor\Sync\VideoFilter;
use RichmondSunlight\VideoProcessor\Sync\VideoImporter;

$app = require __DIR__ . '/bootstrap.php';
$logger = $app->log ?? (class_exists('Log') ? new Log() : null);
$pdo = $app->pdo ?? null;

if (!$pdo) {
    throw new RuntimeException('Unable to connect to the database.');
}
$pdoFactory = static function () {
    unset($GLOBALS['db_pdo'], $GLOBALS['db']);
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
            'User-Agent' => 'RS-Video-Processor (+https://richmondsunlight.com/)',
        ],
    ])),
    1.0,
    3,
    5.0
);

// Always scrape new records (limit to 50 per source since new videos are at the top)
$houseScraper = new HouseScraper($http, logger: $logger, maxRecords: 50);
$senateScraper = new SenateScraper($http, logger: $logger, maxRecords: 50);
$newRecords = array_merge(
    $houseScraper->scrape(),
    $senateScraper->scrape()
);
$logger?->put(sprintf('Scraped %d new records', count($newRecords)), 3);

// Load cached records if they exist
$cachedRecords = [];
if (file_exists($snapshotPath)) {
    $loaded = json_decode(file_get_contents($snapshotPath), true);
    if (is_array($loaded)) {
        $cachedRecords = $loaded;
        $logger?->put(sprintf('Loaded %d cached records from snapshot %s', count($cachedRecords), $snapshotPath), 3);
    }
}

// Merge and deduplicate: new records take precedence over cached
$recordsById = [];
// First, add cached records
foreach ($cachedRecords as $record) {
    $key = ($record['chamber'] ?? 'unknown') . '|' . ($record['content_id'] ?? $record['clip_id'] ?? $record['video_id'] ?? uniqid());
    $recordsById[$key] = $record;
}
// Then, add/overwrite with new records (they take precedence)
foreach ($newRecords as $record) {
    $key = ($record['chamber'] ?? 'unknown') . '|' . ($record['content_id'] ?? $record['clip_id'] ?? $record['video_id'] ?? uniqid());
    $recordsById[$key] = $record;
}
$records = array_values($recordsById);

// Save merged records back to snapshot
file_put_contents($snapshotPath, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$logger?->put(sprintf('Total records after merge: %d (snapshot saved to %s)', count($records), $snapshotPath), 3);

$before = count($records);
$records = array_values(array_filter($records, [VideoFilter::class, 'shouldKeep']));
$logger?->put(sprintf('Filtered records: %d -> %d after applying keep/skip rules', $before, count($records)), 3);

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
