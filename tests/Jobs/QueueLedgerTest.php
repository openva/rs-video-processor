<?php

namespace RichmondSunlight\VideoProcessor\Tests\Jobs;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJobQueue;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadQueue;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJobQueue;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobQueue;

class QueueLedgerTest extends TestCase
{
    private const S3 = 'https://video.richmondsunlight.com/house/floor/20250101-1.mp4';
    private const SHOTS = '/house/floor/20250101-1/';

    /**
     * @return array<string,array{0:string,1:\Closure,2:array<string,mixed>}>
     */
    public static function queues(): array
    {
        $cache = json_encode(['video_url' => 'https://example.test/a.mp4']);

        return [
            'download' => [
                JobLedger::STAGE_DOWNLOAD,
                fn(PDO $pdo) => new VideoDownloadQueue($pdo),
                ['path' => '', 'video_index_cache' => $cache],
            ],
            'screenshots' => [
                JobLedger::STAGE_SCREENSHOTS,
                fn(PDO $pdo) => new ScreenshotJobQueue($pdo),
                ['path' => self::S3],
            ],
            'transcript' => [
                JobLedger::STAGE_TRANSCRIPT,
                fn(PDO $pdo) => new TranscriptJobQueue($pdo),
                ['path' => self::S3],
            ],
            'bills' => [
                JobLedger::STAGE_BILLS,
                fn(PDO $pdo) => new BillDetectionJobQueue($pdo),
                ['capture_directory' => self::SHOTS],
            ],
            'speakers' => [
                JobLedger::STAGE_SPEAKERS,
                fn(PDO $pdo) => new SpeakerJobQueue($pdo),
                ['path' => self::S3],
            ],
            'archive' => [
                JobLedger::STAGE_ARCHIVE,
                fn(PDO $pdo) => new ArchiveJobQueue($pdo),
                ['path' => self::S3, 'webvtt' => 'WEBVTT', 'capture_directory' => self::SHOTS, 'transcript' => 'text'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function seed(PDO $pdo, array $overrides, string $date = '2025-01-01'): int
    {
        $row = array_merge([
            'chamber' => 'house',
            'title' => 'Test',
            'date' => $date,
            'path' => null,
            'html' => null,
            'webvtt' => null,
            'transcript' => null,
            'capture_directory' => null,
            'capture_rate' => null,
            'video_index_cache' => null,
            'date_created' => '2025-01-01 00:00:00',
        ], $overrides);

        $columns = array_keys($row);
        $stmt = $pdo->prepare(sprintf(
            'INSERT INTO files (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(fn(string $c) => ':' . $c, $columns))
        ));
        $stmt->execute(array_combine(array_map(fn(string $c) => ':' . $c, $columns), array_values($row)));

        return (int) $pdo->lastInsertId();
    }

    /**
     * @dataProvider queues
     */
    public function testGivenUpJobIsNeitherFetchedNorCounted(string $stage, \Closure $makeQueue, array $row): void
    {
        $pdo = TestDatabaseFactory::create();
        $fileId = $this->seed($pdo, $row);
        $queue = $makeQueue($pdo);

        $this->assertSame(1, $queue->countPending());

        (new JobLedger($pdo))->markFailed($fileId, $stage, 'test');

        $this->assertSame(0, $queue->countPending());
        $this->assertSame([], $queue->fetch(5));
    }

    /**
     * @dataProvider queues
     */
    public function testJobWaitingToRetryIsNeitherFetchedNorCounted(string $stage, \Closure $makeQueue, array $row): void
    {
        $pdo = TestDatabaseFactory::create();
        $fileId = $this->seed($pdo, $row);
        $queue = $makeQueue($pdo);

        $ledger = new JobLedger($pdo);
        $ledger->begin($fileId, $stage);
        $ledger->recordFailure($fileId, $stage, 'transient');

        $this->assertSame(0, $queue->countPending());
        $this->assertSame([], $queue->fetch(5));
    }

    /**
     * @dataProvider queues
     */
    public function testJobClaimedByAnotherWorkerIsNotFetched(string $stage, \Closure $makeQueue, array $row): void
    {
        $pdo = TestDatabaseFactory::create();
        $fileId = $this->seed($pdo, $row);

        (new JobLedger($pdo))->claim($fileId, $stage);

        $this->assertSame([], $makeQueue($pdo)->fetch(5));
    }

    /**
     * These three stages had no claim of their own: two workers starting
     * together could both take the same video.
     *
     * @dataProvider queues
     */
    public function testFetchClaimsWhatItReturns(string $stage, \Closure $makeQueue, array $row): void
    {
        if (!in_array($stage, [JobLedger::STAGE_DOWNLOAD, JobLedger::STAGE_TRANSCRIPT, JobLedger::STAGE_ARCHIVE], true)) {
            $this->markTestSkipped('This stage claims through the files or video_index tables, on MariaDB only.');
        }

        $pdo = TestDatabaseFactory::create();
        $this->seed($pdo, $row);

        $this->assertCount(1, $makeQueue($pdo)->fetch(5));
        $this->assertSame([], $makeQueue($pdo)->fetch(5), 'A second worker must not receive the same video.');
        $this->assertSame(0, $makeQueue($pdo)->countPending());
    }

    /**
     * A row the download queue cannot use must not sit at the head of the
     * queue forever, hiding the usable rows behind it.
     */
    public function testUnusableDownloadRowIsGivenUpOnAndSkipped(): void
    {
        $pdo = TestDatabaseFactory::create();
        $bad = $this->seed($pdo, ['path' => '', 'video_index_cache' => '{"title": "no url here"}'], '2025-06-01');
        $good = $this->seed($pdo, [
            'path' => '',
            'video_index_cache' => json_encode(['video_url' => 'https://example.test/good.mp4']),
        ], '2025-01-01');

        $jobs = (new VideoDownloadQueue($pdo))->fetch(1);

        $this->assertCount(1, $jobs);
        $this->assertSame($good, $jobs[0]->id);

        $failed = (new JobLedger($pdo))->failedJobs();
        $this->assertCount(1, $failed);
        $this->assertSame($bad, (int) $failed[0]['file_id']);
    }
}
