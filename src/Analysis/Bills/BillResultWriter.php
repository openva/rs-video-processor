<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use DateTimeImmutable;
use PDO;

class BillResultWriter
{
    public function __construct(private PDO $pdo)
    {
    }

    public function clearExisting(int $fileId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM video_index WHERE file_id = :id AND type = :type');
        $stmt->execute([':id' => $fileId, ':type' => 'bill']);
    }

    public function record(int $fileId, int $timestamp, array $bills, string $screenshotFilename): void
    {
        if (empty($bills)) {
            return;
        }
        $now = new DateTimeImmutable('now');
        $stmt = $this->pdo->prepare('INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created) VALUES (:file_id, :time, :screenshot, :raw_text, :type, NULL, "n", :created)');

        // Extract screenshot number from filename (e.g., "00102.jpg" -> "00102")
        $screenshotNumber = $this->extractScreenshotNumber($screenshotFilename);

        foreach ($bills as $bill) {
            $stmt->execute([
                ':file_id' => $fileId,
                ':time' => $this->formatTimestamp($timestamp),
                ':screenshot' => $screenshotNumber,
                ':raw_text' => $bill,
                ':type' => 'bill',
                ':created' => $now->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function formatTimestamp(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * Extract numeric screenshot identifier from filename.
     *
     * @param string $filename Screenshot filename (e.g., "00102.jpg")
     * @return string Numeric identifier (e.g., "00102")
     */
    private function extractScreenshotNumber(string $filename): string
    {
        // Remove file extension and any path components
        $basename = basename($filename, '.jpg');
        $basename = basename($basename, '.png');

        // If it's purely numeric (with leading zeros), return as-is
        if (preg_match('/^\d+$/', $basename)) {
            return $basename;
        }

        // Otherwise, this shouldn't happen - log a warning and return the basename
        error_log("Warning: Unexpected screenshot filename format: {$filename}");
        return $basename;
    }
}
