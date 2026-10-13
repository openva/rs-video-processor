<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Speakers;

use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;
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
            title TEXT,
            path TEXT,
            html TEXT,
            capture_directory TEXT,
            video_index_cache TEXT,
            date TEXT,
            date_created TEXT
        )');
        TestDatabaseFactory::addPipelineTables($pdo);
        $pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            type TEXT
        )');
        $pdo->prepare('INSERT INTO files (chamber, path, capture_directory, video_index_cache, date, date_created) VALUES ("house", :path, :capture, :cache, "20250101", "2025-01-01")')
            ->execute([
                ':path' => 'https://video.richmondsunlight.com/house/floor/20250101.mp4',
                ':capture' => '/house/floor/20250101/screenshots',
                ':cache' => json_encode(['Speakers' => []]),
            ]);
        $queue = new SpeakerJobQueue($pdo);
        $jobs = $queue->fetch();
        $this->assertCount(1, $jobs);
        $this->assertSame('house', strtolower($jobs[0]->chamber));
    }

    /**
     * Speaker names for these videos come from on-screen text, so there is
     * nothing to do until screenshots exist. Handing the video out earlier only
     * uses up its attempts, or records "no speakers" for a video never looked at.
     */
    public function testWaitsForScreenshots(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            title TEXT,
            path TEXT,
            html TEXT,
            capture_directory TEXT,
            video_index_cache TEXT,
            date TEXT,
            date_created TEXT
        )');
        TestDatabaseFactory::addPipelineTables($pdo);
        $pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            type TEXT
        )');
        $insert = $pdo->prepare('INSERT INTO files (chamber, path, capture_directory, date, date_created) VALUES ("house", "https://video.richmondsunlight.com/house/floor/20260310-1.mp4", :capture, "2026-03-10", "2026-03-10")');
        foreach ([null, '', '/pending', '/deleted-source'] as $notReady) {
            $insert->execute([':capture' => $notReady]);
        }

        $queue = new SpeakerJobQueue($pdo);
        $this->assertSame([], $queue->fetch(10));
        $this->assertSame(0, $queue->countPending());

        $insert->execute([':capture' => '/house/floor/20260310-5/']);
        $this->assertCount(1, $queue->fetch(10));
    }
}
