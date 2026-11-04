<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use PDO;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\ProcessingPriority;

class TranscriptJobQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Archived videos are transcribed from this date on, the earliest date
     * bill and speaker detection cover (ScreenshotJobQueue uses it too). The
     * older ones have almost no captions, so each would be transcribed in
     * full by OpenAI: about $300 for the 2008 to 2014 videos, in November 2026.
     */
    public const ARCHIVED_FROM = '2020-01-01';

    private function pendingWhere(): string
    {
        // An archived video's audio is read from archive.org, which is free;
        // its S3 copy is in Glacier, where reading costs $0.03 a GB. Only
        // download URLs name a file; old details URLs do not.
        return "(f.path LIKE 'https://video.richmondsunlight.com/%'
                 OR (f.path LIKE 'https://archive.org/download/%' AND f.date >= '" . self::ARCHIVED_FROM . "'))
            AND NOT EXISTS (SELECT 1 FROM video_transcript vt WHERE vt.file_id = f.id)
            AND " . JobLedger::eligibleSql(JobLedger::STAGE_TRANSCRIPT);
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
