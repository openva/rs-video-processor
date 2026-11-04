<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\ArchiveCaptionQueue;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class ArchiveCaptionQueueTest extends TestCase
{
    private PDO $pdo;
    private JobLedger $ledger;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE files (id INTEGER PRIMARY KEY, date TEXT, path TEXT, webvtt TEXT, caption_alignment TEXT)');
        TestDatabaseFactory::addPipelineTables($this->pdo);
        $this->ledger = new JobLedger($this->pdo);
    }

    private function video(int $id, string $path, ?string $webvtt = 'WEBVTT', ?string $alignment = 'aligned', bool $requested = true): void
    {
        $this->pdo->prepare('INSERT INTO files (id, date, path, webvtt, caption_alignment) VALUES (:id, :date, :path, :webvtt, :alignment)')
            ->execute([':id' => $id, ':date' => '2025-01-0' . $id, ':path' => $path, ':webvtt' => $webvtt, ':alignment' => $alignment]);
        if ($requested) {
            $this->ledger->request($id, JobLedger::STAGE_ARCHIVE_CAPTIONS);
        }
    }

    public function testFetchesRequestedArchivedVideosWithCheckedCaptions(): void
    {
        $this->video(1, 'https://archive.org/download/va-house-20250101-1/va-house-20250101-1.mp4');
        $this->video(2, 'https://archive.org/download/va-house-20250102-2/va-house-20250102-2.mp4', 'WEBVTT', 'unaligned');

        $queue = new ArchiveCaptionQueue($this->pdo);

        $this->assertSame(2, $queue->countPending());
        $this->assertSame([2, 1], $queue->fetch(5), 'Newest first.');
        $this->assertSame(0, $queue->countPending(), 'Fetched jobs are claimed.');
    }

    public function testLeavesOutWhatHasNothingToSend(): void
    {
        // Archived, but nothing asked for its captions.
        $this->video(1, 'https://archive.org/download/a/a.mp4', 'WEBVTT', 'aligned', false);
        // Not archived.
        $this->video(2, 'https://video.richmondsunlight.com/house/floor/20250102-2.mp4');
        // Captions whose timing has not been checked.
        $this->video(3, 'https://archive.org/download/c/c.mp4', 'WEBVTT', null);
        // No captions.
        $this->video(4, 'https://archive.org/download/d/d.mp4', null);
        // Already sent.
        $this->video(5, 'https://archive.org/download/e/e.mp4');
        $this->ledger->begin(5, JobLedger::STAGE_ARCHIVE_CAPTIONS);
        $this->ledger->recordSuccess(5, JobLedger::STAGE_ARCHIVE_CAPTIONS);
        // Given up on.
        $this->video(6, 'https://archive.org/download/f/f.mp4');
        $this->ledger->markFailed(6, JobLedger::STAGE_ARCHIVE_CAPTIONS, 'broken');

        $queue = new ArchiveCaptionQueue($this->pdo);

        $this->assertSame(0, $queue->countPending());
        $this->assertSame([], $queue->fetch(10));
    }

    /** Waiting on the archive is not a reason to start the instance. */
    public function testAWaitingJobIsNotCountedButIsFetchedWhenDue(): void
    {
        $this->video(1, 'https://archive.org/download/a/a.mp4');
        $this->ledger->begin(1, JobLedger::STAGE_ARCHIVE_CAPTIONS);
        $this->ledger->recordWaiting(1, JobLedger::STAGE_ARCHIVE_CAPTIONS, 'busy', -60);

        $queue = new ArchiveCaptionQueue($this->pdo);

        $this->assertSame(0, $queue->countPending());
        $this->assertSame([1], $queue->fetch(1));
    }
}
