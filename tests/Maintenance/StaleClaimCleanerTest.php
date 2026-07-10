<?php

namespace RichmondSunlight\VideoProcessor\Tests\Maintenance;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Maintenance\StaleClaimCleaner;

class StaleClaimCleanerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            capture_directory TEXT,
            capture_rate INTEGER,
            date_modified TEXT
        )');
        $this->pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            time TEXT,
            screenshot TEXT,
            raw_text TEXT,
            type TEXT,
            linked_id INTEGER,
            ignored TEXT,
            date_created TEXT
        )');
    }

    public function testResetsStaleScreenshotClaimsOnly(): void
    {
        // Fresh rows use the DB clock (datetime('now'), UTC in SQLite) to match
        // the DB-side cutoff; a PHP-local date() would be on a different clock.
        $this->pdo->exec("INSERT INTO files (capture_directory, capture_rate, date_modified) VALUES ('/pending', 0, '2020-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO files (capture_directory, capture_rate, date_modified) VALUES ('/pending', 0, datetime('now'))");
        $this->pdo->exec("INSERT INTO files (capture_directory, capture_rate, date_modified) VALUES ('/house/floor/20250101/', 60, '2020-01-01 00:00:00')");

        $cleaner = new StaleClaimCleaner($this->pdo);
        $counts = $cleaner->clean(3);

        $this->assertSame(1, $counts['screenshot_claims']);
        $rows = $this->pdo->query("SELECT id, capture_directory FROM files ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNull($rows[0]['capture_directory'], 'Stale claim must be reset');
        $this->assertSame('/pending', $rows[1]['capture_directory'], 'Fresh claim must be kept');
        $this->assertSame('/house/floor/20250101/', $rows[2]['capture_directory'], 'Completed video must be untouched');
    }

    public function testResetsClaimsWithNullDateModified(): void
    {
        // Claims made before this fix never set date_modified — treat as stale.
        $this->pdo->exec("INSERT INTO files (capture_directory, capture_rate, date_modified) VALUES ('/pending', 0, NULL)");

        $cleaner = new StaleClaimCleaner($this->pdo);
        $counts = $cleaner->clean(3);

        $this->assertSame(1, $counts['screenshot_claims']);
    }

    public function testDeletesStalePlaceholdersButKeepsSentinelsAndResults(): void
    {
        // Stale claim placeholders (should be deleted)
        $this->pdo->exec("INSERT INTO video_index (file_id, raw_text, type, ignored, date_created) VALUES (1, '/pending', 'bill', 'y', '2020-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO video_index (file_id, raw_text, type, ignored, date_created) VALUES (2, '/pending', 'legislator', 'y', '2020-01-01 00:00:00')");
        // Fresh claim placeholder (should be kept) — DB clock to match the cutoff.
        $this->pdo->exec("INSERT INTO video_index (file_id, raw_text, type, ignored, date_created) VALUES (3, '/pending', 'bill', 'y', datetime('now'))");
        // Terminal none-found sentinel (should be kept)
        $this->pdo->exec("INSERT INTO video_index (file_id, raw_text, type, ignored, date_created) VALUES (4, '/none', 'bill', 'y', '2020-01-01 00:00:00')");
        // Real result (should be kept)
        $this->pdo->exec("INSERT INTO video_index (file_id, raw_text, type, ignored, date_created) VALUES (5, 'HB1234', 'bill', 'n', '2020-01-01 00:00:00')");

        $cleaner = new StaleClaimCleaner($this->pdo);
        $counts = $cleaner->clean(3);

        $this->assertSame(2, $counts['index_placeholders']);
        $remaining = $this->pdo->query("SELECT raw_text FROM video_index ORDER BY file_id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['/pending', '/none', 'HB1234'], $remaining);
    }
}
