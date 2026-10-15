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
     * Index speakers into video_index from scraped metadata.
     *
     * Note: Agenda items are not indexed as they don't map to valid video_index types
     * (only 'bill' and 'legislator' are allowed).
     *
     * @param array{agenda?:array<mixed>,speakers?:array<mixed>} $metadata
     */
    public function index(int $fileId, array $metadata): void
    {
        $now = new DateTimeImmutable('now');
        // Agenda items are not indexed - only bills and legislators are valid types
        $this->indexSpeakers($fileId, $metadata['speakers'] ?? [], $metadata, $now);
    }

    /**
     * @param array<int,array{name?:string,start_time?:string}> $speakers
     * @param array<string,mixed> $metadata the whole scraped record, for the recording's start time
     */
    private function indexSpeakers(int $fileId, array $speakers, array $metadata, DateTimeImmutable $now): void
    {
        if (empty($speakers)) {
            return;
        }

        // Offsets are measured from when the recording started, not from the
        // first speaker; see SpeakerTimeline.
        $starts = array_map(static fn($speaker) => (string) ($speaker['start_time'] ?? ''), $speakers);
        $recordingStart = SpeakerTimeline::recordingStart($metadata, $starts);

        // Keep each name's matched legislator across a re-index, so correcting
        // the times does not undo the name matching.
        $matched = [];
        $existing = $this->pdo->prepare('SELECT raw_text, linked_id FROM video_index WHERE file_id = :id AND type = :type AND linked_id IS NOT NULL');
        $existing->execute([':id' => $fileId, ':type' => 'legislator']);
        foreach ($existing->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $matched[$row['raw_text']] = (int) $row['linked_id'];
        }

        $delete = $this->pdo->prepare('DELETE FROM video_index WHERE file_id = :id AND type = :type');
        $delete->execute([':id' => $fileId, ':type' => 'legislator']);

        $stmt = $this->pdo->prepare('INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created) VALUES (:file_id, :time, :shot, :raw, :type, :linked, "n", :created)');
        foreach ($speakers as $speaker) {
            if (empty($speaker['start_time']) || empty($speaker['name'])) {
                continue;
            }

            if ($recordingStart !== null && SpeakerTimeline::isTimestamp($speaker['start_time'])) {
                $seconds = max(0, (SpeakerTimeline::toSeconds($speaker['start_time']) ?? $recordingStart) - $recordingStart);
            } else {
                // Already in HH:MM:SS format, parse to seconds
                $seconds = $this->parseTimeToSeconds($speaker['start_time']);
            }
            $time = $this->formatTime($seconds);

            // Convert time to screenshot number
            $screenshotNumber = $this->secondsToScreenshotNumber($seconds);

            $stmt->execute([
                ':file_id' => $fileId,
                ':time' => $time,
                ':shot' => $screenshotNumber,
                ':raw' => $speaker['name'],
                ':type' => 'legislator',
                ':linked' => $matched[$speaker['name']] ?? null,
                ':created' => $now->format('Y-m-d H:i:s'),
            ]);
        }
    }


    /**
     * Parse HH:MM:SS time string to total seconds.
     *
     * @param string $time Time in HH:MM:SS format
     * @return int Total seconds
     */
    private function parseTimeToSeconds(string $time): int
    {
        [$h, $m, $s] = array_pad(explode(':', $time), 3, 0);
        return ((int) $h) * 3600 + ((int) $m) * 60 + (int) $s;
    }


    /**
     * Format seconds as HH:MM:SS.
     *
     * @param int $seconds Total seconds
     * @return string Time in HH:MM:SS format
     */
    private function formatTime(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * Convert seconds to screenshot number (screenshots are 1 FPS).
     *
     * @param int $seconds Elapsed seconds from video start
     * @return string Screenshot number with leading zeros (e.g., "00000102")
     */
    private function secondsToScreenshotNumber(int $seconds): string
    {
        // Screenshots are 1 per second, numbered starting from 00000001
        // Add 1 since screenshots start at 00000001, not 00000000
        $screenshotNumber = max(1, $seconds + 1);
        return sprintf('%08d', $screenshotNumber);
    }
}
