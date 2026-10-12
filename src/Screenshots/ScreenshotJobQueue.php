<?php

namespace RichmondSunlight\VideoProcessor\Screenshots;

use PDO;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;

class ScreenshotJobQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    private function pendingWhere(): string
    {
        return "f.path LIKE 'https://video.richmondsunlight.com/%'
            AND (f.capture_directory IS NULL OR f.capture_directory = ''
                 OR (f.capture_directory NOT LIKE '/%' AND f.capture_directory NOT LIKE 'https://%')
                 OR f.capture_rate IS NULL)
            AND " . JobLedger::eligibleSql(JobLedger::STAGE_SCREENSHOTS);
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
     * @return ScreenshotJob[]
     */
    public function fetch(int $limit = 5): array
    {
        $sql = 'SELECT f.id, f.chamber, f.committee_id, f.title, f.date, f.path, f.capture_directory
            FROM files f
            WHERE ' . $this->pendingWhere() . '
            ORDER BY f.date DESC
            LIMIT :limit';

        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (in_array($driver, ['mysql', 'pgsql'], true)) {
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
            $jobs[] = new ScreenshotJob(
                (int) $row['id'],
                (string) $row['chamber'],
                $row['committee_id'] !== null ? (int) $row['committee_id'] : null,
                (string) $row['date'],
                (string) $row['path'],
                $row['capture_directory'] ?? null,
                $row['title'] ?? null
            );
        }

        // Mark fetched rows as claimed before releasing locks, so other
        // workers' SKIP LOCKED queries won't pick up the same rows.
        if (!empty($jobs) && in_array($driver, ['mysql', 'pgsql'], true)) {
            $ids = array_map(fn(ScreenshotJob $j) => $j->id, $jobs);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $this->pdo->prepare(
                "UPDATE files SET capture_rate = 0, capture_directory = '/pending', date_modified = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})"
            );
            $update->execute($ids);
        }

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->pdo->commit();
        }

        return $jobs;
    }
}
