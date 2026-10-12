<?php

namespace RichmondSunlight\VideoProcessor\Jobs;

use InvalidArgumentException;
use PDO;

/**
 * Per-video, per-stage job state in the video_jobs table: who has claimed a
 * job, how many attempts it has had, and why it last failed.
 *
 * Each *JobQueue class decides what work exists from the files table, as it
 * always has. The ledger only hides work that should not be handed out right
 * now: claimed by another worker, waiting out a retry delay, or given up on.
 *
 * All timestamps are UTC strings produced here, so they never depend on the
 * database server's clock or timezone.
 */
class JobLedger
{
    public const STAGE_DOWNLOAD = 'download';
    public const STAGE_SCREENSHOTS = 'screenshots';
    public const STAGE_TRANSCRIPT = 'transcript';
    public const STAGE_BILLS = 'bills';
    public const STAGE_SPEAKERS = 'speakers';
    public const STAGE_ARCHIVE = 'archive';

    public const STAGES = [
        self::STAGE_DOWNLOAD,
        self::STAGE_SCREENSHOTS,
        self::STAGE_TRANSCRIPT,
        self::STAGE_BILLS,
        self::STAGE_SPEAKERS,
        self::STAGE_ARCHIVE,
    ];

    public const STATUS_RUNNING = 'running';
    public const STATUS_RETRY = 'retry';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DONE = 'done';

    /** A job is given up on after this many attempts. */
    public const MAX_ATTEMPTS = 3;
    /** Wait this long after the first failure, twice this after the second. */
    public const RETRY_DELAY_HOURS = 6;
    /** A claim this old belongs to a worker that died; the job is handed out again. */
    public const STALE_CLAIM_HOURS = 3;

    private const MAX_ERROR_LENGTH = 2000;

    /** @var (\Closure(): PDO)|null */
    private ?\Closure $pdoFactory;

    /** @var (\Closure(): int)|null */
    private ?\Closure $clock;

    /**
     * @param (\Closure(): PDO)|null $pdoFactory supply this when writes happen after long
     *        work, when the original connection may have timed out
     * @param (\Closure(): int)|null $clock unix time source, for tests
     */
    public function __construct(private PDO $pdo, ?\Closure $pdoFactory = null, ?\Closure $clock = null)
    {
        $this->pdoFactory = $pdoFactory;
        $this->clock = $clock;
    }

    /**
     * SQL condition that is true when the ledger allows this file's job to be
     * handed out. Append it to a queue's WHERE clause, then bind
     * eligibleParams() on the statement.
     */
    public static function eligibleSql(string $stage, string $fileIdColumn = 'f.id'): string
    {
        self::assertStage($stage);

        return "NOT EXISTS (
            SELECT 1 FROM video_jobs vj
            WHERE vj.file_id = {$fileIdColumn} AND vj.stage = '{$stage}'
              AND (vj.status = 'failed'
                   OR (vj.status = 'retry' AND vj.next_attempt_at > :ledger_now)
                   OR (vj.status = 'running' AND vj.claimed_at > :ledger_stale))
        )";
    }

    /**
     * @return array<string,string>
     */
    public static function eligibleParams(?int $now = null): array
    {
        $now = $now ?? time();

        return [
            ':ledger_now' => self::format($now),
            ':ledger_stale' => self::format($now - self::STALE_CLAIM_HOURS * 3600),
        ];
    }

    /**
     * Mark a job as taken, at the moment a queue hands it out. Not an attempt.
     */
    public function claim(int $fileId, string $stage): void
    {
        self::assertStage($stage);
        $pdo = $this->connection();
        $row = $this->row($pdo, $fileId, $stage);

        $this->write(
            $pdo,
            $row !== null,
            $fileId,
            $stage,
            self::STATUS_RUNNING,
            (int) ($row['attempts'] ?? 0),
            self::format($this->now()),
            null,
            $row['last_error'] ?? null
        );
    }

    /**
     * Count an attempt, immediately before work starts. Counting here rather
     * than on failure means a worker killed mid-job still uses an attempt up.
     *
     * @return bool false when the job has no attempts left; it is then marked failed
     */
    public function begin(int $fileId, string $stage): bool
    {
        self::assertStage($stage);
        $pdo = $this->connection();
        $row = $this->row($pdo, $fileId, $stage);
        $attempts = (int) ($row['attempts'] ?? 0);

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->write(
                $pdo,
                true,
                $fileId,
                $stage,
                self::STATUS_FAILED,
                $attempts,
                null,
                null,
                $row['last_error'] ?? 'Interrupted before finishing on every attempt.'
            );
            return false;
        }

        $this->write(
            $pdo,
            $row !== null,
            $fileId,
            $stage,
            self::STATUS_RUNNING,
            $attempts + 1,
            self::format($this->now()),
            null,
            $row['last_error'] ?? null
        );

        return true;
    }

    public function recordSuccess(int $fileId, string $stage): void
    {
        self::assertStage($stage);
        $pdo = $this->connection();
        $row = $this->row($pdo, $fileId, $stage);

        $this->write($pdo, $row !== null, $fileId, $stage, self::STATUS_DONE, 0, null, null, null);
    }

    /**
     * @return string the job's new status: STATUS_RETRY or STATUS_FAILED
     */
    public function recordFailure(int $fileId, string $stage, string $error): string
    {
        self::assertStage($stage);
        $pdo = $this->connection();
        $row = $this->row($pdo, $fileId, $stage);
        $attempts = max(1, (int) ($row['attempts'] ?? 0));

        if ($attempts >= self::MAX_ATTEMPTS) {
            $status = self::STATUS_FAILED;
            $nextAttemptAt = null;
        } else {
            $status = self::STATUS_RETRY;
            $nextAttemptAt = self::format($this->now() + self::RETRY_DELAY_HOURS * 3600 * $attempts);
        }

        $this->write($pdo, $row !== null, $fileId, $stage, $status, $attempts, null, $nextAttemptAt, self::cleanError($error));

        return $status;
    }

    /**
     * Give up on a job at once, for a problem no retry can fix.
     */
    public function markFailed(int $fileId, string $stage, string $error): void
    {
        self::assertStage($stage);
        $pdo = $this->connection();
        $row = $this->row($pdo, $fileId, $stage);

        $this->write(
            $pdo,
            $row !== null,
            $fileId,
            $stage,
            self::STATUS_FAILED,
            (int) ($row['attempts'] ?? 0),
            null,
            null,
            self::cleanError($error)
        );
    }

    /**
     * Forget ledger rows so their jobs are handed out again.
     *
     * @return int number of rows removed
     */
    public function reset(?int $fileId = null, ?string $stage = null, bool $failedOnly = false): int
    {
        $sql = 'DELETE FROM video_jobs WHERE 1 = 1';
        $params = [];
        if ($fileId !== null) {
            $sql .= ' AND file_id = :file_id';
            $params[':file_id'] = $fileId;
        }
        if ($stage !== null) {
            self::assertStage($stage);
            $sql .= ' AND stage = :stage';
            $params[':stage'] = $stage;
        }
        if ($failedOnly) {
            $sql .= " AND status = 'failed'";
        }

        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * @return array<int,array{file_id:int|string,stage:string,attempts:int|string,last_error:?string,date_modified:string}>
     */
    public function failedJobs(): array
    {
        $stmt = $this->connection()->query(
            "SELECT file_id, stage, attempts, last_error, date_modified
             FROM video_jobs WHERE status = 'failed'
             ORDER BY date_modified DESC, file_id DESC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Unix time of the most recent ledger write, or null if there has never been one.
     */
    public function lastActivity(): ?int
    {
        $latest = $this->connection()->query('SELECT MAX(date_modified) FROM video_jobs')->fetchColumn();
        if ($latest === false || $latest === null) {
            return null;
        }
        $timestamp = strtotime($latest . ' UTC');

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function row(PDO $pdo, int $fileId, string $stage): ?array
    {
        $stmt = $pdo->prepare('SELECT attempts, last_error FROM video_jobs WHERE file_id = :file_id AND stage = :stage');
        $stmt->execute([':file_id' => $fileId, ':stage' => $stage]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function write(
        PDO $pdo,
        bool $exists,
        int $fileId,
        string $stage,
        string $status,
        int $attempts,
        ?string $claimedAt,
        ?string $nextAttemptAt,
        ?string $lastError
    ): void {
        $sql = $exists
            ? 'UPDATE video_jobs
               SET status = :status, attempts = :attempts, claimed_at = :claimed_at,
                   next_attempt_at = :next_attempt_at, last_error = :last_error, date_modified = :date_modified
               WHERE file_id = :file_id AND stage = :stage'
            : 'INSERT INTO video_jobs
                   (file_id, stage, status, attempts, claimed_at, next_attempt_at, last_error, date_modified)
               VALUES
                   (:file_id, :stage, :status, :attempts, :claimed_at, :next_attempt_at, :last_error, :date_modified)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':file_id' => $fileId,
            ':stage' => $stage,
            ':status' => $status,
            ':attempts' => $attempts,
            ':claimed_at' => $claimedAt,
            ':next_attempt_at' => $nextAttemptAt,
            ':last_error' => $lastError,
            ':date_modified' => self::format($this->now()),
        ]);
    }

    private function connection(): PDO
    {
        return $this->pdoFactory ? ($this->pdoFactory)() : $this->pdo;
    }

    private function now(): int
    {
        return $this->clock ? ($this->clock)() : time();
    }

    private static function format(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private static function assertStage(string $stage): void
    {
        if (!in_array($stage, self::STAGES, true)) {
            throw new InvalidArgumentException('Unknown pipeline stage: ' . $stage);
        }
    }

    private static function cleanError(string $error): string
    {
        // Command output can contain bytes that are not valid UTF-8.
        $error = mb_convert_encoding($error, 'UTF-8', 'UTF-8');

        return mb_substr($error, 0, self::MAX_ERROR_LENGTH);
    }
}
