<?php

namespace RichmondSunlight\VideoProcessor\Tests\Sync;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Sync\VideoImporter;

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
            author_name TEXT,
            license TEXT,
            date_created TEXT,
            date_modified TEXT,
            video_index_cache TEXT
        )');
        $this->pdo->exec('CREATE TABLE committees (
            id INTEGER PRIMARY KEY,
            name TEXT,
            shortname TEXT,
            chamber TEXT,
            parent_id INTEGER
        )');
        $this->pdo->exec("INSERT INTO committees (id, name, shortname, chamber, parent_id) VALUES (1, 'Appropriations', 'finance', 'house', NULL)");
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
        $this->assertSame('Appropriations', $rows[0]['sponsor']);
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
}
