<?php

namespace RichmondSunlight\VideoProcessor\Tests\Screenshots;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJobQueue;

class ScreenshotJobQueueTest extends TestCase
{
    public function testFetchReturnsRowsNeedingScreenshots(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            committee_id INTEGER,
            title TEXT,
            date TEXT,
            path TEXT,
            capture_directory TEXT,
            date_created TEXT
        )');

        $stmt = $pdo->prepare('INSERT INTO files (chamber, committee_id, title, date, path, capture_directory, date_created) VALUES (:chamber, :committee_id, :title, :date, :path, :capture_directory, :created)');
        $stmt->execute([
            ':chamber' => 'house',
            ':committee_id' => null,
            ':title' => 'Floor',
            ':date' => '2025-01-31',
            ':path' => 'https://s3.amazonaws.com/video.richmondsunlight.com/house/floor/20250131.mp4',
            ':capture_directory' => '',
            ':created' => '2025-01-31 12:00:00',
        ]);

        $queue = new ScreenshotJobQueue($pdo);
        $jobs = $queue->fetch();

        $this->assertCount(1, $jobs);
        $this->assertSame('house', strtolower($jobs[0]->chamber));
        $this->assertSame('2025-01-31', $jobs[0]->date);
    }
}
