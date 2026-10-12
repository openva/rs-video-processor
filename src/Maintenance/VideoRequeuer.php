<?php

namespace RichmondSunlight\VideoProcessor\Maintenance;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Returns one video to the "not downloaded" state, so it goes through every
 * stage again.
 *
 * This is the remedy for rows whose video or screenshots were shared with, or
 * overwritten by, another row. It discards the row's derived data. It does not
 * delete anything from S3 or the Internet Archive.
 */
class VideoRequeuer
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * The URL the video was originally scraped from.
     *
     * @throws InvalidArgumentException when the file does not exist
     * @throws RuntimeException when no source URL was recorded
     */
    public function sourceUrl(int $fileId): string
    {
        $stmt = $this->pdo->prepare('SELECT video_index_cache FROM files WHERE id = :id');
        $stmt->execute([':id' => $fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new InvalidArgumentException("File #{$fileId} does not exist.");
        }

        $cache = json_decode((string) $row['video_index_cache'], true);
        $url = is_array($cache) ? ($cache['video_url'] ?? null) : null;
        if (!is_string($url) || $url === '') {
            throw new RuntimeException(
                "File #{$fileId} has no source video_url in video_index_cache; it cannot be re-downloaded."
            );
        }

        return $url;
    }

    /**
     * @return string the source URL the video will be downloaded from again
     */
    public function requeue(int $fileId): string
    {
        $url = $this->sourceUrl($fileId);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE files
                 SET path = :path, capture_directory = NULL, capture_rate = NULL, transcript = NULL, webvtt = NULL
                 WHERE id = :id'
            )->execute([':path' => $url, ':id' => $fileId]);

            foreach (['video_index', 'video_transcript', 'video_jobs'] as $table) {
                $this->pdo->prepare("DELETE FROM {$table} WHERE file_id = :id")->execute([':id' => $fileId]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $url;
    }
}
