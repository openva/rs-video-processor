<?php

namespace RichmondSunlight\VideoProcessor\Tests\Sync;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Sync\VideoImporter;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class VideoImporterTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            committee_id INTEGER,
            title TEXT,
            description TEXT,
            type TEXT,
            length TEXT,
            date TEXT,
            sponsor TEXT,
            width INTEGER,
            height INTEGER,
            fps REAL,
            capture_rate INTEGER,
            capture_directory TEXT,
            path TEXT,
            html TEXT,
            author_name TEXT,
            license TEXT,
            date_created TEXT,
            date_modified TEXT,
            video_index_cache TEXT,
            raw_metadata TEXT
        )');
        $this->pdo->exec('CREATE TABLE committees (
            id INTEGER PRIMARY KEY,
            name TEXT,
            shortname TEXT,
            chamber TEXT,
            parent_id INTEGER
        )');
        $this->pdo->exec("INSERT INTO committees (id, name, shortname, chamber, parent_id) VALUES (1, 'Appropriations', 'finance', 'house', NULL)");
        TestDatabaseFactory::addPipelineTables($this->pdo);
    }

    public function testInsertsRecords(): void
    {
        $directory = new CommitteeDirectory($this->pdo);
        $importer = new VideoImporter($this->pdo, $directory);
        $count = $importer->import([
            [
                'chamber' => 'house',
                'title' => 'Appropriations',
                'meeting_date' => '2025-01-31',
                'duration_seconds' => 3300,
                'committee_name' => 'Appropriations',
                'event_type' => 'committee',
                'video_url' => 'https://example.com/video.mp4',
            ],
        ]);

        $this->assertSame(1, $count);

        $rows = $this->pdo->query('SELECT chamber, title, date, length, sponsor, video_index_cache FROM files')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('house', $rows[0]['chamber']);
        $this->assertSame('House Appropriations', $rows[0]['title']);
        $this->assertSame('2025-01-31', $rows[0]['date']);
        $this->assertSame('00:55:00', $rows[0]['length']);
        $this->assertNull($rows[0]['sponsor']);  // sponsor field is no longer populated
        $this->assertNotEmpty($rows[0]['video_index_cache']);
    }

    public function testGeneratesFloorSessionTitles(): void
    {
        $directory = new CommitteeDirectory($this->pdo);
        $importer = new VideoImporter($this->pdo, $directory);
        $count = $importer->import([
            [
                'chamber' => 'senate',
                'title' => 'Floor Session',
                'meeting_date' => '2025-02-15',
                'duration_seconds' => 7200,
                'event_type' => 'floor',
                'video_url' => 'https://example.com/floor.mp4',
            ],
        ]);

        $this->assertSame(1, $count);

        $row = $this->pdo->query('SELECT title FROM files')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Senate Session', $row['title']);
    }

    public function testSkipsInvalidRecords(): void
    {
        $directory = new CommitteeDirectory($this->pdo);
        $importer = new VideoImporter($this->pdo, $directory);
        $count = $importer->import([
            [
                'chamber' => 'senate',
                'title' => 'Missing Duration',
                'meeting_date' => '2025-11-19',
            ],
        ]);

        $this->assertSame(0, $count);
        $rows = $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
        $this->assertSame(0, (int) $rows);
    }

    public function testSkipsDuplicates(): void
    {
        $directory = new CommitteeDirectory($this->pdo);
        $importer = new VideoImporter($this->pdo, $directory);

        // Import first video
        $count1 = $importer->import([
            [
                'chamber' => 'house',
                'title' => 'Finance Meeting',
                'meeting_date' => '2025-03-15',
                'duration_seconds' => 3600,
                'committee_name' => 'Appropriations',
                'event_type' => 'committee',
                'video_url' => 'https://example.com/video1.mp4',
            ],
        ]);

        $this->assertSame(1, $count1);

        // Try to import duplicate (same chamber, date, committee)
        $count2 = $importer->import([
            [
                'chamber' => 'house',
                'title' => 'Finance Meeting',
                'meeting_date' => '2025-03-15',
                'duration_seconds' => 3605, // Slightly different duration
                'committee_name' => 'Appropriations',
                'event_type' => 'committee',
                'video_url' => 'https://example.com/video2.mp4', // Different URL
            ],
        ]);

        $this->assertSame(0, $count2); // Should be skipped
        $rows = $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
        $this->assertSame(1, (int) $rows); // Only one record should exist
    }

    public function testSetsCommitteeIdZeroForUnresolvedCommittees(): void
    {
        $directory = new CommitteeDirectory($this->pdo);
        $importer = new VideoImporter($this->pdo, $directory);

        $importer->import([[
            'chamber' => 'senate',
            'meeting_date' => '2025-03-15',
            'duration_seconds' => 3600,
            'committee_name' => 'Subcommittee Room 300',   // no DB match
            'event_type' => 'committee',
            'video_url' => 'https://www.youtube.com/watch?v=aaa111',
        ]]);

        $row = $this->pdo->query('SELECT committee_id FROM files')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $row['committee_id']);
    }

    public function testDistinctUnresolvedCommitteesOnSameDayBothInsert(): void
    {
        $directory = new CommitteeDirectory($this->pdo);
        $importer = new VideoImporter($this->pdo, $directory);

        $count1 = $importer->import([[
            'chamber' => 'senate',
            'meeting_date' => '2025-03-15',
            'duration_seconds' => 3600,
            'committee_name' => 'Subcommittee Room 300',
            'event_type' => 'committee',
            'video_url' => 'https://www.youtube.com/watch?v=aaa111',
        ]]);

        $count2 = $importer->import([[
            'chamber' => 'senate',
            'meeting_date' => '2025-03-15',
            'duration_seconds' => 5400,
            'committee_name' => 'Conference Room B',
            'event_type' => 'committee',
            'video_url' => 'https://www.youtube.com/watch?v=bbb222',  // different URL
        ]]);

        $this->assertSame(1, $count1);
        $this->assertSame(1, $count2);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testSameUnresolvedCommitteeImportedTwiceIsDeduped(): void
    {
        $directory = new CommitteeDirectory($this->pdo);
        $importer = new VideoImporter($this->pdo, $directory);

        $url = 'https://www.youtube.com/watch?v=aaa111';

        $count1 = $importer->import([[
            'chamber' => 'senate',
            'meeting_date' => '2025-03-15',
            'duration_seconds' => 3600,
            'committee_name' => 'Subcommittee Room 300',
            'event_type' => 'committee',
            'video_url' => $url,
        ]]);

        $count2 = $importer->import([[
            'chamber' => 'senate',
            'meeting_date' => '2025-03-15',
            'duration_seconds' => 3600,
            'committee_name' => 'Subcommittee Room 300',
            'event_type' => 'committee',
            'video_url' => $url,  // same URL = same video
        ]]);

        $this->assertSame(1, $count1);
        $this->assertSame(0, $count2);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testStoresRawMetadataWithoutAgendaAndSpeakers(): void
    {
        $directory = new CommitteeDirectory($this->pdo);
        $importer = new VideoImporter($this->pdo, $directory);

        $count = $importer->import([
            [
                'chamber' => 'house',
                'title' => 'Finance Committee',
                'meeting_date' => '2025-03-20',
                'duration_seconds' => 3600,
                'committee_name' => 'Appropriations',
                'event_type' => 'committee',
                'video_url' => 'https://example.com/video.mp4',
                'agenda' => [
                    ['item' => 'HB1234', 'title' => 'Budget Bill'],
                    ['item' => 'HB5678', 'title' => 'Tax Reform'],
                ],
                'speakers' => [
                    ['name' => 'Delegate Smith', 'start_time' => '10:00:00'],
                    ['name' => 'Delegate Jones', 'start_time' => '10:15:00'],
                ],
            ],
        ]);

        $this->assertSame(1, $count);

        $row = $this->pdo->query('SELECT video_index_cache, raw_metadata FROM files')->fetch(PDO::FETCH_ASSOC);

        // video_index_cache should contain everything (including agenda and speakers)
        $indexCache = json_decode($row['video_index_cache'], true);
        $this->assertArrayHasKey('agenda', $indexCache);
        $this->assertArrayHasKey('speakers', $indexCache);
        $this->assertCount(2, $indexCache['agenda']);
        $this->assertCount(2, $indexCache['speakers']);

        // raw_metadata should exclude agenda and speakers
        $rawMetadata = json_decode($row['raw_metadata'], true);
        $this->assertArrayNotHasKey('agenda', $rawMetadata);
        $this->assertArrayNotHasKey('speakers', $rawMetadata);

        // But should still contain other fields
        $this->assertArrayHasKey('chamber', $rawMetadata);
        $this->assertArrayHasKey('video_url', $rawMetadata);
        $this->assertArrayHasKey('committee_name', $rawMetadata);
        $this->assertSame('house', $rawMetadata['chamber']);
        $this->assertSame('https://example.com/video.mp4', $rawMetadata['video_url']);
    }

    public function testSameSourceIdIsNotReimportedAfterPathChanges(): void
    {
        $importer = new VideoImporter($this->pdo, new CommitteeDirectory($this->pdo));
        $record = [
            'source' => 'house',
            'content_id' => 555,
            'chamber' => 'house',
            'meeting_date' => '2026-08-17',
            'duration_seconds' => 3600,
            'committee_name' => 'Joint Subcommittee on Something Unlisted',
            'event_type' => 'committee',
            'video_url' => 'https://sg001-harmony.sliq.net/00304/Harmony/en/x.mp4',
        ];

        $this->assertSame(1, $importer->import([$record]));

        // What the download stage does: path becomes the S3 URL, length comes from ffprobe.
        $this->pdo->exec(
            "UPDATE files SET path = 'https://video.richmondsunlight.com/house/floor/20260817-1.mp4', length = '01:00:07'"
        );

        $this->assertSame(0, $importer->import([$record]));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testSecondMeetingOfOneCommitteeOnOneDayIsImported(): void
    {
        $importer = new VideoImporter($this->pdo, new CommitteeDirectory($this->pdo));
        $base = [
            'source' => 'house',
            'chamber' => 'house',
            'meeting_date' => '2026-02-10',
            'committee_name' => 'Appropriations',
            'event_type' => 'committee',
        ];

        $morning = $base + ['content_id' => 1, 'duration_seconds' => 3600, 'video_url' => 'https://example.com/1.mp4'];
        $evening = $base + ['content_id' => 2, 'duration_seconds' => 9000, 'video_url' => 'https://example.com/2.mp4'];

        $this->assertSame(2, $importer->import([$morning, $evening]));
    }

    public function testSecondFloorSessionOnOneDayIsImported(): void
    {
        $importer = new VideoImporter($this->pdo, new CommitteeDirectory($this->pdo));
        $base = ['source' => 'house', 'chamber' => 'house', 'meeting_date' => '2026-02-10', 'event_type' => 'floor'];

        $first = $base + ['content_id' => 3, 'duration_seconds' => 1800, 'video_url' => 'https://example.com/3.mp4'];
        $second = $base + ['content_id' => 4, 'duration_seconds' => 7200, 'video_url' => 'https://example.com/4.mp4'];

        $this->assertSame(2, $importer->import([$first, $second]));
    }

    public function testSameMeetingFromTwoSourcesIsImportedOnce(): void
    {
        $importer = new VideoImporter($this->pdo, new CommitteeDirectory($this->pdo));
        $base = [
            'chamber' => 'house',
            'meeting_date' => '2026-02-10',
            'committee_name' => 'Appropriations',
            'event_type' => 'committee',
        ];

        $one = $base + ['source' => 'house', 'content_id' => 7, 'duration_seconds' => 3600, 'video_url' => 'https://example.com/7.mp4'];
        $two = $base + ['source' => 'other', 'video_id' => 'abc', 'duration_seconds' => 3700, 'video_url' => 'https://example.com/abc.mp4'];

        $this->assertSame(1, $importer->import([$one]));
        $this->assertSame(0, $importer->import([$two]));

        // Both IDs now point at the one row, so neither is looked up by guesswork again.
        $owners = $this->pdo->query('SELECT file_id FROM video_sources ORDER BY source')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([1, 1], array_map('intval', $owners));
    }

    public function testOneBadRecordDoesNotAbortTheBatch(): void
    {
        $this->pdo->exec(
            "CREATE TRIGGER reject_boom BEFORE INSERT ON files WHEN NEW.description = 'BOOM'
             BEGIN SELECT RAISE(ABORT, 'boom'); END"
        );
        $importer = new VideoImporter($this->pdo, new CommitteeDirectory($this->pdo));
        $base = ['source' => 'house', 'chamber' => 'house', 'event_type' => 'floor', 'duration_seconds' => 600];

        $bad = $base + ['content_id' => 8, 'meeting_date' => '2026-03-01', 'description' => 'BOOM', 'video_url' => 'https://example.com/8.mp4'];
        $good = $base + ['content_id' => 9, 'meeting_date' => '2026-03-02', 'video_url' => 'https://example.com/9.mp4'];

        $this->assertSame(1, $importer->import([$bad, $good]));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }
}
