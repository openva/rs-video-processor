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
            html TEXT,
            capture_directory TEXT,
            capture_rate INTEGER,
            date_created TEXT
        )');

        $stmt = $pdo->prepare('INSERT INTO files (chamber, committee_id, title, date, path, capture_directory, capture_rate, date_created) VALUES (:chamber, :committee_id, :title, :date, :path, :capture_directory, :capture_rate, :created)');

        // Video with empty capture_directory - needs screenshots
        $stmt->execute([
            ':chamber' => 'house',
            ':committee_id' => null,
            ':title' => 'Floor',
            ':date' => '2025-01-31',
            ':path' => 'https://video.richmondsunlight.com/house/floor/20250131.mp4',
            ':capture_directory' => '',
            ':capture_rate' => null,
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
            ':capture_rate' => null,
            ':created' => '2025-02-01 12:00:00',
        ]);

        // Video with capture_directory but NULL capture_rate - screenshots failed/never ran
        $stmt->execute([
            ':chamber' => 'house',
            ':committee_id' => null,
            ':title' => 'Session',
            ':date' => '2025-02-03',
            ':path' => 'https://video.richmondsunlight.com/house/floor/20250203.mp4',
            ':capture_directory' => '/house/floor/20250203/',
            ':capture_rate' => null,
            ':created' => '2025-02-03 12:00:00',
        ]);

        // Video with full screenshot URL and capture_rate set - already has screenshots, should be excluded
        $stmt->execute([
            ':chamber' => 'house',
            ':committee_id' => null,
            ':title' => 'Committee',
            ':date' => '2025-02-04',
            ':path' => 'https://video.richmondsunlight.com/house/committee/20250204.mp4',
            ':capture_directory' => 'https://video.richmondsunlight.com/house/committee/20250204/',
            ':capture_rate' => 60,
            ':created' => '2025-02-04 12:00:00',
        ]);

        $queue = new ScreenshotJobQueue($pdo);
        $jobs = $queue->fetch();

        $this->assertCount(3, $jobs); // Should fetch all 3 videos needing screenshots
        $this->assertSame('house', strtolower($jobs[0]->chamber)); // Newest first (DESC order)
        $this->assertSame('2025-02-03', $jobs[0]->date); // Video with capture_directory but NULL capture_rate
        $this->assertSame('senate', strtolower($jobs[1]->chamber));
        $this->assertSame('2025-02-01', $jobs[1]->date);
        $this->assertSame('house', strtolower($jobs[2]->chamber));
        $this->assertSame('2025-01-31', $jobs[2]->date);
    }
}
