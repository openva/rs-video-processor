<?php

namespace RichmondSunlight\VideoProcessor\Tests\Jobs;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class JobLedgerTest extends TestCase
{
    private PDO $pdo;
    private int $now;
    private JobLedger $ledger;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE files (id INTEGER PRIMARY KEY)');
        $this->pdo->exec('INSERT INTO files (id) VALUES (1)');
        TestDatabaseFactory::addPipelineTables($this->pdo);

        $this->now = 1_700_000_000;
        $this->ledger = new JobLedger($this->pdo, null, fn(): int => $this->now);
    }

    private function eligible(string $stage = JobLedger::STAGE_DOWNLOAD): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files f WHERE f.id = 1 AND ' . JobLedger::eligibleSql($stage));
        foreach (JobLedger::eligibleParams($this->now) as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn() === 1;
    }

    private function row(string $stage = JobLedger::STAGE_DOWNLOAD): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_jobs WHERE file_id = 1 AND stage = :stage');
        $stmt->execute([':stage' => $stage]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function testUnknownJobIsEligible(): void
    {
        $this->assertTrue($this->eligible());
    }

    public function testClaimHidesJobUntilTheClaimGoesStale(): void
    {
        $this->ledger->claim(1, JobLedger::STAGE_DOWNLOAD);
        $this->assertFalse($this->eligible());
        $this->assertSame(0, (int) $this->row()['attempts'], 'Claiming is not an attempt.');

        $this->now += JobLedger::STALE_CLAIM_HOURS * 3600 + 1;
        $this->assertTrue($this->eligible(), 'A claim older than the stale window is released.');
    }

    public function testClaimOnOneStageDoesNotHideAnother(): void
    {
        $this->ledger->claim(1, JobLedger::STAGE_DOWNLOAD);
        $this->assertTrue($this->eligible(JobLedger::STAGE_TRANSCRIPT));
    }

    public function testFailuresBackOffThenGiveUp(): void
    {
        $this->assertTrue($this->ledger->begin(1, JobLedger::STAGE_DOWNLOAD));
        $this->assertSame(JobLedger::STATUS_RETRY, $this->ledger->recordFailure(1, JobLedger::STAGE_DOWNLOAD, 'first'));
        $this->assertFalse($this->eligible());

        $this->now += 6 * 3600 + 1;
        $this->assertTrue($this->eligible(), 'First retry is allowed after 6 hours.');

        $this->assertTrue($this->ledger->begin(1, JobLedger::STAGE_DOWNLOAD));
        $this->assertSame(JobLedger::STATUS_RETRY, $this->ledger->recordFailure(1, JobLedger::STAGE_DOWNLOAD, 'second'));
        $this->now += 6 * 3600 + 1;
        $this->assertFalse($this->eligible(), 'Second retry waits 12 hours.');
        $this->now += 6 * 3600;
        $this->assertTrue($this->eligible());

        $this->assertTrue($this->ledger->begin(1, JobLedger::STAGE_DOWNLOAD));
        $this->assertSame(JobLedger::STATUS_FAILED, $this->ledger->recordFailure(1, JobLedger::STAGE_DOWNLOAD, 'third'));

        $this->now += 365 * 86400;
        $this->assertFalse($this->eligible(), 'A given-up job stays hidden.');
        $this->assertSame('third', $this->row()['last_error']);
        $this->assertSame(3, (int) $this->row()['attempts']);
    }

    public function testJobInterruptedOnEveryAttemptIsGivenUpOn(): void
    {
        for ($i = 0; $i < JobLedger::MAX_ATTEMPTS; $i++) {
            $this->assertTrue($this->ledger->begin(1, JobLedger::STAGE_SCREENSHOTS));
            // The worker dies here: no success or failure is recorded.
            $this->now += JobLedger::STALE_CLAIM_HOURS * 3600 + 1;
            $this->assertTrue($this->eligible(JobLedger::STAGE_SCREENSHOTS));
        }

        $this->assertFalse($this->ledger->begin(1, JobLedger::STAGE_SCREENSHOTS));
        $this->assertSame(JobLedger::STATUS_FAILED, $this->row(JobLedger::STAGE_SCREENSHOTS)['status']);
        $this->assertFalse($this->eligible(JobLedger::STAGE_SCREENSHOTS));
    }

    public function testSuccessClearsAttemptsAndDoesNotHideTheJob(): void
    {
        $this->ledger->begin(1, JobLedger::STAGE_DOWNLOAD);
        $this->ledger->recordFailure(1, JobLedger::STAGE_DOWNLOAD, 'oops');
        $this->ledger->begin(1, JobLedger::STAGE_DOWNLOAD);
        $this->ledger->recordSuccess(1, JobLedger::STAGE_DOWNLOAD);

        $row = $this->row();
        $this->assertSame(JobLedger::STATUS_DONE, $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNull($row['last_error']);
        $this->assertTrue($this->eligible(), 'The queue SQL, not the ledger, decides whether done work is pending.');
    }

    public function testMarkFailedHidesImmediately(): void
    {
        $this->ledger->markFailed(1, JobLedger::STAGE_DOWNLOAD, 'no source URL');
        $this->assertFalse($this->eligible());
        $this->assertSame([['file_id' => 1, 'stage' => 'download']], array_map(
            fn(array $job) => ['file_id' => (int) $job['file_id'], 'stage' => $job['stage']],
            $this->ledger->failedJobs()
        ));
    }

    public function testResetMakesAFailedJobEligibleAgain(): void
    {
        $this->ledger->markFailed(1, JobLedger::STAGE_DOWNLOAD, 'x');
        $this->ledger->claim(1, JobLedger::STAGE_TRANSCRIPT);

        $this->assertSame(1, $this->ledger->reset(null, null, true), 'failedOnly leaves the running claim alone.');
        $this->assertTrue($this->eligible());
        $this->assertFalse($this->eligible(JobLedger::STAGE_TRANSCRIPT));

        $this->assertSame(1, $this->ledger->reset(1));
        $this->assertTrue($this->eligible(JobLedger::STAGE_TRANSCRIPT));
    }

    public function testErrorTextIsMadeSafeToStore(): void
    {
        $this->ledger->begin(1, JobLedger::STAGE_DOWNLOAD);
        $this->ledger->recordFailure(1, JobLedger::STAGE_DOWNLOAD, str_repeat('x', 5000) . "\xC3\x28");

        $stored = $this->row()['last_error'];
        $this->assertLessThanOrEqual(2000, mb_strlen($stored));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
    }

    public function testLastActivity(): void
    {
        $this->assertNull($this->ledger->lastActivity());
        $this->ledger->claim(1, JobLedger::STAGE_DOWNLOAD);
        $this->assertSame($this->now, $this->ledger->lastActivity());
    }

    public function testUnknownStageIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->claim(1, 'nonsense');
    }
}
