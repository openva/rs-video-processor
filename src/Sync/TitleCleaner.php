<?php

namespace RichmondSunlight\VideoProcessor\Sync;

/**
 * Reduces a scraped video title to the name of the meeting.
 *
 * Listings pad titles with the date, the start time, the room and scheduling
 * notes: "January 9, 2019 - Senate Privileges and Elections (SR A) - 15 min
 * after adjournment" is the Privileges and Elections committee. The patterns
 * here were taken from every such title in the 2018-2026 records.
 *
 * Anything not recognised as padding is kept, so an unfamiliar format comes
 * through unchanged rather than mangled.
 */
class TitleCleaner
{
    private const MONTH = '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\.?';
    private const TIME = '\d{1,2}(?::\d{2})?\s*(?:a\.?\s?m\b\.*|p\.?\s?m\b\.*|m\b\.*|noon\b)';

    /**
     * The title stored on the files row: chamber, then meeting name.
     */
    public static function storedTitle(string $raw, string $chamber, string $eventType): string
    {
        $chamberName = ucfirst(strtolower($chamber));
        $name = $eventType === 'floor' ? '' : self::meetingName($raw, $chamber);

        return $name === '' ? $chamberName . ' Session' : $chamberName . ' ' . $name;
    }

    /**
     * The meeting's name with dates, times, rooms and scheduling notes removed,
     * and without a leading chamber word ("Senate Rules" becomes "Rules").
     */
    public static function meetingName(string $raw, string $chamber = ''): string
    {
        $title = trim(str_replace(['–', '—'], '-', $raw));

        // Senate YouTube: "Senate of Virginia: <meeting> on 2026-02-04 [Finished]".
        if (preg_match('/^Senate of Virginia:\s*(.+?)\s+on\s+\d{4}-\d{2}-\d{2}\b/i', $title, $m)) {
            $title = $m[1];
        }

        // One video can hold two meetings: "SESSION - 11:00 am; Judiciary, upon recess".
        $meetings = [];
        foreach (preg_split('/\s*;\s*/', $title) ?: [] as $meeting) {
            $cleaned = self::cleanMeeting($meeting, $chamber);
            if ($cleaned !== '') {
                $meetings[] = $cleaned;
            }
        }

        return implode('; ', $meetings);
    }

    private static function cleanMeeting(string $meeting, string $chamber): string
    {
        // A dash with a space on at least one side separates the parts; a dash
        // inside a word ("K-12", "Procurement-Open") does not.
        $segments = preg_split('/\s+-\s*|\s*-\s+/', $meeting) ?: [];

        $kept = [];
        foreach ($segments as $segment) {
            $segment = self::stripBrackets($segment);
            // Within a part, commas separate either padding ("SR A, 1:00 pm") or
            // words of a committee name ("Agriculture, Conservation and ...").
            $parts = array_filter(
                array_map('trim', explode(',', $segment)),
                static fn(string $part): bool => $part !== '' && !self::isPadding($part)
            );
            $segment = self::stripTrailingTime(implode(', ', $parts));
            if ($segment !== '' && !self::isPadding($segment)) {
                $kept[] = $segment;
            }
        }

        $name = trim(implode(' - ', $kept), " \t.,:-");

        if ($chamber !== '') {
            // ...but not from "Senate Chamber" or "Senate Session", which need it.
            $name = (string) preg_replace('/^' . preg_quote($chamber, '/') . '\s+(?!Chamber\b|Session\b)(?=\S)/i', '', $name);
        }
        if ($name !== '' && $name === strtoupper($name) && preg_match('/[A-Z]{4}/', $name)) {
            $name = ucwords(strtolower($name)); // "SESSION" -> "Session"
        }

        return trim((string) preg_replace('/\s{2,}/', ' ', $name));
    }

    /**
     * Remove bracketed rooms, times and notes; keep meaningful brackets such as
     * "(MEI)" or "(SJ 28 Subcommittee)".
     */
    private static function stripBrackets(string $text): string
    {
        $padding = '/\s*\(\s*(?:'
            . 'SR\s*[A-Z0-9]+'                                  // (SR A), (SR3)
            . '|Comm(?:ittee)?\.?\s+R(?:oo)?m\.?\s+[A-Z0-9]+'   // (Comm Room B), (Comm Rm B)
            . '|virtual'
            . '|p(?:ar)?t\.?\s*\d+|part\s+\d+'                  // (pt 2), (Part 3)
            . '|' . self::TIME . '[^)]*'                        // (12 m.), (6:00 pm)
            . '|\d+(?:st|nd|rd|th)\s+floor'
            . '|[^)]*\b(?:adjournment|recess|backup|open session|partial clip|technical difficult)[^)]*'
            . '|\d+'                                            // (305)
            . ')\s*\)/i';

        return trim((string) preg_replace($padding, '', $text));
    }

    /** Remove a start time and anything after it: "Session 4:00 pm", "Judiciary at 3:00 p.m.". */
    private static function stripTrailingTime(string $text): string
    {
        return trim((string) preg_replace('/\s*\b(?:at\s+)?' . self::TIME . '.*$/i', '', $text));
    }

    private static function isPadding(string $part): bool
    {
        $part = trim($part, " \t.");
        if ($part === '') {
            return true;
        }

        $patterns = [
            '/^' . self::MONTH . '\s+\d{1,2},?\s+\d{4}$/i',            // January 9, 2019
            '/^' . self::TIME . '/i',                                   // 10:00 am ...
            '/^\d{1,2}:\d{2}\b/',                                       // 9:30
            '/^\d+\s*(?:min|in|hour|hr)s?\b/i',                         // 15 min after ..., 1 hour ...
            '/^(?:immediately\s+)?(?:upon|after|following)\b/i',        // upon adjournment ...
            '/^SR\s*[A-Z0-9]+(?:\s*\(\d+\))?$/i',                       // SR A, SR 3 (305)
            '/^(?:Senate|House)\s+Room\s+[A-Z0-9]+$/i',                 // Senate Room 3
            '/^Comm(?:ittee)?\.?\s+R(?:oo)?m\.?\s+[A-Z0-9]+$/i',
            '/^Capitol$/i',
            '/^p(?:ar)?t\.?\s*\d+$/i',                                  // part 2, pt.1
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $part)) {
                return true;
            }
        }

        return false;
    }
}
