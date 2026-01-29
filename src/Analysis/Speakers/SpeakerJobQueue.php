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
        $sql = "SELECT f.id, f.chamber, f.path, f.capture_directory, f.video_index_cache
            FROM files f
            WHERE (f.path LIKE 'https://video.richmondsunlight.com/%'
              OR f.path LIKE 'https://archive.org/%')
              AND NOT EXISTS (
                SELECT 1 FROM video_index vi
                WHERE vi.file_id = f.id AND vi.type = 'legislator'
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
            $captureDir = $row['capture_directory'] ?? null;
            $manifestUrl = null;
            if (is_string($captureDir) && $captureDir !== '') {
                $manifestUrl = $this->buildManifestUrl($captureDir);
                if (str_contains($captureDir, '/committee/')) {
                    $eventType = 'committee';
                }
            }
            $jobs[] = new SpeakerJob(
                (int) $row['id'],
                (string) $row['chamber'],
                (string) $row['path'],
                $metadata,
                $eventType,
                is_string($captureDir) ? $captureDir : null,
                $manifestUrl
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

        $path = trim($captureDirectory, '/');
        if (str_ends_with($path, '/full')) {
            $path = substr($path, 0, -strlen('/full'));
        }
        return sprintf(
            'https://video.richmondsunlight.com/%s/manifest.json',
            $path
        );
    }
}
