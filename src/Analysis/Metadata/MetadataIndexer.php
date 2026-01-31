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
        $this->indexSpeakers($fileId, $metadata['speakers'] ?? [], $now);
    }

    /**
     * @param array<int,array{name?:string,start_time?:string}> $speakers
     */
    private function indexSpeakers(int $fileId, array $speakers, DateTimeImmutable $now): void
    {
        if (empty($speakers)) {
            return;
        }

        // Detect if we have ISO timestamps (with dates) or HH:MM:SS times
        $hasIsoTimestamps = false;
        foreach ($speakers as $speaker) {
            if (!empty($speaker['start_time']) && $this->isIsoTimestamp($speaker['start_time'])) {
                $hasIsoTimestamps = true;
                break;
            }
        }

        // For ISO timestamps, find the earliest to use as baseline (video start)
        $firstTimestamp = null;
        if ($hasIsoTimestamps) {
            foreach ($speakers as $speaker) {
                if (empty($speaker['start_time'])) {
                    continue;
                }
                $ts = strtotime($speaker['start_time']);
                if ($ts !== false && ($firstTimestamp === null || $ts < $firstTimestamp)) {
                    $firstTimestamp = $ts;
                }
            }
        }

        $stmt = $this->pdo->prepare('INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created) VALUES (:file_id, :time, :shot, :raw, :type, NULL, "n", :created)');
        foreach ($speakers as $speaker) {
            if (empty($speaker['start_time']) || empty($speaker['name'])) {
                continue;
            }

            if ($hasIsoTimestamps && $firstTimestamp !== null) {
                // Convert ISO timestamp to relative seconds
                $seconds = $this->convertToRelativeSeconds($speaker['start_time'], $firstTimestamp);
                $time = $this->formatTime($seconds);
            } else {
                // Already in HH:MM:SS format, parse to seconds
                $seconds = $this->parseTimeToSeconds($speaker['start_time']);
                $time = $this->formatTime($seconds);
            }

            // Convert time to screenshot number
            $screenshotNumber = $this->secondsToScreenshotNumber($seconds);

            $stmt->execute([
                ':file_id' => $fileId,
                ':time' => $time,
                ':shot' => $screenshotNumber,
                ':raw' => $speaker['name'],
                ':type' => 'legislator',
                ':created' => $now->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Check if a timestamp string is an ISO timestamp (contains date).
     *
     * @param string $timestamp Timestamp string
     * @return bool True if ISO timestamp, false if HH:MM:SS format
     */
    private function isIsoTimestamp(string $timestamp): bool
    {
        // ISO timestamps contain date separators like 'T', '-', or space between date and time
        return (strpos($timestamp, 'T') !== false) ||
               (preg_match('/\d{4}-\d{2}-\d{2}/', $timestamp) === 1);
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
     * Convert ISO timestamp to seconds relative to video start.
     *
     * @param string $timestamp ISO timestamp (e.g., "2025-01-31T13:05:00")
     * @param int $baseTimestamp Unix timestamp to use as baseline (video start)
     * @return int Seconds elapsed from baseline
     */
    private function convertToRelativeSeconds(string $timestamp, int $baseTimestamp): int
    {
        $ts = strtotime($timestamp);
        if ($ts === false) {
            return 0;
        }
        return max(0, $ts - $baseTimestamp);
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
