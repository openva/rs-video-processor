<?php

namespace RichmondSunlight\VideoProcessor\Tests\Scraper;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Scraper\YouTube\YouTubeApiClient;
use RichmondSunlight\VideoProcessor\Tests\Support\FakeHttpClient;

class YouTubeApiClientTest extends TestCase
{
    public function testFetchLiveVideos(): void
    {
        $client = new FakeHttpClient([
            'search?part=id,snippet&channelId=' => file_get_contents(__DIR__ . '/../fixtures/youtube-live-videos.json'),
        ]);

        $apiClient = new YouTubeApiClient($client, 'fake-api-key');
        $videos = $apiClient->fetchLiveVideos('UC0fC9dFvlYA0OTmz2xBWZ4g', 50);

        $this->assertCount(3, $videos);
        $this->assertSame('dQw4w9WgXcQ', $videos[0]['id']['videoId']);
        $this->assertSame('Finance Committee - January 15, 2026 - Budget Hearing', $videos[0]['snippet']['title']);
        $this->assertSame(100, $apiClient->getQuotaUsed()); // search.list costs 100 units
    }

    public function testFetchVideoDetails(): void
    {
        $client = new FakeHttpClient([
            'videos?part=snippet,contentDetails&id=' => file_get_contents(__DIR__ . '/../fixtures/youtube-video-details.json'),
        ]);

        $apiClient = new YouTubeApiClient($client, 'fake-api-key');
        $details = $apiClient->fetchVideoDetails(['dQw4w9WgXcQ', 'abc123def456', 'xyz789ghi012']);

        $this->assertCount(3, $details);
        $this->assertArrayHasKey('dQw4w9WgXcQ', $details);
        $this->assertSame('PT1H23M45S', $details['dQw4w9WgXcQ']['contentDetails']['duration']);
        $this->assertSame(3, $apiClient->getQuotaUsed()); // videos.list costs 1 unit per video
    }

    public function testParseIsoDuration(): void
    {
        $client = new FakeHttpClient([]);
        $apiClient = new YouTubeApiClient($client, 'fake-api-key');

        // Test 1 hour 23 minutes 45 seconds
        $this->assertSame(5025, $apiClient->parseIsoDuration('PT1H23M45S'));

        // Test 2 hours 15 minutes 30 seconds
        $this->assertSame(8130, $apiClient->parseIsoDuration('PT2H15M30S'));

        // Test 45 minutes 12 seconds
        $this->assertSame(2712, $apiClient->parseIsoDuration('PT45M12S'));

        // Test 30 seconds only
        $this->assertSame(30, $apiClient->parseIsoDuration('PT30S'));

        // Test 1 hour only
        $this->assertSame(3600, $apiClient->parseIsoDuration('PT1H'));

        // Test 15 minutes only
        $this->assertSame(900, $apiClient->parseIsoDuration('PT15M'));

        // Test invalid format
        $this->assertNull($apiClient->parseIsoDuration('invalid'));
        $this->assertNull($apiClient->parseIsoDuration('1H23M45S'));
    }

    public function testQuotaTracking(): void
    {
        $client = new FakeHttpClient([
            'search?part=id,snippet&channelId=' => file_get_contents(__DIR__ . '/../fixtures/youtube-live-videos.json'),
            'videos?part=snippet,contentDetails&id=' => file_get_contents(__DIR__ . '/../fixtures/youtube-video-details.json'),
        ]);

        $apiClient = new YouTubeApiClient($client, 'fake-api-key');

        $apiClient->fetchLiveVideos('UC0fC9dFvlYA0OTmz2xBWZ4g', 50);
        $this->assertSame(100, $apiClient->getQuotaUsed());

        $apiClient->fetchVideoDetails(['dQw4w9WgXcQ', 'abc123def456']);
        $this->assertSame(102, $apiClient->getQuotaUsed()); // 100 + 2
    }

    public function testEmptyVideoIds(): void
    {
        $client = new FakeHttpClient([]);
        $apiClient = new YouTubeApiClient($client, 'fake-api-key');

        $details = $apiClient->fetchVideoDetails([]);
        $this->assertEmpty($details);
        $this->assertSame(0, $apiClient->getQuotaUsed());
    }
}
