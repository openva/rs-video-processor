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

        // Video with empty capture_directory - needs screenshots
        $stmt->execute([
            ':chamber' => 'house',
            ':committee_id' => null,
            ':title' => 'Floor',
            ':date' => '2025-01-31',
            ':path' => 'https://video.richmondsunlight.com/house/floor/20250131.mp4',
            ':capture_directory' => '',
            ':created' => '2025-01-31 12:00:00',
        ]);

        // Video with S3 key path (set by VideoDownloadProcessor) - needs screenshots
        $stmt->execute([
            ':chamber' => 'senate',
            ':committee_id' => null,
            ':title' => 'Floor',
            ':date' => '2025-02-01',
            ':path' => 'https://video.richmondsunlight.com/senate/floor/20250201.mp4',
            ':capture_directory' => 'senate/floor/20250201.mp4',
            ':created' => '2025-02-01 12:00:00',
        ]);

        // Video with full screenshot URL - already has screenshots, should be excluded
        $stmt->execute([
            ':chamber' => 'house',
            ':committee_id' => null,
            ':title' => 'Committee',
            ':date' => '2025-02-02',
            ':path' => 'https://video.richmondsunlight.com/house/committee/20250202.mp4',
            ':capture_directory' => 'https://video.richmondsunlight.com/house/committee/20250202/screenshots/full/',
            ':created' => '2025-02-02 12:00:00',
        ]);

        $queue = new ScreenshotJobQueue($pdo);
        $jobs = $queue->fetch();

        $this->assertCount(2, $jobs);
        $this->assertSame('senate', strtolower($jobs[0]->chamber)); // Newest first (DESC order)
        $this->assertSame('2025-02-01', $jobs[0]->date);
        $this->assertSame('house', strtolower($jobs[1]->chamber));
        $this->assertSame('2025-01-31', $jobs[1]->date);
    }
}
