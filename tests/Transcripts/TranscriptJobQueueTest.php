<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;
use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
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
            html TEXT,
            webvtt TEXT,
            srt TEXT,
            date TEXT,
            date_created TEXT
        )');
        TestDatabaseFactory::addPipelineTables($pdo);
        $pdo->exec('CREATE TABLE video_transcript (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            text TEXT,
            time_start TEXT,
            time_end TEXT
        )');

        $pdo->prepare('INSERT INTO files (chamber, title, path, webvtt, srt, date, date_created) VALUES ("house", "Test", :path, "", "", "20250101", "2025-01-01")')->execute([
            ':path' => 'https://video.richmondsunlight.com/house/floor/20250101.mp4',
        ]);

        $queue = new TranscriptJobQueue($pdo);
        $jobs = $queue->fetch();

        $this->assertCount(1, $jobs);
        $this->assertSame('house', strtolower($jobs[0]->chamber));
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT, chamber TEXT, title TEXT, path TEXT, html TEXT,
            webvtt TEXT, srt TEXT, date TEXT, date_created TEXT
        )');
        TestDatabaseFactory::addPipelineTables($pdo);
        $pdo->exec('CREATE TABLE video_transcript (
            id INTEGER PRIMARY KEY AUTOINCREMENT, file_id INTEGER, text TEXT, time_start TEXT, time_end TEXT
        )');
        return $pdo;
    }

    private function addVideo(PDO $pdo, string $path, string $date): int
    {
        $pdo->prepare("INSERT INTO files (chamber, title, path, date, date_created) VALUES ('house', 'House Session', :path, :date, :date)")
            ->execute([':path' => $path, ':date' => $date]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Once OpenAI's cap is reached, every transcript job is set aside, so
     * none counts as pending and rs-machine does not start the instance.
     */
    public function testPutAllAsideStopsCountingUntilResumed(): void
    {
        $pdo = $this->database();
        $first = $this->addVideo($pdo, 'https://video.richmondsunlight.com/house/floor/a.mp4', '2026-01-10');
        $this->addVideo($pdo, 'https://video.richmondsunlight.com/house/floor/b.mp4', '2026-01-11');
        $running = $this->addVideo($pdo, 'https://video.richmondsunlight.com/house/floor/c.mp4', '2026-01-12');
        $ledger = new JobLedger($pdo);
        $ledger->recordFailure($first, JobLedger::STAGE_TRANSCRIPT, 'earlier failure');
        $pdo->exec("UPDATE video_jobs SET next_attempt_at = '2000-01-01 00:00:00'");
        $ledger->claim($running, JobLedger::STAGE_TRANSCRIPT);
        $queue = new TranscriptJobQueue($pdo);
        $this->assertSame(2, $queue->countPending());

        $this->assertSame(2, $queue->putAllAside('cap reached', time() + 86400));

        $this->assertSame(0, $queue->countPending());
        $this->assertSame([], $queue->fetch());
        $rows = array_map(
            static fn(array $r): array => ['file_id' => (int) $r['file_id'], 'status' => $r['status'], 'attempts' => (int) $r['attempts']],
            $pdo->query('SELECT file_id, status, attempts FROM video_jobs ORDER BY file_id')->fetchAll(PDO::FETCH_ASSOC)
        );
        // An earlier failure still counts; a job running now is left alone.
        $this->assertSame([
            ['file_id' => $first, 'status' => 'waiting', 'attempts' => 1],
            ['file_id' => $first + 1, 'status' => 'waiting', 'attempts' => 0],
            ['file_id' => $running, 'status' => 'running', 'attempts' => 0],
        ], $rows);

        $this->assertSame(2, $ledger->resumeWaiting(JobLedger::STAGE_TRANSCRIPT));
        $this->assertSame(2, $queue->countPending());
    }

    /** Once archived, a video's audio is read from archive.org, which is free. */
    public function testFetchesArchivedVideosFrom2020On(): void
    {
        $pdo = $this->database();
        $archived = $this->addVideo($pdo, 'https://archive.org/download/va-house-20250101-1/va-house-20250101-1.mp4', '2025-01-01');
        $this->addVideo($pdo, 'https://archive.org/download/rs-house-20191231-house-session/ia_video_x.mp4', '2019-12-31');
        // An old details URL names no file to read.
        $this->addVideo($pdo, 'https://archive.org/details/rs-house-20250102-house-session', '2025-01-02');

        $queue = new TranscriptJobQueue($pdo);
        $jobs = $queue->fetch(10);

        $this->assertSame([$archived], array_map(static fn($job) => $job->fileId, $jobs));
        $this->assertSame('https://archive.org/download/va-house-20250101-1/va-house-20250101-1.mp4', $jobs[0]->videoUrl);
    }

    public function testCountsArchivedVideosFrom2020On(): void
    {
        $pdo = $this->database();
        $this->addVideo($pdo, 'https://video.richmondsunlight.com/house/floor/20250101.mp4', '2025-01-01');
        $this->addVideo($pdo, 'https://archive.org/download/va-house-20250102-2/va-house-20250102-2.mp4', '2025-01-02');
        $this->addVideo($pdo, 'https://archive.org/download/rs-house-20100101-house-session/ia_video_x.mp4', '2010-01-01');

        $this->assertSame(2, (new TranscriptJobQueue($pdo))->countPending());
    }

    public function testArchivedVideosWithTranscriptsAreNotFetched(): void
    {
        $pdo = $this->database();
        $id = $this->addVideo($pdo, 'https://archive.org/download/va-house-20250101-1/va-house-20250101-1.mp4', '2025-01-01');
        $pdo->prepare("INSERT INTO video_transcript (file_id, text, time_start, time_end) VALUES (:id, 'Hello', '00:00:01', '00:00:02')")
            ->execute([':id' => $id]);

        $this->assertSame([], (new TranscriptJobQueue($pdo))->fetch(10));
    }
}
