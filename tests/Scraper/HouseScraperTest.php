<?php

namespace RichmondSunlight\VideoProcessor\Tests\Scraper;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Scraper\House\HouseScraper;
use RichmondSunlight\VideoProcessor\Tests\Support\FakeHttpClient;

class HouseScraperTest extends TestCase
{
    public function testScrapesHouseMetadataFromFixtures(): void
    {
        $client = new FakeHttpClient([
            'GetListViewData' => file_get_contents(__DIR__ . '/../fixtures/house-listing.json'),
            'PowerBrowserV2' => file_get_contents(__DIR__ . '/../fixtures/house-committee-video.html'),
        ]);

        $scraper = new HouseScraper($client, 'https://example.test/00304/Harmony');

        $records = $scraper->scrape();

        $this->assertCount(1, $records);
        $record = $records[0];

        $this->assertSame('house', $record['chamber']);
        $this->assertSame('Appropriations', $record['title']);
        $this->assertSame('https://sg001-harmony01.sliq.net/00304archives/2025/01/31/Appropriations_2025-01-31-13.40.09_3299_20760_6.mp4', $record['video_url']);
        $this->assertNotEmpty($record['agenda']);
        $this->assertNotEmpty($record['speakers']);
        $this->assertStringContainsString('/PowerBrowser/PowerBrowserV2/', $record['detail_url']);
    }
}
