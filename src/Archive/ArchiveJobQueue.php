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
        $sql = "SELECT id, chamber, title, date, path, webvtt, srt, capture_directory, video_index_cache
            FROM files
            WHERE path LIKE 'https://s3.amazonaws.com/video.richmondsunlight.com/%'
              AND (webvtt IS NOT NULL OR srt IS NOT NULL)
            ORDER BY date_created DESC
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
                $row['video_index_cache'] ?? null
            );
        }

        return $jobs;
    }
}
