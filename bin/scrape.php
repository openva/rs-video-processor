#!/usr/bin/env php
<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Scraper\FilesystemMetadataWriter;
use RichmondSunlight\VideoProcessor\Scraper\House\HouseScraper;
use RichmondSunlight\VideoProcessor\Scraper\Http\GuzzleHttpClient;
use RichmondSunlight\VideoProcessor\Scraper\Http\RateLimitedHttpClient;
use RichmondSunlight\VideoProcessor\Scraper\Senate\SenateScraper;
use RichmondSunlight\VideoProcessor\Scraper\Senate\SenateYouTubeScraper;
use RichmondSunlight\VideoProcessor\Scraper\VideoScraper;

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';
require_once __DIR__ . '/../includes/class.Log.php';

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

// Allow limiting records for testing (set via environment variable or hardcoded default)
$maxRecords = getenv('MAX_SCRAPE_RECORDS');
if ($maxRecords !== false && $maxRecords !== '') {
    $maxRecords = (int)$maxRecords;
    echo "Limiting to {$maxRecords} records per scraper\n";
} else {
    $maxRecords = 500;  // Change this to null for unlimited, or set a number for testing
    if ($maxRecords !== null) {
        echo "Using hardcoded limit of {$maxRecords} records per scraper\n";
    } else {
        echo "No limit set - scraping all records\n";
    }
}

$house = new HouseScraper($http, maxRecords: $maxRecords);
$senateGranicus = new SenateScraper($http, maxRecords: $maxRecords);
$senateYouTube = new SenateYouTubeScraper($http, YOUTUBE_API_KEY ?? '', maxRecords: $maxRecords);
$writer = new FilesystemMetadataWriter(__DIR__ . '/../storage/scraper');
$logger = class_exists('Log') ? new Log() : null;

$scraper = new VideoScraper([$house, $senateGranicus, $senateYouTube], $writer, $logger);
$path = $scraper->run();

echo sprintf("Scraped metadata written to %s\n", $path);
