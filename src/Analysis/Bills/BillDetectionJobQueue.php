<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use PDO;

class BillDetectionJobQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return BillDetectionJob[]
     */
    public function fetch(int $limit = 3): array
    {
        $sql = "SELECT f.id, f.chamber, f.committee_id, f.capture_directory, f.video_index_cache
            FROM files f
            WHERE f.capture_directory IS NOT NULL AND f.capture_directory != ''
              AND NOT EXISTS (
                SELECT 1 FROM video_index vi
                WHERE vi.file_id = f.id AND vi.type = 'bill'
              )
            ORDER BY f.date_created DESC
            LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $jobs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $captureDir = $row['capture_directory'];
            $manifestUrl = $this->buildManifestUrl($captureDir);
            $metadata = null;
            $eventType = 'floor';
            if (!empty($row['video_index_cache'])) {
                $decoded = json_decode($row['video_index_cache'], true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                    if (!empty($decoded['event_type'])) {
                        $eventType = $decoded['event_type'];
                    }
                }
            }
            if (str_contains($captureDir, '/committee/')) {
                $eventType = 'committee';
            }
            $jobs[] = new BillDetectionJob(
                (int) $row['id'],
                (string) $row['chamber'],
                $row['committee_id'] !== null ? (int) $row['committee_id'] : null,
                $eventType,
                $captureDir,
                $manifestUrl,
                $metadata
            );
        }

        return $jobs;
    }

    private function buildManifestUrl(string $captureDirectory): ?string
    {
        if ($captureDirectory === '') {
            return null;
        }
        $base = rtrim($captureDirectory, '/');
        if (str_ends_with($base, '/full')) {
            $base = substr($base, 0, -strlen('/full'));
        }
        return $base . '/manifest.json';
    }
}
