<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use DateTimeImmutable;
use PDO;

class BillResultWriter
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(int $fileId, int $timestamp, array $bills, string $screenshotFilename): void
    {
        if (empty($bills)) {
            return;
        }
        $now = new DateTimeImmutable('now');
        $stmt = $this->pdo->prepare('INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created) VALUES (:file_id, :time, :screenshot, :raw_text, :type, NULL, "n", :created)');
        foreach ($bills as $bill) {
            $stmt->execute([
                ':file_id' => $fileId,
                ':time' => $this->formatTimestamp($timestamp),
                ':screenshot' => $screenshotFilename,
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
}
