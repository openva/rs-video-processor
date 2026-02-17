<?php

namespace RichmondSunlight\VideoProcessor\Contract;

use PDO;

/**
 * Port of the front-end's video reading logic from class.Video.php.
 *
 * This mirrors the read-side queries and clip-boundary logic used by
 * richmondsunlight.com to render video clips, screenshots, and transcripts.
 * It must be kept in sync with the front-end — see README.md in this directory.
 */
class VideoReadContract
{
    /** Seconds between frames that triggers a clip split */
    public const CLIP_BOUNDARY_THRESHOLD = 30;

    /** Padding added before the first and after the last frame of a clip */
    public const CLIP_PADDING_SECONDS = 10;

    /** Minimum duration when start == end */
    public const MIN_FUZZ_FOR_SAME_TIME = 15;

    /** Base URL for screenshot/video assets */
    public const VIDEO_BASE_URL = 'https://video.richmondsunlight.com/';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Find video clips for a given bill, mirroring the front-end's by_bill() logic.
     *
     * Queries video_index rows with type='bill' and linked_id matching the bill,
     * joins to files for metadata, then reduces adjacent frames into clips
     * using the boundary threshold.
     *
     * @param int $billId The bills.id to search for
     * @return array<int, array{
     *     file_id: int,
     *     start: int,
     *     end: int,
     *     screenshot: string,
     *     chamber: string,
     *     date: string,
     *     capture_directory: string|null
     * }>
     */
    public function byBill(int $billId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                vi.file_id,
                vi.time,
                vi.screenshot,
                f.chamber,
                f.date,
                f.capture_directory
            FROM video_index vi
            JOIN files f ON f.id = vi.file_id
            WHERE vi.type = :type
              AND vi.linked_id = :bill_id
              AND (vi.ignored IS NULL OR vi.ignored != :ignored)
            ORDER BY f.date DESC, vi.time ASC
        ');

        $stmt->execute([
            ':type' => 'bill',
            ':bill_id' => $billId,
            ':ignored' => 'y',
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->reduceToClips($rows);
    }

    /**
     * Index all clips for a file, grouped by linked_id.
     *
     * Mirrors the front-end's index_clips() logic: fetches all non-ignored
     * video_index rows for a file, groups them by linked_id, and reduces
     * each group into clips.
     *
     * @param int $fileId
     * @return array<int|string, array<int, array{
     *     file_id: int,
     *     start: int,
     *     end: int,
     *     screenshot: string,
     *     chamber: string,
     *     date: string,
     *     capture_directory: string|null,
     *     type: string,
     *     linked_id: int|null
     * }>>
     */
    public function indexClips(int $fileId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                vi.file_id,
                vi.time,
                vi.screenshot,
                vi.type,
                vi.linked_id,
                f.chamber,
                f.date,
                f.capture_directory
            FROM video_index vi
            JOIN files f ON f.id = vi.file_id
            WHERE vi.file_id = :file_id
              AND (vi.ignored IS NULL OR vi.ignored != :ignored)
            ORDER BY vi.time ASC
        ');

        $stmt->execute([
            ':file_id' => $fileId,
            ':ignored' => 'y',
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by linked_id
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['linked_id'] ?? 'unresolved';
            $groups[$key][] = $row;
        }

        $result = [];
        foreach ($groups as $key => $groupRows) {
            $clips = $this->reduceToClips($groupRows);
            // Attach type and linked_id to each clip
            foreach ($clips as &$clip) {
                $clip['type'] = $groupRows[0]['type'];
                $clip['linked_id'] = $groupRows[0]['linked_id'];
            }
            $result[$key] = $clips;
        }

        return $result;
    }

    /**
     * Build a full screenshot URL from a capture_directory and screenshot number.
     *
     * The front-end constructs URLs as:
     *   https://video.richmondsunlight.com/{capture_directory}/{screenshot}.jpg
     *
     * @param string $captureDirectory e.g. "house/floor/20250110"
     * @param string $screenshot       e.g. "00000102"
     * @return string Full URL
     */
    public static function normalizeScreenshotUrl(string $captureDirectory, string $screenshot): string
    {
        // Strip any leading/trailing slashes from capture_directory
        $captureDirectory = trim($captureDirectory, '/');

        // Ensure screenshot has .jpg extension
        if (!str_ends_with($screenshot, '.jpg')) {
            $screenshot .= '.jpg';
        }

        return self::VIDEO_BASE_URL . $captureDirectory . '/' . $screenshot;
    }

    /**
     * Convert HH:MM:SS time string to total seconds.
     *
     * @param string $time Time in HH:MM:SS format
     * @return int Total seconds
     */
    public static function timeToSeconds(string $time): int
    {
        $parts = explode(':', $time);

        if (count($parts) !== 3) {
            return 0;
        }

        return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (int) $parts[2];
    }

    /**
     * Fetch transcript segments for a file.
     *
     * @param int $fileId
     * @return array<int, array{
     *     text: string,
     *     time_start: string,
     *     time_end: string,
     *     new_speaker: string,
     *     legislator_id: int|null
     * }>
     */
    public function getTranscript(int $fileId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT text, time_start, time_end, new_speaker, legislator_id
            FROM video_transcript
            WHERE file_id = :file_id
            ORDER BY time_start ASC
        ');

        $stmt->execute([':file_id' => $fileId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Reduce a set of video_index rows into clips using the boundary threshold.
     *
     * Adjacent rows within CLIP_BOUNDARY_THRESHOLD seconds of each other are
     * merged into a single clip. The clip start is padded backward by
     * CLIP_PADDING_SECONDS, and the end is padded forward.
     *
     * @param array<int, array<string, mixed>> $rows Sorted by time ASC
     * @return array<int, array<string, mixed>>
     */
    private function reduceToClips(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $clips = [];
        $currentClip = null;

        foreach ($rows as $row) {
            $seconds = self::timeToSeconds($row['time']);

            if ($currentClip === null) {
                // Start a new clip
                $currentClip = [
                    'file_id' => (int) $row['file_id'],
                    'start' => $seconds,
                    'end' => $seconds,
                    'screenshot' => $row['screenshot'],
                    'chamber' => $row['chamber'],
                    'date' => $row['date'],
                    'capture_directory' => $row['capture_directory'] ?? null,
                ];
            } else {
                $gap = $seconds - $currentClip['end'];

                if ($gap <= self::CLIP_BOUNDARY_THRESHOLD) {
                    // Extend current clip
                    $currentClip['end'] = $seconds;
                } else {
                    // Finalize current clip and start a new one
                    $clips[] = $this->finalizeClip($currentClip);
                    $currentClip = [
                        'file_id' => (int) $row['file_id'],
                        'start' => $seconds,
                        'end' => $seconds,
                        'screenshot' => $row['screenshot'],
                        'chamber' => $row['chamber'],
                        'date' => $row['date'],
                        'capture_directory' => $row['capture_directory'] ?? null,
                    ];
                }
            }
        }

        // Finalize the last clip
        if ($currentClip !== null) {
            $clips[] = $this->finalizeClip($currentClip);
        }

        return $clips;
    }

    /**
     * Apply padding and minimum duration to a clip.
     */
    private function finalizeClip(array $clip): array
    {
        // Apply padding
        $clip['start'] = max(0, $clip['start'] - self::CLIP_PADDING_SECONDS);
        $clip['end'] = $clip['end'] + self::CLIP_PADDING_SECONDS;

        // Ensure minimum duration when start == end (before padding)
        if ($clip['end'] - $clip['start'] <= (2 * self::CLIP_PADDING_SECONDS)) {
            $midpoint = ($clip['start'] + $clip['end']) / 2;
            $clip['start'] = max(0, (int) ($midpoint - self::MIN_FUZZ_FOR_SAME_TIME));
            $clip['end'] = (int) ($midpoint + self::MIN_FUZZ_FOR_SAME_TIME);
        }

        return $clip;
    }
}
