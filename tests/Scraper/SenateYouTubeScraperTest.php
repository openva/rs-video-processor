<?php

namespace RichmondSunlight\VideoProcessor\Tests\Scraper;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Scraper\Senate\SenateYouTubeScraper;
use RichmondSunlight\VideoProcessor\Tests\Support\FakeHttpClient;

class SenateYouTubeScraperTest extends TestCase
{
    public function testScrapesYouTubeVideos(): void
    {
        $client = new FakeHttpClient([
            'search?part=id,snippet&channelId=' => file_get_contents(__DIR__ . '/../fixtures/youtube-live-videos.json'),
            'videos?part=snippet,contentDetails&id=' => file_get_contents(__DIR__ . '/../fixtures/youtube-video-details.json'),
        ]);

        $scraper = new SenateYouTubeScraper($client, 'fake-api-key');
        $records = $scraper->scrape();

        $this->assertCount(3, $records);

        // Test first record (Finance Committee)
        $first = $records[0];
        $this->assertSame('senate-youtube', $first['source']);
        $this->assertSame('senate', $first['chamber']);
        $this->assertSame('dQw4w9WgXcQ', $first['video_id']);
        $this->assertSame('dQw4w9WgXcQ', $first['youtube_id']);
        $this->assertSame('Finance Committee - January 15, 2026 - Budget Hearing', $first['title']);
        $this->assertSame('Finance Committee', $first['committee']);
        $this->assertNull($first['subcommittee']);
        $this->assertSame('committee', $first['event_type']);
        $this->assertSame('2026-01-15', $first['meeting_date']);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $first['video_url']);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $first['youtube_url']);
        $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', $first['embed_url']);
        $this->assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $first['thumbnail_url']);
        $this->assertSame(5025, $first['duration_seconds']); // 1h 23m 45s = 5025 seconds

        // Test second record (Floor Session)
        $second = $records[1];
        $this->assertSame('abc123def456', $second['video_id']);
        $this->assertNull($second['committee_name']);
        $this->assertSame('floor', $second['event_type']);
        $this->assertSame('2026-01-14', $second['meeting_date']);
        $this->assertSame(8130, $second['duration_seconds']); // 2h 15m 30s = 8130 seconds

        // Test third record (Subcommittee)
        $third = $records[2];
        $this->assertSame('xyz789ghi012', $third['video_id']);
        $this->assertSame('Commerce and Labor', $third['committee']);
        $this->assertSame('Subcommittee on Workers\' Rights', $third['subcommittee']);
        $this->assertSame('subcommittee', $third['event_type']);
        $this->assertSame('2026-01-13', $third['meeting_date']);
        $this->assertSame(2712, $third['duration_seconds']); // 45m 12s = 2712 seconds
    }

    public function testReturnsEmptyArrayWhenApiKeyNotConfigured(): void
    {
        $client = new FakeHttpClient([]);
        $scraper = new SenateYouTubeScraper($client, '');
        $records = $scraper->scrape();

        $this->assertEmpty($records);
    }

    public function testExtractsCommitteeNamesFromTitles(): void
    {
        $client = new FakeHttpClient([
            'search?part=id,snippet&channelId=' => file_get_contents(__DIR__ . '/../fixtures/youtube-live-videos.json'),
            'videos?part=snippet,contentDetails&id=' => file_get_contents(__DIR__ . '/../fixtures/youtube-video-details.json'),
        ]);

        $scraper = new SenateYouTubeScraper($client, 'fake-api-key');
        $records = $scraper->scrape();

        // Finance Committee
        $this->assertSame('Finance Committee', $records[0]['committee']);
        $this->assertNull($records[0]['subcommittee']);

        // Senate Floor Session — committee_name is cleared for floor sessions
        $this->assertNull($records[1]['committee_name']);
        $this->assertNull($records[1]['subcommittee']);

        // Commerce and Labor with Subcommittee
        $this->assertSame('Commerce and Labor', $records[2]['committee']);
        $this->assertSame('Subcommittee on Workers\' Rights', $records[2]['subcommittee']);
    }

    public function testExtractsDatesFromTitles(): void
    {
        $client = new FakeHttpClient([
            'search?part=id,snippet&channelId=' => file_get_contents(__DIR__ . '/../fixtures/youtube-live-videos.json'),
            'videos?part=snippet,contentDetails&id=' => file_get_contents(__DIR__ . '/../fixtures/youtube-video-details.json'),
        ]);

        $scraper = new SenateYouTubeScraper($client, 'fake-api-key');
        $records = $scraper->scrape();

        // Date extracted from title "January 15, 2026"
        $this->assertSame('2026-01-15', $records[0]['meeting_date']);

        // Date extracted from title "1/14/26"
        $this->assertSame('2026-01-14', $records[1]['meeting_date']);

        // Date falls back to published_at since not in title
        $this->assertSame('2026-01-13', $records[2]['meeting_date']);
    }

    public function testMaxRecordsLimit(): void
    {
        $client = new FakeHttpClient([
            'search?part=id,snippet&channelId=' => file_get_contents(__DIR__ . '/../fixtures/youtube-live-videos.json'),
            'videos?part=snippet,contentDetails&id=' => file_get_contents(__DIR__ . '/../fixtures/youtube-video-details.json'),
        ]);

        $scraper = new SenateYouTubeScraper($client, 'fake-api-key', maxRecords: 2);
        $records = $scraper->scrape();

        $this->assertCount(2, $records);
    }
}
