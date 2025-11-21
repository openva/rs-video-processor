<?php

namespace RichmondSunlight\VideoProcessor\Tests\Scraper;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Scraper\Senate\SenateScraper;
use RichmondSunlight\VideoProcessor\Tests\Support\FakeHttpClient;

class SenateScraperTest extends TestCase
{
    public function testParsesYouTubeFeed(): void
    {
        $client = new FakeHttpClient([
            'feeds/videos.xml' => file_get_contents(__DIR__ . '/../fixtures/senate-feed.xml'),
        ]);

        $scraper = new SenateScraper($client, 'https://www.youtube.com/feeds/videos.xml?channel_id=test');
        $records = $scraper->scrape();

        $this->assertCount(2, $records);
        $first = $records[0];

        $this->assertSame('senate', $first['chamber']);
        $this->assertSame('6aYWtT8hGtM', $first['video_id']);
        $this->assertSame('https://www.youtube.com/watch?v=6aYWtT8hGtM', $first['video_url']);
        $this->assertStringContainsString('Joint Commission on Technology and Science', $first['title']);
        $this->assertSame('https://www.youtube.com/api/timedtext?v=6aYWtT8hGtM&lang=en', $first['captions_url']);
        $this->assertSame('2025-11-19', $first['meeting_date']);
        $this->assertSame('Joint Commission on Technology and Science', $first['committee']);
        $this->assertSame('Blockchain Subcommittee', $first['subcommittee']);

        $second = $records[1];
        $this->assertSame('State Water Commission', $second['committee']);
        $this->assertNull($second['subcommittee']);
    }
}
