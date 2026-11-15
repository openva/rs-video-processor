<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use PDO;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\ProcessingPriority;

class TranscriptJobQueue
{
    /**
     * Videos are transcribed from this date on, the earliest date bill and
     * speaker detection cover (ScreenshotJobQueue uses it too). The older ones
     * have almost no captions, so each would be transcribed in full by OpenAI:
     * about $300 for the 2008 to 2014 archived videos, and $79 for the 2018
     * and 2019 videos still on S3, in November 2026.
     */
    public const TRANSCRIBE_FROM = '2020-01-01';

    /**
     * A video without captions is transcribed in full, which with OpenAI costs
     * $0.36 an hour of video (a video with captions costs $0.06, to check
     * their timing). Uncaptioned videos from before this date are left out of
     * the transcript stage, and transcribed instead on Waldo's Mac, for free,
     * by bin/transcribe_backlog.php: in November 2026 there were 287 of them,
     * about $183 of OpenAI's time. The Mac reads them from archive.org, which
     * is free, so each waits until the archive stage has moved it there:
     * reading it from S3 outside AWS would cost $0.09 a GB, and then the
     * archive stage would pay that again to send it to the archive.
     */
    public const OPENAI_WHISPER_FROM = '2024-01-01';

    private function __construct(private PDO $pdo, private bool $backlog)
    {
    }

    /** The transcript stage's jobs, done on the server with OpenAI. */
    public static function stage(PDO $pdo): self
    {
        return new self($pdo, false);
    }

    /**
     * The uncaptioned videos from before OPENAI_WHISPER_FROM, which the stage
     * leaves to bin/transcribe_backlog.php, once they are on archive.org. They
     * are never counted by PendingWork, so they never start the instance.
     */
    public static function backlog(PDO $pdo): self
    {
        return new self($pdo, true);
    }

    /** Whether the video has captions to work from, or must be transcribed in full. */
    private const HAS_CAPTIONS = "(COALESCE(f.webvtt, '') != '' OR COALESCE(f.srt, '') != '')";

    private function pendingWhere(): string
    {
        // An archived video's audio is read from archive.org, which is free;
        // its S3 copy is in Glacier, where reading costs $0.03 a GB. Only
        // download URLs name a file; old details URLs do not.
        $where = "(f.path LIKE 'https://video.richmondsunlight.com/%' OR f.path LIKE 'https://archive.org/download/%')
            AND f.date >= '" . self::TRANSCRIBE_FROM . "'
            AND NOT EXISTS (SELECT 1 FROM video_transcript vt WHERE vt.file_id = f.id)
            AND " . JobLedger::eligibleSql(JobLedger::STAGE_TRANSCRIPT);

        if ($this->backlog) {
            return $where . ' AND NOT ' . self::HAS_CAPTIONS . " AND f.date < '" . self::OPENAI_WHISPER_FROM . "'"
                . " AND f.path LIKE 'https://archive.org/download/%'";
        }

        return $where . ' AND (' . self::HAS_CAPTIONS . " OR f.date >= '" . self::OPENAI_WHISPER_FROM . "')";
    }

    /**
     * The backlog, for bin/transcribe_backlog.php to hand to another machine.
     * Nothing is claimed: the transcript stage never takes these videos.
     *
     * @return list<array{id:int,chamber:string,date:string,path:string,title:?string}>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->prepare('SELECT f.id, f.chamber, f.date, f.path, f.title FROM files f WHERE '
            . $this->pendingWhere() . ' ORDER BY f.date DESC, f.id');
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'chamber' => (string) $row['chamber'],
            'date' => (string) $row['date'],
            'path' => (string) $row['path'],
            'title' => $row['title'] ?? null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function countPending(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files f WHERE ' . $this->pendingWhere());
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Put every transcript job that could be handed out now aside until
     * $until: every one of them needs OpenAI, to transcribe the audio or to
     * check the captions' timing. Set aside, they are not counted as pending
     * (so rs-machine does not start the instance for them) until then.
     *
     * @return int number of jobs put aside
     */
    public function putAllAside(string $reason, int $until): int
    {
        $stmt = $this->pdo->prepare('SELECT f.id FROM files f WHERE ' . $this->pendingWhere());
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $ledger = new JobLedger($this->pdo);
        $this->pdo->beginTransaction();
        try {
            foreach ($ids as $id) {
                $ledger->putAside($id, JobLedger::STAGE_TRANSCRIPT, $reason, $until);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return count($ids);
    }

    /**
     * @return TranscriptJob[]
     */
    public function fetch(int $limit = 5): array
    {
        $sql = 'SELECT f.id, f.chamber, f.path, f.webvtt, f.srt, f.title
            FROM files f
            WHERE ' . $this->pendingWhere() . '
            ORDER BY ' . ProcessingPriority::orderSql() . ', f.date DESC
            LIMIT :limit';

        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $locking = in_array($driver, ['mysql', 'pgsql'], true);
        if ($locking) {
            $sql .= ' FOR UPDATE SKIP LOCKED';
            $this->pdo->beginTransaction();
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        $jobs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $jobs[] = new TranscriptJob(
                (int) $row['id'],
                (string) $row['chamber'],
                (string) $row['path'],
                $row['webvtt'] ?? null,
                $row['srt'] ?? null,
                $row['title'] ?? null
            );
        }

        // The lock lasts only until commit. The claim keeps other workers from
        // transcribing (and paying for) the same video.
        $ledger = new JobLedger($this->pdo);
        foreach ($jobs as $job) {
            $ledger->claim($job->fileId, JobLedger::STAGE_TRANSCRIPT);
        }

        if ($locking) {
            $this->pdo->commit();
        }

        return $jobs;
    }
}
