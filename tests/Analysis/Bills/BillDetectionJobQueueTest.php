<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Bills;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJobQueue;

class BillDetectionJobQueueTest extends TestCase
{
    public function testFetchBuildsManifestPath(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            committee_id INTEGER,
            capture_directory TEXT,
            video_index_cache TEXT,
            date TEXT,
            date_created TEXT
        )');
        $pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            type TEXT
        )');
        $pdo->prepare('INSERT INTO files (chamber, committee_id, capture_directory, video_index_cache, date, date_created) VALUES ("house", 123, :dir, :cache, "20250101", "2025-01-01")')
            ->execute([
                ':dir' => 'https://video.richmondsunlight.com/house/floor/20250101/',
                ':cache' => json_encode(['AgendaTree' => []]),
            ]);
        $queue = new BillDetectionJobQueue($pdo);
        $jobs = $queue->fetch();
        $this->assertCount(1, $jobs);
        $this->assertStringContainsString('manifest.json', $jobs[0]->manifestUrl);
        $this->assertSame(123, $jobs[0]->committeeId);
    }

    public function testFetchStripsLegacyVideoPrefix(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            committee_id INTEGER,
            capture_directory TEXT,
            video_index_cache TEXT,
            date TEXT,
            date_created TEXT
        )');
        $pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            type TEXT
        )');
        $pdo->prepare('INSERT INTO files (chamber, committee_id, capture_directory, video_index_cache, date, date_created) VALUES ("house", NULL, :dir, :cache, "20260121", "2026-01-21")')
            ->execute([
                ':dir' => '/video/house/floor/20260121/',
                ':cache' => json_encode(['AgendaTree' => []]),
            ]);
        $queue = new BillDetectionJobQueue($pdo);
        $jobs = $queue->fetch();
        $this->assertCount(1, $jobs);
        // Should strip /video/ prefix from manifest URL
        $this->assertSame(
            'https://video.richmondsunlight.com/house/floor/20260121/manifest.json',
            $jobs[0]->manifestUrl
        );
    }
}
