<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Metadata;

use DateTimeImmutable;
use PDO;

class MetadataIndexer
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Index agenda items and speakers into video_index from scraped metadata.
     *
     * @param array{agenda?:array<mixed>,speakers?:array<mixed>} $metadata
     */
    public function index(int $fileId, array $metadata): void
    {
        $now = new DateTimeImmutable('now');
        $this->indexAgenda($fileId, $metadata['agenda'] ?? [], $now);
        $this->indexSpeakers($fileId, $metadata['speakers'] ?? [], $now);
    }

    /**
     * @param array<int,array{key?:string,text?:string,start_time?:string}> $agenda
     */
    private function indexAgenda(int $fileId, array $agenda, DateTimeImmutable $now): void
    {
        if (empty($agenda)) {
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created) VALUES (:file_id, :time, :screenshot, :raw, :type, NULL, "n", :created)');
        foreach ($agenda as $item) {
            if (empty($item['start_time']) || empty($item['text'])) {
                continue;
            }
            $time = $this->formatIsoOrTime($item['start_time']);
            $stmt->execute([
                ':file_id' => $fileId,
                ':time' => $time,
                ':screenshot' => 'agenda-' . ($item['key'] ?? preg_replace('/\s+/', '-', strtolower($item['text']))),
                ':raw' => $item['text'],
                ':type' => 'agenda',
                ':created' => $now->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * @param array<int,array{name?:string,start_time?:string}> $speakers
     */
    private function indexSpeakers(int $fileId, array $speakers, DateTimeImmutable $now): void
    {
        if (empty($speakers)) {
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created, new_speaker) VALUES (:file_id, :time, :shot, :raw, :type, NULL, "n", :created, "y")');
        foreach ($speakers as $speaker) {
            if (empty($speaker['start_time']) || empty($speaker['name'])) {
                continue;
            }
            $time = $this->formatIsoOrTime($speaker['start_time']);
            $stmt->execute([
                ':file_id' => $fileId,
                ':time' => $time,
                ':shot' => 'speaker-' . preg_replace('/\s+/', '-', strtolower($speaker['name'])),
                ':raw' => $speaker['name'],
                ':type' => 'speaker',
                ':created' => $now->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function formatIsoOrTime(string $value): string
    {
        // If this looks like an ISO timestamp, convert to HH:MM:SS relative time.
        $ts = strtotime($value);
        if ($ts !== false) {
            $seconds = max((int) ($ts - strtotime(date('Y-m-d 00:00:00', $ts))), 0);
        } else {
            // Fallback: expect HH:MM:SS
            [$h, $m, $s] = array_pad(explode(':', $value), 3, 0);
            $seconds = ((int) $h) * 3600 + ((int) $m) * 60 + (int) $s;
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}
