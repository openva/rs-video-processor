<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use PDO;

class VideoDownloadQueue
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * @return VideoDownloadJob[]
     */
    public function fetch(int $limit = 5): array
    {
        $sql = "SELECT id, chamber, committee_id, title, date, path, video_index_cache
            FROM files
            WHERE (path IS NULL OR path = '' OR (
                path NOT LIKE 'https:///video.richmondsunlight.com/%'
                AND path NOT LIKE 'https://archive.org/%'
            ))
              AND video_index_cache IS NOT NULL
              AND video_index_cache LIKE '{%'
            ORDER BY date_created DESC
            LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $jobs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $metadata = json_decode($row['video_index_cache'], true);
            if (!is_array($metadata)) {
                continue;
            }
            $remote = $metadata['video_url'] ?? $row['path'] ?? null;
            if (!$remote) {
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

        return $jobs;
    }
}
