<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Metadata;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns the House's wall-clock speaker timestamps into seconds into the video.
 *
 * Offsets have to be measured from when the recording started, which is often
 * long before anyone speaks: a floor recording can open with half an hour of
 * a "House is at ease" card. Measuring from the first speaker instead put
 * every speaker that much too early in the video.
 */
class SpeakerTimeline
{
    /**
     * Seconds since the epoch for a timestamp, treating it as a local time.
     *
     * The House site labels some local times "+00:00" and leaves others bare,
     * so any timezone designator is ignored; otherwise the two kinds would be
     * hours apart.
     */
    public static function toSeconds(string $value): ?int
    {
        $value = (string) preg_replace('/(?:Z|[+-]\d{2}:?\d{2})$/i', '', trim($value));
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    public static function isTimestamp(string $value): bool
    {
        return preg_match('/\d{4}-\d{2}-\d{2}/', $value) === 1;
    }

    /**
     * When the recording started, in the same seconds as toSeconds().
     *
     * Prefers the recording's own start ("media_start"), then the meeting's
     * recorded start ("actual_start"), then the first speaker, which is right
     * only when the recording begins with someone speaking. A start later than
     * the first speaker is impossible and is ignored.
     *
     * @param array<string,mixed> $metadata the scraped record
     * @param string[] $speakerStarts raw speaker timestamps
     */
    public static function recordingStart(array $metadata, array $speakerStarts): ?int
    {
        $earliestSpeaker = null;
        foreach ($speakerStarts as $start) {
            if (!self::isTimestamp($start)) {
                continue;
            }
            $seconds = self::toSeconds($start);
            if ($seconds !== null && ($earliestSpeaker === null || $seconds < $earliestSpeaker)) {
                $earliestSpeaker = $seconds;
            }
        }

        foreach (['media_start', 'actual_start'] as $key) {
            $value = $metadata[$key] ?? null;
            if (!is_string($value) || !self::isTimestamp($value)) {
                continue;
            }
            $start = self::toSeconds($value);
            if ($start !== null && ($earliestSpeaker === null || $start <= $earliestSpeaker)) {
                return $start;
            }
        }

        return $earliestSpeaker;
    }
}
