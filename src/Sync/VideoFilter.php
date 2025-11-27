<?php

namespace RichmondSunlight\VideoProcessor\Sync;

class VideoFilter
{
    /**
     * Decide whether a scraped video record should be kept.
     * Bias toward inclusion: keep wins if both keep and skip keywords are present.
     */
    public static function shouldKeep(array $record): bool
    {
        $title = isset($record['title']) ? strtolower((string) $record['title']) : '';
        if ($title === '') {
            return true; // can't decide; keep
        }

        $keepKeywords = [
            'session',
            'floor',
            'committee',
            'subcommittee',
            'sfac',
            'finance',
            'appropriations',
            'education and health',
            'general laws',
            'technology',
            'transportation',
            'courts of justice',
            'privileges and elections',
            'rules',
            'public safety',
            'health and human resources',
            'resources',
        ];

        $skipKeywords = [
            'commission',
            'board',
            'authority',
            'jlarc',
            'vrs',
            'crime commission',
            'code commission',
            'civic education',
            'manufacturing development',
            'small business commission',
            'health insurance reform commission',
            'recurrent flooding',
            'mei project approval commission',
            'public hearing',
        ];

        $hasKeep = self::containsAny($title, $keepKeywords);
        $hasSessionOrFloor = str_contains($title, 'session') || str_contains($title, 'floor');
        $hasSkip = self::containsAny($title, $skipKeywords);

        // Skip wins unless this is clearly a session/floor item.
        if ($hasSkip && !$hasSessionOrFloor) {
            return false;
        }

        if ($hasKeep) {
            return true;
        }

        // Default to inclusion on tie/unknown.
        return true;
    }

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize a raw title by stripping dates/times/rooms and keeping the core segment.
     */
    public static function normalizeTitle(string $raw): string
    {
        $parts = preg_split('/\s*-\s*/', $raw) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));

        // Drop leading date-like segment.
        if (!empty($parts) && preg_match('/^[A-Za-z]+\s+\d{1,2},\s+\d{4}$/', $parts[0])) {
            array_shift($parts);
        }

        // Remove trailing time/room phrases.
        $parts = array_values(array_filter($parts, static function ($p) {
            $p = strtolower($p);
            if (preg_match('/\b\d{1,2}:\d{2}\s*(am|pm|m\.?)/', $p)) {
                return false;
            }
            if (str_contains($p, 'adjournment')) {
                return false;
            }
            if (preg_match('/^sr\s+[a-z]\b/', $p)) {
                return false;
            }
            if (preg_match('/^sr\s*\d+/', $p)) {
                return false;
            }
            if (preg_match('/^sr\s+[a-z]\s*\(\d+\)/', $p)) {
                return false;
            }
            return true;
        }));

        if (!empty($parts)) {
            return $parts[0];
        }

        return trim($raw);
    }
}
