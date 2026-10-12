<?php

namespace RichmondSunlight\VideoProcessor\Tests\Jobs;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;
use RichmondSunlight\VideoProcessor\Tests\Support\TestLogger;
use RuntimeException;

class StageRunnerTest extends TestCase
{
    private PDO $pdo;
    private JobLedger $ledger;
    private TestLogger $log;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        TestDatabaseFactory::addPipelineTables($this->pdo);
        $this->ledger = new JobLedger($this->pdo);
        $this->log = new TestLogger();
    }

    /**
     * @param int[] $ids
     * @return callable(): ?object
     */
    private function jobs(array $ids): callable
    {
        return function () use (&$ids): ?object {
            $id = array_shift($ids);
            return $id === null ? null : (object) ['id' => $id];
        };
    }

    private function jobStatus(int $fileId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT status FROM video_jobs WHERE file_id = :id AND stage = 'download'");
        $stmt->execute([':id' => $fileId]);
        $status = $stmt->fetchColumn();
        return $status === false ? null : $status;
    }

    private function runner(): StageRunner
    {
        return new StageRunner($this->ledger, JobLedger::STAGE_DOWNLOAD, $this->log);
    }

    public function testRecordsSuccessesAndStopsWhenTheQueueIsEmpty(): void
    {
        $seen = [];
        $count = $this->runner()->run(
            10,
            $this->jobs([1, 2]),
            fn(object $job): int => $job->id,
            function (object $job) use (&$seen): void {
                $seen[] = $job->id;
            }
        );

        $this->assertSame(2, $count);
        $this->assertSame([1, 2], $seen);
        $this->assertSame(JobLedger::STATUS_DONE, $this->jobStatus(1));
        $this->assertSame(JobLedger::STATUS_DONE, $this->jobStatus(2));
    }

    public function testRespectsTheLimit(): void
    {
        $count = $this->runner()->run(1, $this->jobs([1, 2]), fn(object $job): int => $job->id, fn() => null);
        $this->assertSame(1, $count);
        $this->assertNull($this->jobStatus(2));
    }

    public function testAnExceptionIsAFailureAndTheRunContinues(): void
    {
        $count = $this->runner()->run(
            10,
            $this->jobs([1, 2]),
            fn(object $job): int => $job->id,
            function (object $job): void {
                if ($job->id === 1) {
                    throw new RuntimeException('download exploded');
                }
            }
        );

        $this->assertSame(1, $count);
        $this->assertSame(JobLedger::STATUS_RETRY, $this->jobStatus(1));
        $this->assertSame(JobLedger::STATUS_DONE, $this->jobStatus(2));

        $messages = array_column($this->log->entries, 'message');
        $this->assertContains('Download failed for file #1 (will retry later): download exploded', $messages);
    }

    public function testReturningFalseIsAFailure(): void
    {
        $count = $this->runner()->run(10, $this->jobs([1]), fn(object $job): int => $job->id, fn() => false);

        $this->assertSame(0, $count);
        $this->assertSame(JobLedger::STATUS_RETRY, $this->jobStatus(1));
    }

    /**
     * If S3 or the database is down, every job fails. Stop early so an outage
     * costs three jobs one attempt each, not the whole backlog.
     */
    public function testStopsAfterThreeConsecutiveFailures(): void
    {
        $attempted = [];
        $count = $this->runner()->run(
            10,
            $this->jobs([1, 2, 3, 4, 5]),
            fn(object $job): int => $job->id,
            function (object $job) use (&$attempted): void {
                $attempted[] = $job->id;
                throw new RuntimeException('S3 is down');
            }
        );

        $this->assertSame(0, $count);
        $this->assertSame([1, 2, 3], $attempted);
        $this->assertNull($this->jobStatus(4));
    }

    public function testASuccessResetsTheConsecutiveFailureCount(): void
    {
        $attempted = [];
        $this->runner()->run(
            10,
            $this->jobs([1, 2, 3, 4, 5]),
            fn(object $job): int => $job->id,
            function (object $job) use (&$attempted): void {
                $attempted[] = $job->id;
                if ($job->id !== 3) {
                    throw new RuntimeException('bad video');
                }
            }
        );

        $this->assertSame([1, 2, 3, 4, 5], $attempted);
    }

    public function testSkipsAJobThatHasNoAttemptsLeft(): void
    {
        for ($i = 0; $i < JobLedger::MAX_ATTEMPTS; $i++) {
            $this->ledger->begin(1, JobLedger::STAGE_DOWNLOAD);
        }

        $attempted = [];
        $count = $this->runner()->run(
            10,
            $this->jobs([1, 2]),
            fn(object $job): int => $job->id,
            function (object $job) use (&$attempted): void {
                $attempted[] = $job->id;
            }
        );

        $this->assertSame(1, $count);
        $this->assertSame([2], $attempted);
        $this->assertSame(JobLedger::STATUS_FAILED, $this->jobStatus(1));
    }
}
