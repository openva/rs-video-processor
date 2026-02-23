<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Classification;

use PDO;

class ClassificationVerificationJobQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return ClassificationVerificationJob[]
     */
    public function fetch(int $limit = 3): array
    {
        $sql = "SELECT f.id, f.chamber, f.committee_id, f.capture_directory,
                    f.video_index_cache, f.title, f.date
            FROM files f
            WHERE f.chamber = 'house'
              AND f.capture_directory IS NOT NULL AND f.capture_directory != ''
              AND (f.capture_directory LIKE '/%' OR f.capture_directory LIKE 'https://%')
              AND f.date >= '2020-01-01'
              AND f.video_index_cache IS NOT NULL
              AND f.video_index_cache NOT LIKE '%\"classification_verified\"%'
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
            $videoIndexCache = null;
            $eventType = 'floor';
            if (!empty($row['video_index_cache'])) {
                $decoded = json_decode($row['video_index_cache'], true);
                if (is_array($decoded)) {
                    $videoIndexCache = $decoded;
                    if (!empty($decoded['event_type'])) {
                        $eventType = $decoded['event_type'];
                    }
                }
            }
            $jobs[] = new ClassificationVerificationJob(
                (int) $row['id'],
                (string) $row['chamber'],
                $eventType,
                $row['committee_id'] !== null ? (int) $row['committee_id'] : null,
                $captureDir,
                $manifestUrl,
                $videoIndexCache,
                $row['title'] !== null ? (string) $row['title'] : null,
                (string) $row['date']
            );
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

        if (str_starts_with($captureDirectory, 'https://')) {
            $base = rtrim($captureDirectory, '/');
            if (str_ends_with($base, '/full')) {
                $base = substr($base, 0, -strlen('/full'));
            }
            return $base . '/manifest.json';
        }

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
