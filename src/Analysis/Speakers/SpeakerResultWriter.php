<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use DateTimeImmutable;
use PDO;

class SpeakerResultWriter
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<int,array{name:string,start:float,legislator_id:?int}> $segments
     */
    public function write(int $fileId, array $segments): void
    {
        if (empty($segments)) {
            return;
        }
        $now = new DateTimeImmutable('now');
        $stmt = $this->pdo->prepare('INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created) VALUES (:file_id, :time, :shot, :raw, :type, :linked, "n", :created)');
        foreach ($segments as $segment) {
            $stmt->execute([
                ':file_id' => $fileId,
                ':time' => $this->formatTime($segment['start']),
                ':shot' => 'speaker-' . preg_replace('/\s+/', '-', strtolower($segment['name'])),
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
}
