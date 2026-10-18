<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

/**
 * Moves caption times by a measured offset (see CaptionAlignment): each time
 * becomes time minus offset, and none goes below zero.
 */
class CaptionShift
{
    /**
     * @param array<int,array{start:float,end:float,text:string}> $segments
     * @return array<int,array{start:float,end:float,text:string}>
     */
    public static function segments(array $segments, float $offset): array
    {
        foreach ($segments as $i => $segment) {
            $segments[$i]['start'] = max(0.0, $segment['start'] - $offset);
            $segments[$i]['end'] = max(0.0, $segment['end'] - $offset);
        }

        return $segments;
    }

    /** Every cue time (HH:MM:SS.mmm) in a WebVTT document. */
    public static function webVtt(string $webvtt, float $offset): string
    {
        return (string) preg_replace_callback(
            '/\b(\d{2}):(\d{2}):(\d{2})\.(\d{3})\b/',
            static function (array $m) use ($offset): string {
                $seconds = (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3] + (int) $m[4] / 1000;
                $millis = (int) round(max(0.0, $seconds - $offset) * 1000);
                return sprintf(
                    '%02d:%02d:%02d.%03d',
                    intdiv($millis, 3600000),
                    intdiv($millis % 3600000, 60000),
                    intdiv($millis % 60000, 1000),
                    $millis % 1000
                );
            },
            $webvtt
        );
    }

    /** A video_transcript time ("HH:MM:SS" or "HH:MM:SS.ss"), moved. */
    public static function time(string $time, float $offset): string
    {
        $seconds = self::toSeconds($time);
        $centis = (int) round(max(0.0, $seconds - $offset) * 100);

        return sprintf(
            '%02d:%02d:%02d.%02d',
            intdiv($centis, 360000),
            intdiv($centis % 360000, 6000),
            intdiv($centis % 6000, 100),
            $centis % 100
        );
    }

    public static function toSeconds(string $time): float
    {
        $parts = explode(':', $time);
        if (count($parts) !== 3) {
            return 0.0;
        }

        return (int) $parts[0] * 3600 + (int) $parts[1] * 60 + (float) $parts[2];
    }
}
