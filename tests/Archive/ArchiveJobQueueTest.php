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

    /**
     * Videos are archived without waiting for a transcript or captions, which
     * some never get. The archive_captions stage adds captions later.
     */
    public function testArchivesVideosWithoutATranscriptOrCaptions(): void
    {
        $pdo = $this->createTestDatabase();
        $pdo->exec('INSERT INTO files (chamber,title,date,path,webvtt,capture_directory,transcript,date_created) VALUES ("house","Floor","2025-01-01","https://video.richmondsunlight.com/house/floor/20250101.mp4",NULL,"/house/floor/20250101-1/",NULL,"2025-01-01")');

        $queue = new ArchiveJobQueue($pdo);

        $this->assertSame(1, $queue->countPending());
        $this->assertCount(1, $queue->fetch());
    }

    public function testCarriesTheCaptionTimingCheck(): void
    {
        $pdo = $this->createTestDatabase();
        $pdo->exec('INSERT INTO files (chamber,title,date,path,webvtt,caption_alignment,capture_directory,date_created) VALUES ("house","Floor","2025-01-01","https://video.richmondsunlight.com/house/floor/20250101.mp4","WEBVTT","aligned","/house/floor/20250101-1/","2025-01-01")');

        $this->assertSame('aligned', (new ArchiveJobQueue($pdo))->fetch()[0]->captionAlignment);
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

    /**
     * rs-machine starts the instance when anything is pending. A job waiting on
     * the Internet Archive must not start it, or keep it running, just to ask
     * the archive one question; it is checked on whatever run comes next.
     */
    public function testAJobWaitingOnTheArchiveIsCheckedButIsNotPendingWork(): void
    {
        $pdo = $this->createTestDatabase();
        $pdo->exec('INSERT INTO files (chamber,title,date,path,webvtt,capture_directory,transcript,date_created) VALUES ("house","Floor","2025-01-01","https://video.richmondsunlight.com/house/floor/20250101-1.mp4","WEBVTT","/house/floor/1","Some transcript","2025-01-01")');
        $ledger = new \RichmondSunlight\VideoProcessor\Jobs\JobLedger($pdo, null, fn(): int => time() - 7200);
        $ledger->begin(1, 'archive');
        $ledger->recordWaiting(1, 'archive', 'Still processing.', 600);

        $queue = new ArchiveJobQueue($pdo);
        $this->assertSame(0, $queue->countPending());
        $this->assertCount(1, $queue->fetch(), 'A run that is going anyway checks it.');
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
            caption_alignment TEXT,
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
