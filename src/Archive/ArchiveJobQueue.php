<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use PDO;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\ProcessingPriority;

class ArchiveJobQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    private function pendingWhere(): string
    {
        // A real screenshot directory has at least two slashes. The markers
        // '/pending' and '/deleted-source' have one and must not pass.
        // Screenshots are made a few steps earlier in the same run, from S3,
        // which is faster than from archive.org.
        //
        // A transcript and captions are not waited for: some videos never get
        // them. The transcript stage reads archived videos from archive.org,
        // and the archive_captions stage adds captions to the item later.
        return "f.path LIKE 'https://video.richmondsunlight.com/%'
            AND (f.capture_directory LIKE '/%/%' OR f.capture_directory LIKE 'https://%')
            AND " . JobLedger::eligibleSql(JobLedger::STAGE_ARCHIVE);
    }

    public function countPending(): int
    {
        // A video waiting on the Internet Archive is not work for this machine.
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files f WHERE ' . $this->pendingWhere()
            . ' AND ' . JobLedger::notWaitingSql(JobLedger::STAGE_ARCHIVE));
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * fetch(): only jobs waiting on the archive. Nearly all are uploads waiting
     * for the archive to list the video; a few were put aside because the
     * archive was busy or down.
     */
    public const WAITING = 'waiting';

    /** fetch(): only jobs not waiting on the archive: uploads still to make. */
    public const NOT_WAITING = 'not_waiting';

    /**
     * @param string|null $which WAITING, NOT_WAITING, or null for both. A
     *        waiting job is handed out only once its time to be checked again
     *        has come; checking it is one question to the archive, while an
     *        upload moves a whole video, so upload_archive.php takes the two
     *        separately and limits only the uploads.
     * @return ArchiveJob[]
     */
    public function fetch(int $limit = 3, ?string $which = null): array
    {
        $where = $this->pendingWhere();
        if ($which === self::WAITING) {
            $where .= ' AND NOT ' . JobLedger::notWaitingSql(JobLedger::STAGE_ARCHIVE);
        } elseif ($which === self::NOT_WAITING) {
            $where .= ' AND ' . JobLedger::notWaitingSql(JobLedger::STAGE_ARCHIVE);
        } elseif ($which !== null) {
            throw new \InvalidArgumentException('Unknown kind of archive job: ' . $which);
        }

        $sql = 'SELECT f.id, f.chamber, f.title, f.date, f.path, f.webvtt, f.srt, f.caption_alignment,
                       f.capture_directory, f.video_index_cache, f.committee_id, c.name as committee_name
            FROM files f
            LEFT JOIN committees c ON f.committee_id = c.id
            WHERE ' . $where . '
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
                $row['committee_name'] ?? null,
                $row['caption_alignment'] ?? null
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
