<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Classification;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Classification\ClassificationCorrectionWriter;

class ClassificationCorrectionWriterTest extends TestCase
{
    private function createDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            committee_id INTEGER,
            capture_directory TEXT,
            video_index_cache TEXT,
            title TEXT,
            date TEXT,
            date_created TEXT
        )');
        return $pdo;
    }

    public function testCorrectUpdatesAllFields(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec('INSERT INTO files (chamber, committee_id, capture_directory, video_index_cache, title, date, date_created) VALUES ("house", NULL, "/house/floor/20250115/", \'{"event_type":"floor"}\', "House Session", "20250115", "2025-01-15")');

        $writer = new ClassificationCorrectionWriter($pdo);
        $writer->correct(1, 42, 'House Agriculture', 'committee', ['event_type' => 'floor']);

        $row = $pdo->query('SELECT committee_id, title, video_index_cache FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(42, (int) $row['committee_id']);
        $this->assertSame('House Agriculture', $row['title']);

        $cache = json_decode($row['video_index_cache'], true);
        $this->assertSame('committee', $cache['event_type']);
        $this->assertTrue($cache['classification_verified']);
    }

    public function testMarkVerifiedSetsFlag(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec('INSERT INTO files (chamber, committee_id, capture_directory, video_index_cache, title, date, date_created) VALUES ("house", NULL, "/house/floor/20250115/", \'{"event_type":"floor"}\', "House Session", "20250115", "2025-01-15")');

        $writer = new ClassificationCorrectionWriter($pdo);
        $writer->markVerified(1, ['event_type' => 'floor']);

        $row = $pdo->query('SELECT video_index_cache FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $cache = json_decode($row['video_index_cache'], true);
        $this->assertTrue($cache['classification_verified']);
        $this->assertSame('floor', $cache['event_type']);
    }

    public function testCorrectWithNullExistingCache(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec('INSERT INTO files (chamber, committee_id, capture_directory, video_index_cache, title, date, date_created) VALUES ("house", NULL, "/house/floor/20250115/", NULL, "House Session", "20250115", "2025-01-15")');

        $writer = new ClassificationCorrectionWriter($pdo);
        $writer->correct(1, 10, 'House Education', 'committee', null);

        $row = $pdo->query('SELECT committee_id, title, video_index_cache FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(10, (int) $row['committee_id']);
        $this->assertSame('House Education', $row['title']);

        $cache = json_decode($row['video_index_cache'], true);
        $this->assertSame('committee', $cache['event_type']);
        $this->assertTrue($cache['classification_verified']);
    }

    public function testCorrectSetsNullCommitteeIdForFloor(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec('INSERT INTO files (chamber, committee_id, capture_directory, video_index_cache, title, date, date_created) VALUES ("house", 42, "/house/committee/20250115/", \'{"event_type":"committee"}\', "House Agriculture", "20250115", "2025-01-15")');

        $writer = new ClassificationCorrectionWriter($pdo);
        $writer->correct(1, null, 'House Session', 'floor', ['event_type' => 'committee']);

        $row = $pdo->query('SELECT committee_id, title FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['committee_id']);
        $this->assertSame('House Session', $row['title']);
    }
}
