<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use PDO;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;

class ArchiveJobQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    private function pendingWhere(): string
    {
        // A real screenshot directory has at least two slashes. The markers
        // '/pending' and '/deleted-source' have one and must not pass: archiving
        // moves the video to archive.org, after which it cannot be screenshotted.
        return "f.path LIKE 'https://video.richmondsunlight.com/%'
            AND (f.webvtt IS NOT NULL OR f.srt IS NOT NULL)
            AND (f.capture_directory LIKE '/%/%' OR f.capture_directory LIKE 'https://%')
            AND f.transcript IS NOT NULL
            AND " . JobLedger::eligibleSql(JobLedger::STAGE_ARCHIVE);
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
     * @return ArchiveJob[]
     */
    public function fetch(int $limit = 3): array
    {
        $sql = 'SELECT f.id, f.chamber, f.title, f.date, f.path, f.webvtt, f.srt,
                       f.capture_directory, f.video_index_cache, f.committee_id, c.name as committee_name
            FROM files f
            LEFT JOIN committees c ON f.committee_id = c.id
            WHERE ' . $this->pendingWhere() . '
            ORDER BY f.date DESC
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
            $jobs[] = new ArchiveJob(
                (int) $row['id'],
                (string) $row['chamber'],
                (string) $row['title'],
                (string) $row['date'],
                (string) $row['path'],
                $row['webvtt'] ?? null,
                $row['srt'] ?? null,
                $row['capture_directory'] ?? null,
                $row['video_index_cache'] ?? null,
                $row['committee_id'] !== null ? (int) $row['committee_id'] : null,
                $row['committee_name'] ?? null
            );
        }

        // The lock lasts only until commit. The claim keeps two workers from
        // uploading the same video to the Internet Archive.
        $ledger = new JobLedger($this->pdo);
        foreach ($jobs as $job) {
            $ledger->claim($job->fileId, JobLedger::STAGE_ARCHIVE);
        }

        if ($locking) {
            $this->pdo->commit();
        }

        return $jobs;
    }
}
