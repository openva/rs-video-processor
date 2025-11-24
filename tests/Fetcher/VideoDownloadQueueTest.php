<?php

namespace RichmondSunlight\VideoProcessor\Tests\Fetcher;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadQueue;

class VideoDownloadQueueTest extends TestCase
{
    public function testFetchReturnsJobsWithMetadata(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            committee_id INTEGER,
            title TEXT,
            date TEXT,
            path TEXT,
            video_index_cache TEXT,
            date_created TEXT
        )');

        $stmt = $pdo->prepare('INSERT INTO files (chamber, committee_id, title, date, path, video_index_cache, date_created) VALUES (:chamber, :committee_id, :title, :date, :path, :cache, :created)');
        $stmt->execute([
            ':chamber' => 'senate',
            ':committee_id' => null,
            ':title' => 'Floor Session',
            ':date' => '2025-11-19',
            ':path' => '',
            ':cache' => json_encode(['video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']),
            ':created' => '2025-11-19 12:00:00',
        ]);

        $queue = new VideoDownloadQueue($pdo);
        $jobs = $queue->fetch();

        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame('senate', strtolower($job->chamber));
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $job->remoteUrl);
    }
}
