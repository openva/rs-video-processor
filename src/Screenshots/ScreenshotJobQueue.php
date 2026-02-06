<?php

namespace RichmondSunlight\VideoProcessor\Screenshots;

use PDO;

class ScreenshotJobQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return ScreenshotJob[]
     */
    public function fetch(int $limit = 5): array
    {
        $sql = "SELECT id, chamber, committee_id, title, date, path, capture_directory
            FROM files
            WHERE path LIKE 'https://video.richmondsunlight.com/%'
              AND (capture_directory IS NULL OR capture_directory = ''
                   OR (capture_directory NOT LIKE '/%' AND capture_directory NOT LIKE 'https://%')
                   OR capture_rate IS NULL)
            ORDER BY date DESC
            LIMIT :limit";

        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $sql .= " FOR UPDATE SKIP LOCKED";
            $this->pdo->beginTransaction();
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
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
                "UPDATE files SET capture_rate = 0, capture_directory = '/pending' WHERE id IN ({$placeholders})"
            );
            $update->execute($ids);
        }

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->pdo->commit();
        }

        return $jobs;
    }
}
