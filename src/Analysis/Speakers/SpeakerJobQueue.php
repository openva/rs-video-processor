<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use PDO;

class SpeakerJobQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return SpeakerJob[]
     */
    public function fetch(int $limit = 3): array
    {
        $sql = "SELECT f.id, f.chamber, f.path, f.video_index_cache
            FROM files f
            WHERE f.path LIKE 'https://video.richmondsunlight.com/%'
              AND NOT EXISTS (
                SELECT 1 FROM video_index vi
                WHERE vi.file_id = f.id AND vi.type = 'legislator'
              )
            ORDER BY f.date_created DESC
            LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $jobs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $metadata = null;
            if (!empty($row['video_index_cache'])) {
                $decoded = json_decode($row['video_index_cache'], true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }
            $jobs[] = new SpeakerJob(
                (int) $row['id'],
                (string) $row['chamber'],
                (string) $row['path'],
                $metadata
            );
        }

        return $jobs;
    }
}
