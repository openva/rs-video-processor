<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Speakers;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJobQueue;

class SpeakerJobQueueTest extends TestCase
{
    public function testFetchesJobsWithoutLegislatorIndex(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            path TEXT,
            video_index_cache TEXT,
            date_created TEXT
        )');
        $pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            type TEXT
        )');
        $pdo->prepare('INSERT INTO files (chamber, path, video_index_cache, date_created) VALUES ("house", :path, :cache, "2025-01-01")')
            ->execute([
                ':path' => 'https://video.richmondsunlight.com/house/floor/20250101.mp4',
                ':cache' => json_encode(['Speakers' => []]),
            ]);
        $queue = new SpeakerJobQueue($pdo);
        $jobs = $queue->fetch();
        $this->assertCount(1, $jobs);
        $this->assertSame('house', strtolower($jobs[0]->chamber));
    }
}
