<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use DateTimeImmutable;
use PDO;
use RuntimeException;

class TranscriptWriter
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
     * "MySQL server has gone away" after long OpenAI transcription.
     */
    public function reconnect(): void
    {
        if ($this->pdoFactory) {
            $this->pdo = ($this->pdoFactory)();
        }
    }

    /**
     * @param array<int,array{start:float,end:float,text:string}> $segments
     */
    public function write(int $fileId, array $segments): void
    {
        if (empty($segments)) {
            return;
        }
        foreach ($segments as $i => $segment) {
            $segments[$i]['text'] = $this->sanitizeForUtf8mb3($segment['text']);
        }
        $now = new DateTimeImmutable('now');
        $this->pdo->beginTransaction();

        $delete = $this->pdo->prepare('DELETE FROM video_transcript WHERE file_id = :id');
        $delete->execute([':id' => $fileId]);

        // Insert segments into video_transcript table
        $stmt = $this->pdo->prepare('INSERT INTO video_transcript (file_id, text, time_start, time_end, new_speaker, legislator_id, date_created) VALUES (:file_id, :text, :start, :end, :new_speaker, NULL, :created)');
        foreach ($segments as $segment) {
            try {
                $stmt->execute([
                    ':file_id' => $fileId,
                    ':text' => $segment['text'],
                    ':start' => $this->formatTime($segment['start']),
                    ':end' => $this->formatTime($segment['end']),
                    ':new_speaker' => 'n',
                    ':created' => $now->format('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    sprintf('Failed to insert transcript segment for file #%d: %s', $fileId, $segment['text']),
                    0,
                    $e
                );
            }
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

    /**
     * Production columns (video_transcript.text, files.transcript, files.webvtt)
     * are utf8mb3, which rejects 4-byte Unicode (emoji, supplementary planes),
     * malformed UTF-8, and null bytes. Malformed byte sequences are repaired
     * (replaced with substitute characters) first — otherwise the /u regex below
     * returns null on ill-formed input and silently strips nothing — then 4-byte
     * characters and null bytes are removed so one emoji can't poison a
     * transcript forever.
     */
    private function sanitizeForUtf8mb3(string $text): string
    {
        // Repair/replace ill-formed byte sequences first, so the /u regex below
        // never fails on malformed input and silently no-ops. Malformed bytes are
        // also rejected by MySQL's utf8mb3 validation, so this must run regardless.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);
        return str_replace("\0", '', $text);
    }
}
