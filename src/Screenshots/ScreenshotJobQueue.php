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
            WHERE path LIKE 'https://s3.amazonaws.com/video.richmondsunlight.com/%'
              AND (capture_directory IS NULL OR capture_directory = '')
            ORDER BY date_created DESC
            LIMIT :limit";

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

        return $jobs;
    }
}
