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

        // Insert segments into video_transcript table
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

        // Update files table with transcript and webvtt
        $transcript = $this->generatePlainText($segments);
        $webvtt = $this->generateWebVtt($segments);

        $updateStmt = $this->pdo->prepare('UPDATE files SET transcript = :transcript, webvtt = :webvtt, date_modified = CURRENT_TIMESTAMP WHERE id = :id');
        $updateStmt->execute([
            ':transcript' => $transcript,
            ':webvtt' => $webvtt,
            ':id' => $fileId,
        ]);

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

    /**
     * @param array<int,array{start:float,end:float,text:string}> $segments
     */
    private function generatePlainText(array $segments): string
    {
        $lines = [];
        foreach ($segments as $segment) {
            $lines[] = trim($segment['text']);
        }
        return implode(' ', $lines);
    }

    /**
     * @param array<int,array{start:float,end:float,text:string}> $segments
     */
    private function generateWebVtt(array $segments): string
    {
        $lines = ["WEBVTT", ""];
        foreach ($segments as $segment) {
            $start = $this->formatWebVttTime($segment['start']);
            $end = $this->formatWebVttTime($segment['end']);
            $lines[] = sprintf('%s --> %s', $start, $end);
            $lines[] = trim($segment['text']);
            $lines[] = '';
        }
        return implode("\n", $lines);
    }

    private function formatWebVttTime(float $seconds): string
    {
        $totalSeconds = max($seconds, 0);
        $hours = intdiv((int) $totalSeconds, 3600);
        $minutes = intdiv((int) $totalSeconds % 3600, 60);
        $secs = (int) $totalSeconds % 60;
        $millis = (int) (($totalSeconds - (int) $totalSeconds) * 1000);
        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $millis);
    }
}
