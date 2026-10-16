<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Metadata;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Metadata\MetadataIndexer;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerMetadataExtractor;
use RichmondSunlight\VideoProcessor\Scraper\House\HouseScraper;
use RichmondSunlight\VideoProcessor\Tests\Support\FakeHttpClient;

/**
 * House speaker timestamps are wall-clock times. They must be measured from
 * the start of the recording, which often begins long before anyone speaks:
 * the 2025-02-12 floor recording starts at 11:46:08 and its first delegate
 * speaks at 12:04:17. Measuring from the first speaker put every speaker
 * that much too early in the video.
 */
class SpeakerTimelineTest extends TestCase
{
    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY, file_id INTEGER, time TEXT, screenshot TEXT,
            raw_text TEXT, type TEXT, linked_id INTEGER, ignored TEXT, date_created TEXT
        )');
        return $pdo;
    }

    /** As the House scraper records it: the page labels a local time "+00:00". */
    private function metadata(array $extra = []): array
    {
        return $extra + [
            'actual_start' => '2025-02-12T11:46:08.000+00:00',
            'speakers' => [
                ['name' => 'Del. Chad Green', 'start_time' => '2025-02-12T12:04:17.343'],
                ['name' => 'Del. Sam Rasoul', 'start_time' => '2025-02-12T12:05:47.42'],
            ],
        ];
    }

    public function testIndexerMeasuresFromTheStartOfTheRecording(): void
    {
        $pdo = $this->pdo();
        (new MetadataIndexer($pdo))->index(1, $this->metadata());

        $rows = $pdo->query('SELECT raw_text, time, screenshot FROM video_index ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(
            [
                ['raw_text' => 'Del. Chad Green', 'time' => '00:18:09', 'screenshot' => '00001090'],
                ['raw_text' => 'Del. Sam Rasoul', 'time' => '00:19:39', 'screenshot' => '00001180'],
            ],
            $rows
        );
    }

    public function testMediaStartIsPreferredOverMeetingStart(): void
    {
        $pdo = $this->pdo();
        // The recording began at 11:40:00, six minutes before the meeting's recorded start.
        (new MetadataIndexer($pdo))->index(1, $this->metadata(['media_start' => '2025-02-12T11:40:00']));

        $this->assertSame('00:24:17', $pdo->query('SELECT time FROM video_index ORDER BY id LIMIT 1')->fetchColumn());
    }

    /** A recorded start after someone has already spoken cannot be the recording's start. */
    public function testStartAfterTheFirstSpeakerFallsBackToTheFirstSpeaker(): void
    {
        $pdo = $this->pdo();
        (new MetadataIndexer($pdo))->index(1, $this->metadata(['actual_start' => '2025-02-12T12:30:00']));

        $this->assertSame('00:00:00', $pdo->query('SELECT time FROM video_index ORDER BY id LIMIT 1')->fetchColumn());
    }

    /** Re-indexing must not lose the legislator each name was already matched to. */
    public function testReindexingKeepsMatchedLegislators(): void
    {
        $pdo = $this->pdo();
        $indexer = new MetadataIndexer($pdo);
        $indexer->index(1, $this->metadata());
        $pdo->exec("UPDATE video_index SET linked_id = 77 WHERE raw_text = 'Del. Chad Green'");

        $indexer->index(1, $this->metadata());

        $this->assertSame(77, (int) $pdo->query("SELECT linked_id FROM video_index WHERE raw_text = 'Del. Chad Green'")->fetchColumn());
        $this->assertNull($pdo->query("SELECT linked_id FROM video_index WHERE raw_text = 'Del. Sam Rasoul'")->fetchColumn());
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM video_index')->fetchColumn());
    }

    public function testSpeakerExtractorMeasuresFromTheStartOfTheRecording(): void
    {
        $segments = (new SpeakerMetadataExtractor())->extract($this->metadata());

        $this->assertSame(1089.0, $segments[0]['start']);
        $this->assertSame(1179.0, $segments[1]['start']);
    }

    public function testScraperRecordsWhenTheRecordingStarted(): void
    {
        $client = new FakeHttpClient([
            'GetListViewData' => file_get_contents(__DIR__ . '/../../fixtures/house-listing.json'),
            'PowerBrowserV2' => file_get_contents(__DIR__ . '/../../fixtures/house-committee-video.html'),
        ]);
        $records = (new HouseScraper($client, 'https://example.test/00304/Harmony'))->scrape();

        $this->assertSame('2025-01-31T13:40:09', $records[0]['media_start']);
    }

    /**
     * A meeting's recorded start is not always the recording's start (a meeting
     * split across several recordings, say). A start that would put a speaker
     * past the end of the video cannot be right, and is not used.
     */
    public function testStartThatPutsSpeakersPastTheEndIsIgnored(): void
    {
        $metadata = [
            'actual_start' => '2025-02-12T10:00:00',  // 102 minutes before the first speaker
            'duration_seconds' => 6000,                // a 100-minute video
            'speakers' => [
                ['name' => 'Del. Chad Green', 'start_time' => '2025-02-12T11:42:00'],
                ['name' => 'Del. Sam Rasoul', 'start_time' => '2025-02-12T11:50:00'],
            ],
        ];

        $segments = (new SpeakerMetadataExtractor())->extract($metadata);

        $this->assertSame(0.0, $segments[0]['start'], 'Falls back to measuring from the first speaker.');
        $this->assertSame(480.0, $segments[1]['start']);
    }

    public function testStartThatKeepsSpeakersInsideTheVideoIsUsed(): void
    {
        // The fixture recording: 7,600 seconds long, speakers 18 and 19 minutes in.
        $segments = (new SpeakerMetadataExtractor())->extract($this->metadata(['duration_seconds' => 7600]));

        $this->assertSame(1089.0, $segments[0]['start']);
    }
}
