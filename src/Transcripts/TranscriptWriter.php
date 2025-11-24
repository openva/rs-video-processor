<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use DateTimeImmutable;
use PDO;

class TranscriptWriter
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<int,array{start:float,end:float,text:string}> $segments
     */
    public function write(int $fileId, array $segments): void
    {
        if (empty($segments)) {
            return;
        }
        $now = new DateTimeImmutable('now');
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare('INSERT INTO video_transcript (file_id, text, time_start, time_end, new_speaker, legislator_id, date_created) VALUES (:file_id, :text, :start, :end, :new_speaker, NULL, :created)');
        foreach ($segments as $segment) {
            $stmt->execute([
                ':file_id' => $fileId,
                ':text' => $segment['text'],
                ':start' => $this->formatTime($segment['start']),
                ':end' => $this->formatTime($segment['end']),
                ':new_speaker' => 'n',
                ':created' => $now->format('Y-m-d H:i:s'),
            ]);
        }
        $this->pdo->commit();
    }

    private function formatTime(float $seconds): string
    {
        $totalSeconds = max((int) round($seconds), 0);
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $secs = $totalSeconds % 60;
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}
