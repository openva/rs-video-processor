<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use DateTimeImmutable;
use PDO;

class SpeakerResultWriter
{
    /** @var (\Closure(): PDO)|null */
    private ?\Closure $pdoFactory;

    public function __construct(
        private PDO $pdo,
        ?\Closure $pdoFactory = null
    ) {
        $this->pdoFactory = $pdoFactory;
    }

    /**
     * Get a fresh PDO connection if a factory is available.
     * Called before the first DB operation of each job to avoid
     * "MySQL server has gone away" after long diarization/OCR processing.
     */
    public function reconnect(): void
    {
        if ($this->pdoFactory) {
            $this->pdo = ($this->pdoFactory)();
        }
    }

    public function hasEntries(int $fileId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM video_index WHERE file_id = :id AND type = :type LIMIT 1');
        $stmt->execute([
            ':id' => $fileId,
            ':type' => 'legislator',
        ]);
        return (bool) $stmt->fetchColumn();
    }

    public function clearExisting(int $fileId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM video_index WHERE file_id = :id AND type = :type');
        $stmt->execute([':id' => $fileId, ':type' => 'legislator']);
    }

    /**
     * @param array<int,array{name:string,start:float,legislator_id:?int}> $segments
     */
    public function write(int $fileId, array $segments): void
    {
        if (empty($segments)) {
            return;
        }
        $this->clearExisting($fileId);

        $now = new DateTimeImmutable('now');
        $stmt = $this->pdo->prepare('INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created) VALUES (:file_id, :time, :shot, :raw, :type, :linked, "n", :created)');
        foreach ($segments as $segment) {
            // Convert timestamp to screenshot number (screenshots are 1 FPS)
            $screenshotNumber = $this->timestampToScreenshotNumber($segment['start']);

            $stmt->execute([
                ':file_id' => $fileId,
                ':time' => $this->formatTime($segment['start']),
                ':shot' => $screenshotNumber,
                ':raw' => $segment['name'],
                ':type' => 'legislator',
                ':linked' => $segment['legislator_id'] ?? null,
                ':created' => $now->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function formatTime(float $seconds): string
    {
        $seconds = max((int) round($seconds), 0);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * Convert timestamp to screenshot number (screenshots are generated at 1 FPS).
     *
     * @param float $timestamp Timestamp in seconds
     * @return string Screenshot number with leading zeros (e.g., "00000102")
     */
    private function timestampToScreenshotNumber(float $timestamp): string
    {
        // Screenshots are 1 per second, numbered starting from 00000001
        // Round to nearest second and add 1 (since screenshots start at 00000001, not 00000000)
        $second = max(1, (int) round($timestamp) + 1);
        return sprintf('%08d', $second);
    }
}
