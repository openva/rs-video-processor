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
        $sql = "SELECT f.id, f.chamber, f.committee_id, f.capture_directory, f.video_index_cache, f.date
            FROM files f
            WHERE f.capture_directory IS NOT NULL AND f.capture_directory != ''
              AND (f.capture_directory LIKE '/%' OR f.capture_directory LIKE 'https://%')
              AND f.date >= '2020-01-01'
              AND NOT EXISTS (
                SELECT 1 FROM video_index vi
                WHERE vi.file_id = f.id AND vi.type = 'bill'
              )
            ORDER BY f.date DESC
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
                $metadata,
                (string) $row['date']
            );
        }

        // Insert placeholder rows so other workers' NOT EXISTS checks skip these files.
        if (!empty($jobs) && in_array($driver, ['mysql', 'pgsql'], true)) {
            $placeholder = $this->pdo->prepare(
                "INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
                 VALUES (:file_id, '00:00:00', '00000000', '/pending', 'bill', NULL, 'y', NOW())"
            );
            foreach ($jobs as $job) {
                $placeholder->execute([':file_id' => $job->fileId]);
            }
        }

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->pdo->commit();
        }

        return $jobs;
    }

    private function buildManifestUrl(string $captureDirectory): ?string
    {
        if ($captureDirectory === '') {
            return null;
        }

        // Handle old format: full S3 URL
        if (str_starts_with($captureDirectory, 'https://')) {
            $base = rtrim($captureDirectory, '/');
            if (str_ends_with($base, '/full')) {
                $base = substr($base, 0, -strlen('/full'));
            }
            return $base . '/manifest.json';
        }

        // Handle new format: directory path like /senate/floor/20250111/
        // Strip legacy /video/ prefix if present
        $path = preg_replace('#^/video/#', '/', $captureDirectory);
        $path = trim($path, '/');
        if (str_ends_with($path, '/full')) {
            $path = substr($path, 0, -strlen('/full'));
        }
        return sprintf(
            'https://video.richmondsunlight.com/%s/manifest.json',
            $path
        );
    }
}
