<?php

namespace RichmondSunlight\VideoProcessor\Maintenance;

/**
 * Whether the copy viewers play (files.path, on archive.org or S3) is the
 * recording the row describes, judged by length.
 *
 * Before every video had its own S3 key, two meetings on one day could be
 * uploaded to the same key, and the second overwrote the first. Rows from then
 * can point at another meeting's video while their screenshots, index and
 * captions belong to their own. Such a copy is rarely the same length as the
 * original, whose length the scraper recorded (duration_seconds).
 */
class CopyDurationCheck
{
    /** Re-encoding and trimming move the length by a few seconds, never this much. */
    private const TOLERANCE_SECONDS = 60;

    /** @var callable(string): ?float */
    private $probe;

    /**
     * @param (callable(string): ?float)|null $probe the length of the video at a URL, in seconds
     */
    public function __construct(?callable $probe = null)
    {
        $this->probe = $probe ?? [self::class, 'ffprobe'];
    }

    /** Only copies this pipeline made are checked; a source URL is the source. */
    public static function isCopy(string $path): bool
    {
        return str_starts_with($path, 'https://archive.org/') || str_starts_with($path, 'https://video.richmondsunlight.com/');
    }

    /**
     * The length of the original recording, as a range: the scraper's
     * duration_seconds, or failing that files.length.
     *
     * The Senate listing gives hours and minutes ("01h 59m"), so a Senate
     * length could be up to a minute longer. Until 2026-10 the scraper also
     * dropped the minutes, so a Senate length in whole hours could be up to an
     * hour longer.
     *
     * @return array{seconds:float,max:float,from:string}|null seconds is the shortest it could be
     */
    public static function sourceSeconds(?string $videoIndexCache, ?string $length): ?array
    {
        $record = json_decode((string) $videoIndexCache, true);
        if (is_array($record) && is_numeric($record['duration_seconds'] ?? null) && (float) $record['duration_seconds'] > 0) {
            $seconds = (float) $record['duration_seconds'];
            if (($record['source'] ?? '') !== 'senate') {
                return ['seconds' => $seconds, 'max' => $seconds, 'from' => 'source record'];
            }
            if ((int) $seconds % 3600 === 0) {
                return ['seconds' => $seconds, 'max' => $seconds + 3599, 'from' => 'source record, hours only'];
            }
            return ['seconds' => $seconds, 'max' => $seconds + 59, 'from' => 'source record'];
        }
        if ($length !== null && preg_match('/^(\d+):(\d{2}):(\d{2})/', $length, $m) && $length !== '00:00:00') {
            $seconds = (float) ((int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3]);
            return ['seconds' => $seconds, 'max' => $seconds, 'from' => 'files.length'];
        }

        return null;
    }

    /**
     * @param array{path:?string,video_index_cache:?string,length:?string} $file
     * @return array{status:string,copy:?float,source:?float,from:string,reason:string}
     *         status is 'match', 'mismatch', or 'unknown'
     */
    public function check(array $file): array
    {
        $path = (string) ($file['path'] ?? '');
        $source = self::sourceSeconds($file['video_index_cache'] ?? null, $file['length'] ?? null);
        $result = ['status' => 'unknown', 'copy' => null, 'source' => $source['seconds'] ?? null, 'from' => $source['from'] ?? '', 'reason' => ''];

        if (!self::isCopy($path)) {
            $result['reason'] = 'files.path is not a copy';
            return $result;
        }
        if ($source === null) {
            $result['reason'] = 'the original length is not recorded';
            return $result;
        }
        $copy = ($this->probe)($path);
        if ($copy === null) {
            $result['reason'] = 'the copy could not be read';
            return $result;
        }

        $result['copy'] = $copy;
        if ($copy < $source['seconds'] - self::TOLERANCE_SECONDS || $copy > $source['max'] + self::TOLERANCE_SECONDS) {
            $result['status'] = 'mismatch';
            $result['reason'] = sprintf(
                'the copy is %s long but the recording is %s%s (%s): it is another recording',
                self::clock($copy),
                self::clock($source['seconds']),
                $source['max'] > $source['seconds'] ? ' to ' . self::clock($source['max']) : '',
                $source['from']
            );
        } else {
            $result['status'] = 'match';
        }

        return $result;
    }

    public static function ffprobe(string $url): ?float
    {
        $cmd = sprintf(
            'ffprobe -v error -rw_timeout 30000000 -show_entries format=duration -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($url)
        );
        $output = trim((string) shell_exec($cmd));

        return is_numeric($output) ? (float) $output : null;
    }

    private static function clock(float $seconds): string
    {
        $seconds = (int) round($seconds);

        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
