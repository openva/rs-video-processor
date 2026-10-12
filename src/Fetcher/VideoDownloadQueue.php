<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use PDO;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;

class VideoDownloadQueue
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * Videos with scraped metadata that are not yet on S3 or archive.org.
     * YouTube sources are excluded: they are staged by hand with
     * scripts/fetch_youtube_uploads.sh. The ledger clause hides videos another
     * worker has claimed, that are waiting to be retried, or that were given up on.
     */
    private function pendingWhere(): string
    {
        return "(f.path IS NULL OR f.path = '' OR (
                f.path NOT LIKE 'https://video.richmondsunlight.com/%'
                AND f.path NOT LIKE 'https://archive.org/%'
            ))
            AND (f.html IS NULL OR f.html = '')
            AND f.video_index_cache IS NOT NULL
            AND f.video_index_cache LIKE '{%'
            AND f.video_index_cache NOT LIKE '%youtube.com%'
            AND f.video_index_cache NOT LIKE '%youtu.be%'
            AND " . JobLedger::eligibleSql(JobLedger::STAGE_DOWNLOAD);
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
     * @return VideoDownloadJob[]
     */
    public function fetch(int $limit = 5): array
    {
        $sql = 'SELECT f.id, f.chamber, f.committee_id, f.title, f.date, f.path, f.video_index_cache
            FROM files f
            WHERE ' . $this->pendingWhere() . '
            ORDER BY f.date DESC
            LIMIT :limit';

        // Row locks keep two workers from reading the same rows at the same
        // moment. Only supported on MySQL/PostgreSQL, not SQLite (used in tests).
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

        $ledger = new JobLedger($this->pdo);
        $jobs = [];
        $unusable = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $metadata = json_decode($row['video_index_cache'], true);
            $remote = is_array($metadata) ? ($metadata['video_url'] ?? $row['path'] ?? null) : null;

            // A row with nothing to download would otherwise be selected again
            // on every pass, ahead of the rows that can be downloaded.
            if (!$remote || preg_match('#https?://(www\.)?(youtube\.com|youtu\.be)/#', $remote)) {
                $ledger->markFailed(
                    (int) $row['id'],
                    JobLedger::STAGE_DOWNLOAD,
                    'No downloadable video_url in video_index_cache.'
                );
                $unusable++;
                continue;
            }

            $jobs[] = new VideoDownloadJob(
                (int) $row['id'],
                (string) $row['chamber'],
                isset($row['committee_id']) ? (int) $row['committee_id'] : null,
                (string) $row['date'],
                $remote,
                $metadata,
                $row['title'] ?? null
            );
        }

        // The lock above lasts only until commit. The claim is what keeps
        // other workers away while this one downloads.
        foreach ($jobs as $job) {
            $ledger->claim($job->id, JobLedger::STAGE_DOWNLOAD);
        }

        if ($locking) {
            $this->pdo->commit();
        }

        // Every row read was unusable and is now hidden; look again for usable ones.
        if (empty($jobs) && $unusable > 0) {
            return $this->fetch($limit);
        }

        return $jobs;
    }
}
