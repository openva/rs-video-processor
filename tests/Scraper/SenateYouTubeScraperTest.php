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

    /**
     * @dataProvider realYouTubeTitlesProvider
     */
    public function testClassifiesRealYouTubeTitles(
        string $title,
        string $expectedEventType,
        ?string $expectedCommittee,
        ?string $expectedSubcommittee
    ): void {
        $client = new FakeHttpClient([]);
        $scraper = new SenateYouTubeScraper($client, 'fake-api-key');

        $result = $scraper->classifyTitle($title);

        $this->assertSame($expectedEventType, $result['event_type'], "Event type mismatch for: {$title}");
        $this->assertSame($expectedCommittee, $result['committee'], "Committee mismatch for: {$title}");
        $this->assertSame($expectedSubcommittee, $result['subcommittee'], "Subcommittee mismatch for: {$title}");
    }

    public static function realYouTubeTitlesProvider(): array
    {
        return [
            // --- Floor sessions (Senate Chamber) ---
            'floor: Senate Chamber 2026-02-23' => [
                'Senate of Virginia: Senate Chamber on 2026-02-23',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-02-23 [Finished]' => [
                'Senate of Virginia: Senate Chamber on 2026-02-23 [Finished]',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-02-20' => [
                'Senate of Virginia: Senate Chamber on 2026-02-20 [Finished]',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-02-19' => [
                'Senate of Virginia: Senate Chamber on 2026-02-19',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-02-18' => [
                'Senate of Virginia: Senate Chamber on 2026-02-18',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-02-17' => [
                'Senate of Virginia: Senate Chamber on 2026-02-17 [Finished]',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-02-16' => [
                'Senate of Virginia: Senate Chamber on 2026-02-16 [Finished]',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-02-13' => [
                'Senate of Virginia: Senate Chamber on 2026-02-13',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-02-12' => [
                'Senate of Virginia: Senate Chamber on 2026-02-12',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-02-10' => [
                'Senate of Virginia: Senate Chamber on 2026-02-10',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-02-09' => [
                'Senate of Virginia: Senate Chamber on 2026-02-09',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-02-06' => [
                'Senate of Virginia: Senate Chamber on 2026-02-06',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-02-04' => [
                'Senate of Virginia: Senate Chamber on 2026-02-04',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-02-03' => [
                'Senate of Virginia: Senate Chamber on 2026-02-03',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-01-30' => [
                'Senate of Virginia: Senate Chamber on 2026-01-30',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-01-29' => [
                'Senate of Virginia: Senate Chamber on 2026-01-29',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-01-28' => [
                'Senate of Virginia: Senate Chamber on 2026-01-28',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-01-27' => [
                'Senate of Virginia: Senate Chamber on 2026-01-27',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-01-23' => [
                'Senate of Virginia: Senate Chamber on 2026-01-23 [Finished]',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2026-01-17' => [
                'Senate of Virginia: Senate Chamber on 2026-01-17 [Finished]',
                'floor', 'Senate Chamber', null,
            ],
            'floor: Senate Chamber 2020-02-28' => [
                'Senate of Virginia: Senate Chamber on 2020-02-28 [Archival]',
                'floor', 'Senate Chamber', null,
            ],

            // --- Floor sessions (dash-separated format) ---
            'floor: Senate Session (plain)' => [
                'Senate Session',
                'floor', 'Senate Session', null,
            ],

            // --- Committees ---
            'committee: Courts of Justice' => [
                'Senate of Virginia: Courts of Justice on 2026-02-23',
                'committee', 'Courts of Justice', null,
            ],
            'committee: Courts of Justice [Finished]' => [
                'Senate of Virginia: Courts of Justice on 2026-02-23 [Finished]',
                'committee', 'Courts of Justice', null,
            ],
            'committee: Commerce and Labor' => [
                'Senate of Virginia: Commerce and Labor on 2026-02-23 [Finished]',
                'committee', 'Commerce and Labor', null,
            ],
            'committee: Local Government' => [
                'Senate of Virginia: Local Government on 2026-02-23 [Finished]',
                'committee', 'Local Government', null,
            ],
            'committee: Finance and Appropriations' => [
                'Senate of Virginia: Finance and Appropriations on 2026-02-22 [Finished]',
                'committee', 'Finance and Appropriations', null,
            ],
            'committee: Rehabilitation and Social Services' => [
                'Senate of Virginia: Rehabilitation and Social Services on 2026-02-20 [Finished]',
                'committee', 'Rehabilitation and Social Services', null,
            ],
            'committee: Education and Health' => [
                'Senate of Virginia: Education and Health on 2026-01-22 [Finished]',
                'committee', 'Education and Health', null,
            ],
            'committee: General Laws and Technology' => [
                'Senate of Virginia: General Laws and Technology on 2026-01-21',
                'committee', 'General Laws and Technology', null,
            ],
            'committee: Agriculture' => [
                'Senate of Virginia: Agriculture, Conservation and Natural Resources on 2026-02-10',
                'committee', 'Agriculture, Conservation and Natural Resources', null,
            ],
            'committee: Privileges and Elections' => [
                'Senate of Virginia: Privileges and Elections on 2026-01-14 [Finished]',
                'committee', 'Privileges and Elections', null,
            ],
            'committee: Transportation' => [
                'Senate of Virginia: Transportation on 2021-02-18 [Archival]',
                'committee', 'Transportation', null,
            ],
            'committee: Rules' => [
                'Senate of Virginia: Rules on 2020-01-31 [Archival]',
                'committee', 'Rules', null,
            ],
            'committee: Administrative Law Advisory Committee' => [
                'Senate of Virginia: Administrative Law Advisory Committee on 2025-09-16 [Finished]',
                'committee', 'Administrative Law Advisory Committee', null,
            ],
            'committee: Commerce and Labor [Archival]' => [
                'Senate of Virginia: Commerce and Labor on 2020-02-17 [Archival]',
                'committee', 'Commerce and Labor', null,
            ],
            'committee: Finance and Appropriations [Archival]' => [
                'Senate of Virginia: Finance and Appropriations on 2020-01-29 [Archival]',
                'committee', 'Finance and Appropriations', null,
            ],

            // --- Subcommittees (colon-separated parent: child) ---
            'subcommittee: Education & Health: Higher Education' => [
                'Senate of Virginia: Education & Health: Higher Education on 2026-02-23 [Finished]',
                'subcommittee', 'Education & Health', 'Higher Education',
            ],
            'subcommittee: Education & Health: Health Professions' => [
                'Senate of Virginia: Education & Health: Health Professions on 2026-02-20 [Finished]',
                'subcommittee', 'Education & Health', 'Health Professions',
            ],
            'subcommittee: Education & Health: Public Education' => [
                'Senate of Virginia: Education & Health: Public Education on 2026-01-15 [Finished]',
                'subcommittee', 'Education & Health', 'Public Education',
            ],
            'subcommittee: Education & Health: Health' => [
                'Senate of Virginia: Education & Health: Health on 2026-01-20 [Finished]',
                'subcommittee', 'Education & Health', 'Health',
            ],
            'subcommittee: SFAC: Capital Outlay & Transportation' => [
                'Senate of Virginia: SFAC: Capital Outlay & Transportation Subcommittee on 2026-01-16 [Finished]',
                'subcommittee', 'SFAC', 'Capital Outlay & Transportation Subcommittee',
            ],
            'subcommittee: SFAC: Public Safety & Claims' => [
                'Senate of Virginia: SFAC: Public Safety & Claims Subcommittee on 2026-01-12 [Finished]',
                'subcommittee', 'SFAC', 'Public Safety & Claims Subcommittee',
            ],
            'subcommittee: SFAC: Economic Development & Natural Resources' => [
                'Senate of Virginia: SFAC: Economic Development & Natural Resources on 2026-02-02 [Finished]',
                'subcommittee', 'SFAC', 'Economic Development & Natural Resources',
            ],
            'subcommittee: Finance and Appropriations [Archival]' => [
                'Senate of Virginia: Finance and Appropriations on 2022-03-03 [Archival]',
                'committee', 'Finance and Appropriations', null,
            ],

            // --- Edge cases (no "Senate of Virginia:" prefix) ---
            'edge: Joint Subcommittee' => [
                'Senate Joint Subcommittee on Costal Flooding on 2021-11-22 [Archival]',
                'committee', 'Senate Joint Subcommittee on Costal Flooding', null,
            ],

            // --- Noise-prefixed titles (leading junk before "Senate of Virginia:") ---
            'noise-prefix: TEST: before Senate of Virginia' => [
                'TEST: Senate of Virginia: Subcommittee Room 300 on 2026-02-27 [Finished]',
                'committee', 'Subcommittee Room 300', null,
            ],

            // --- Dash-separated format (existing style) ---
            'dash: Finance Committee' => [
                'Finance Committee - January 15, 2026 - Budget Hearing',
                'committee', 'Finance Committee', null,
            ],
            'dash: Senate Floor Session' => [
                'Senate Floor Session - 1/14/26',
                'floor', 'Senate Floor Session', null,
            ],
            'dash: Commerce and Labor subcommittee' => [
                'Commerce and Labor - Subcommittee on Workers\' Rights - Meeting',
                'subcommittee', 'Commerce and Labor', 'Subcommittee on Workers\' Rights',
            ],
        ];
    }
}
