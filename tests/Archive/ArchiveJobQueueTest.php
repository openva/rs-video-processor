<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;
use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;

class ArchiveJobQueueTest extends TestCase
{
    public function testSelectsEligibleRows(): void
    {
        $pdo = $this->createTestDatabase();

        // Eligible: has captions, capture_directory, and transcript
        $pdo->exec('INSERT INTO files (chamber,title,date,path,webvtt,capture_directory,transcript,date_created) VALUES ("house","Floor","2025-01-01","https://video.richmondsunlight.com/house/floor/20250101.mp4","WEBVTT","/screenshots/1","Some transcript","2025-01-01")');
        // Ineligible: already on archive.org
        $pdo->exec('INSERT INTO files (chamber,title,date,path,webvtt,capture_directory,transcript,date_created) VALUES ("senate","Floor","2025-01-02","https://archive.org/details/test","WEBVTT","/screenshots/2","Some transcript","2025-01-02")');

        $queue = new ArchiveJobQueue($pdo);
        $jobs = $queue->fetch();
        $this->assertCount(1, $jobs);
        $this->assertSame('house', strtolower($jobs[0]->chamber));
    }

    public function testExcludesRowsWithoutScreenshots(): void
    {
        $pdo = $this->createTestDatabase();

        // Has captions and transcript but no capture_directory
        $pdo->exec('INSERT INTO files (chamber,title,date,path,webvtt,capture_directory,transcript,date_created) VALUES ("house","Floor","2025-01-01","https://video.richmondsunlight.com/house/floor/20250101.mp4","WEBVTT",NULL,"Some transcript","2025-01-01")');

        $queue = new ArchiveJobQueue($pdo);
        $jobs = $queue->fetch();
        $this->assertCount(0, $jobs);
    }

    public function testExcludesRowsWithoutTranscript(): void
    {
        $pdo = $this->createTestDatabase();

        // Has captions and capture_directory but no transcript
        $pdo->exec('INSERT INTO files (chamber,title,date,path,webvtt,capture_directory,transcript,date_created) VALUES ("house","Floor","2025-01-01","https://video.richmondsunlight.com/house/floor/20250101.mp4","WEBVTT","/screenshots/1",NULL,"2025-01-01")');

        $queue = new ArchiveJobQueue($pdo);
        $jobs = $queue->fetch();
        $this->assertCount(0, $jobs);
    }

    /**
     * '/pending' means a screenshot job is in flight or has failed. Archiving
     * then would move the video to archive.org, out of the screenshot queue's reach.
     */
    public function testExcludesRowsWhoseScreenshotsAreNotFinished(): void
    {
        $pdo = $this->createTestDatabase();

        foreach (['/pending', '/deleted-source'] as $marker) {
            $pdo->prepare(
                'INSERT INTO files (chamber,title,date,path,webvtt,capture_directory,transcript,date_created)
                 VALUES ("house","Floor","2025-01-01","https://video.richmondsunlight.com/house/floor/20250101.mp4","WEBVTT",:dir,"Some transcript","2025-01-01")'
            )->execute([':dir' => $marker]);
        }

        $queue = new ArchiveJobQueue($pdo);
        $this->assertCount(0, $queue->fetch());
        $this->assertSame(0, $queue->countPending());
    }

    private function createTestDatabase(): PDO
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
            webvtt TEXT,
            srt TEXT,
            capture_directory TEXT,
            transcript TEXT,
            video_index_cache TEXT,
            date_created TEXT
        )');
        TestDatabaseFactory::addPipelineTables($pdo);
        $pdo->exec('CREATE TABLE committees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            chamber TEXT
        )');
        return $pdo;
    }
}
