<?php

namespace RichmondSunlight\VideoProcessor\Tests\Scraper;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Scraper\Senate\SenateScraper;
use RichmondSunlight\VideoProcessor\Tests\Support\FakeHttpClient;

class SenateScraperTest extends TestCase
{
    public function testParsesGranicusListing(): void
    {
        $client = new FakeHttpClient([
            'MediaPlayer.php?view_id=3&clip_id=' => file_get_contents(__DIR__ . '/../fixtures/senate-video.html'),
            'ViewPublisher.php?view_id=3' => file_get_contents(__DIR__ . '/../fixtures/senate-listing.html'),
        ]);

        $scraper = new SenateScraper($client, 'https://virginia-senate.granicus.com/ViewPublisher.php?view_id=3', maxRecords: 5);
        $records = $scraper->scrape();

        $this->assertCount(5, $records);

        $first = $records[0];
        $this->assertSame('senate', $first['chamber']);
        $this->assertSame('7681', $first['video_id']);
        $this->assertSame('State Water Commission', $first['committee']);
        $this->assertSame('2025-11-19', $first['meeting_date']);
        $this->assertSame('committee', $first['event_type']);
        $this->assertSame('https://virginia-senate.granicus.com/MediaPlayer.php?view_id=3&clip_id=7681', $first['embed_url']);
        $this->assertSame('https://virginia-senate.granicus.com/videos/7681/captions.vtt', $first['captions_url']);
        $this->assertSame('https://archive-video.granicus.com/virginia-senate/virginia-senate_f9e7145f-e3f8-11ef-a9e2-005056a89546.mp4', $first['video_url']);
        $this->assertStringContainsString('General Laws and Technology', $first['description']);
    }
}
