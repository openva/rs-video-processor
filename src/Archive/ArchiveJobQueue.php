<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use PDO;

class ArchiveJobQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return ArchiveJob[]
     */
    public function fetch(int $limit = 3): array
    {
        $sql = "SELECT f.id, f.chamber, f.title, f.date, f.path, f.webvtt, f.srt,
                       f.capture_directory, f.video_index_cache, f.committee_id, c.name as committee_name
            FROM files f
            LEFT JOIN committees c ON f.committee_id = c.id
            WHERE f.path LIKE 'https://video.richmondsunlight.com/%'
              AND (f.webvtt IS NOT NULL OR f.srt IS NOT NULL)
            ORDER BY f.date_created DESC
            LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
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

        return $jobs;
    }
}
