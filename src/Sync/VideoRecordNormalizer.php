<?php

namespace RichmondSunlight\VideoProcessor\Sync;

use DateTimeImmutable;

class VideoRecordNormalizer
{
    public static function deriveMeetingDate(array $record): ?string
    {
        $candidates = [
            $record['meeting_date'] ?? null,
            $record['scheduled_start'] ?? null,
            $record['actual_start'] ?? null,
            $record['date'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (!$value) {
                continue;
            }

            $normalized = self::normalizeDate($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    public static function deriveDurationSeconds(array $record): ?int
    {
        $candidates = [
            $record['duration_seconds'] ?? null,
            $record['duration'] ?? null,
            $record['length'] ?? null,
        ];

        foreach ($candidates as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_numeric($value)) {
                $seconds = (int) $value;
                if ($seconds > 0) {
                    return $seconds;
                }
            } elseif (is_string($value)) {
                $seconds = self::parseTimeString($value);
                if ($seconds !== null) {
                    return $seconds;
                }
            }
        }

        return null;
    }

    private static function normalizeDate(string $value): ?string
    {
        try {
            $dt = new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        return $dt->format('Y-m-d');
    }

    private static function parseTimeString(string $time): ?int
    {
        $parts = explode(':', $time);
        if (count($parts) !== 3) {
            return null;
        }

        [$hours, $minutes, $seconds] = array_map('intval', $parts);

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }
}
