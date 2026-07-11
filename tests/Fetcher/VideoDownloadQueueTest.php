<?php

namespace RichmondSunlight\VideoProcessor\Tests\Fetcher;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadQueue;

class VideoDownloadQueueTest extends TestCase
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
            date TEXT,
            path TEXT,
            html TEXT,
            video_index_cache TEXT,
            date_created TEXT
        )');
    }

    public function testFetchReturnsJobsWithMetadata(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO files (chamber, committee_id, title, date, path, video_index_cache, date_created) VALUES (:chamber, :committee_id, :title, :date, :path, :cache, :created)');
        $stmt->execute([
            ':chamber' => 'senate',
            ':committee_id' => null,
            ':title' => 'Floor Session',
            ':date' => '2025-11-19',
            ':path' => '',
            ':cache' => json_encode(['video_url' => 'https://granicus.com/player/clip/12345']),
            ':created' => '2025-11-19 12:00:00',
        ]);

        $queue = new VideoDownloadQueue($this->pdo);
        $jobs = $queue->fetch();

        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame('senate', strtolower($job->chamber));
        $this->assertSame('https://granicus.com/player/clip/12345', $job->remoteUrl);
    }

    public function testFetchSkipsYouTubeUrls(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO files (chamber, committee_id, title, date, path, video_index_cache, date_created) VALUES (:chamber, :committee_id, :title, :date, :path, :cache, :created)');
        $stmt->execute([
            ':chamber' => 'senate',
            ':committee_id' => null,
            ':title' => 'Floor Session',
            ':date' => '2025-11-19',
            ':path' => '',
            ':cache' => json_encode(['video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']),
            ':created' => '2025-11-19 12:00:00',
        ]);

        $queue = new VideoDownloadQueue($this->pdo);
        $jobs = $queue->fetch();

        $this->assertCount(0, $jobs);
    }

    public function testFetchSkipsAlreadyDownloadedVideos(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO files (chamber, committee_id, title, date, path, video_index_cache, date_created) VALUES (:chamber, :committee_id, :title, :date, :path, :cache, :created)');
        $stmt->execute([
            ':chamber' => 'house',
            ':committee_id' => null,
            ':title' => 'Floor Session',
            ':date' => '2025-11-19',
            ':path' => 'https://video.richmondsunlight.com/house/floor/20251119.mp4',
            ':cache' => json_encode(['video_url' => 'https://sg001-harmony.sliq.net/download/12345']),
            ':created' => '2025-11-19 12:00:00',
        ]);

        $queue = new VideoDownloadQueue($this->pdo);
        $jobs = $queue->fetch();

        $this->assertCount(0, $jobs, 'A video already downloaded to S3 must not be re-queued for download.');
    }

    public function testYouTubeRowsDoNotConsumeTheFetchLimit(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO files (chamber, committee_id, title, date, path, video_index_cache, date_created) VALUES (:chamber, :committee_id, :title, :date, :path, :cache, :created)');

        // Five newer YouTube videos (would fill a LIMIT 5 window)
        for ($i = 0; $i < 5; $i++) {
            $stmt->execute([
                ':chamber' => 'senate',
                ':committee_id' => null,
                ':title' => 'Senate video ' . $i,
                ':date' => '2025-11-2' . $i,
                ':path' => '',
                ':cache' => json_encode(['video_url' => 'https://www.youtube.com/watch?v=abc' . $i]),
                ':created' => '2025-11-20 12:00:00',
            ]);
        }

        // One older, downloadable House video
        $stmt->execute([
            ':chamber' => 'house',
            ':committee_id' => null,
            ':title' => 'House Floor',
            ':date' => '2025-11-01',
            ':path' => '',
            ':cache' => json_encode(['video_url' => 'https://sg001-harmony.sliq.net/download/999']),
            ':created' => '2025-11-01 12:00:00',
        ]);

        $queue = new VideoDownloadQueue($this->pdo);
        $jobs = $queue->fetch(5);

        $this->assertCount(1, $jobs, 'YouTube rows must be excluded in SQL so they do not consume the LIMIT.');
        $this->assertSame('https://sg001-harmony.sliq.net/download/999', $jobs[0]->remoteUrl);
    }
}
