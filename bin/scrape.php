#!/usr/bin/env php
<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Scraper\FilesystemMetadataWriter;
use RichmondSunlight\VideoProcessor\Scraper\House\HouseScraper;
use RichmondSunlight\VideoProcessor\Scraper\Http\GuzzleHttpClient;
use RichmondSunlight\VideoProcessor\Scraper\Http\RateLimitedHttpClient;
use RichmondSunlight\VideoProcessor\Scraper\Senate\SenateScraper;
use RichmondSunlight\VideoProcessor\Scraper\VideoScraper;

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

$house = new HouseScraper($http);
$senate = new SenateScraper($http);
$writer = new FilesystemMetadataWriter(__DIR__ . '/../storage/scraper');
$logger = class_exists('Log') ? new Log() : null;

$scraper = new VideoScraper([$house, $senate], $writer, $logger);
$path = $scraper->run();

echo sprintf("Scraped metadata written to %s\n", $path);
