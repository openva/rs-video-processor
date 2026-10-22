<?php

namespace RichmondSunlight\VideoProcessor\Sync;

class VideoFilter
{
    /**
     * Names of bodies whose meetings do not advance legislation. Matched as
     * whole words, case-insensitively, against the title and committee name.
     */
    private const NOT_LEGISLATIVE = [
        'task\s*force',
        'work\s*-?\s*group',
        'council',
        'commission',
        'board',
        'authority',
        'retreat',
        'boot\s*camp',
        'capstone',
        'tribute',
        'caucus',
        'public\s+hearings?',
        'jlarc',
        'vrs',
        // Joint meetings study issues; bills go through each chamber's own
        // committees. A joint floor session (Joint Assembly) is kept below.
        'joint',
        // Named because its title says nothing about what kind of body it is.
        'virginia\s+blueprint',
        // Commissions whose listings sometimes leave out the word.
        'civic\s+education',
        'manufacturing\s+development',
        'recurrent\s+flooding',
    ];

    /**
     * The page program: teenagers who serve in each chamber during the session,
     * and hold mock sessions and debates of their own. Matched as a whole word
     * ("Page Mock Session", "House Page Debate", "Senate Page Capstone").
     */
    private const PAGE_PROGRAM = '/\bpages?\b/i';

    /** Whether any of these names is that of a page program event. */
    public static function isPageProgram(?string ...$names): bool
    {
        foreach ($names as $name) {
            if ($name !== null && preg_match(self::PAGE_PROGRAM, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether a scraped video record should be kept: floor sessions,
     * committees and subcommittees, which advance legislation. Meetings of task
     * forces, work groups, councils, commissions and the like are skipped, and
     * so are the pages' mock sessions.
     *
     * This works from names rather than the committees table, because that
     * table lacked several real 2026 committees (Labor and Commerce, Firearms
     * Subcommittee, ...), and matching against it would have dropped them.
     */
    public static function shouldKeep(array $record): bool
    {
        $title = trim((string) ($record['title'] ?? ''));
        $committee = trim((string) ($record['committee_name'] ?? ''));
        if ($title === '' && $committee === '') {
            return true; // can't decide; keep
        }

        // Before the floor check: a page mock session is held in the chamber
        // and titled like a floor session.
        if (self::isPageProgram($title, $committee)) {
            return false;
        }

        if (MeetingClassifier::classify($title, $record['description'] ?? null) === 'floor') {
            return true;
        }

        $pattern = '/\b(?:' . implode('|', self::NOT_LEGISLATIVE) . ')\b/i';

        return !preg_match($pattern, $title . ' ' . $committee);
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
