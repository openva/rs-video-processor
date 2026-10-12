<?php

namespace RichmondSunlight\VideoProcessor\Sync;

use PDO;

/**
 * Maps a video's upstream identifier to its files row.
 *
 * A source URL changes once the video is copied to S3, and a duration differs
 * by a second between the listing and ffprobe. The upstream ID does neither,
 * so it is the only reliable answer to "have we imported this already?".
 */
class VideoSourceRegistry
{
    private const ID_KEYS = ['youtube_id', 'content_id', 'clip_id', 'video_id'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $record a scraped record, or a decoded video_index_cache
     * @return array{source:string,source_id:string}|null
     */
    public static function identityOf(array $record): ?array
    {
        $source = $record['source'] ?? null;
        if (!is_string($source) || $source === '') {
            return null;
        }

        foreach (self::ID_KEYS as $key) {
            $value = $record[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                return [
                    'source' => substr(strtolower($source), 0, 20),
                    'source_id' => substr((string) $value, 0, 64),
                ];
            }
        }

        return null;
    }

    public function findFileId(string $source, string $sourceId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT file_id FROM video_sources WHERE source = :source AND source_id = :source_id'
        );
        $stmt->execute([':source' => $source, ':source_id' => $sourceId]);
        $fileId = $stmt->fetchColumn();

        return $fileId === false ? null : (int) $fileId;
    }

    /**
     * @return bool false when the ID already belongs to a different file
     */
    public function register(int $fileId, string $source, string $sourceId): bool
    {
        $owner = $this->findFileId($source, $sourceId);
        if ($owner !== null) {
            return $owner === $fileId;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO video_sources (source, source_id, file_id, date_created)
             VALUES (:source, :source_id, :file_id, :created)'
        );
        $stmt->execute([
            ':source' => $source,
            ':source_id' => $sourceId,
            ':file_id' => $fileId,
            ':created' => gmdate('Y-m-d H:i:s'),
        ]);

        return true;
    }
}
