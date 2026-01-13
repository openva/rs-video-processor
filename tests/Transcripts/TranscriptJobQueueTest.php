<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobQueue;

class TranscriptJobQueueTest extends TestCase
{
    public function testFetchesJobsWithoutExistingTranscripts(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            title TEXT,
            path TEXT,
            webvtt TEXT,
            srt TEXT,
            date_created TEXT
        )');
        $pdo->exec('CREATE TABLE video_transcript (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            text TEXT,
            time_start TEXT,
            time_end TEXT
        )');

        $pdo->prepare('INSERT INTO files (chamber, title, path, webvtt, srt, date_created) VALUES ("house", "Test", :path, "", "", "2025-01-01")')->execute([
            ':path' => 'https://video.richmondsunlight.com/house/floor/20250101.mp4',
        ]);

        $queue = new TranscriptJobQueue($pdo);
        $jobs = $queue->fetch();

        $this->assertCount(1, $jobs);
        $this->assertSame('house', strtolower($jobs[0]->chamber));
    }
}
