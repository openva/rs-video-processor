<?php

namespace RichmondSunlight\VideoProcessor\Sync;

/**
 * Decides whether a scraped video is a floor session, a committee meeting or a
 * subcommittee meeting, from its title and description.
 *
 * The answer chooses where OCR looks for bill numbers and speaker names, which
 * differs between floor and committee video. Records scraped before
 * 2026-02-10 carry "floor" for almost everything (the House scraper's default
 * was inverted that day), so the stored event_type cannot be trusted on its own.
 */
class MeetingClassifier
{
    /**
     * @return string 'floor', 'committee' or 'subcommittee'
     */
    public static function classify(string $title, ?string $description = null): string
    {
        // Subcommittee first: "Joint Subcommittee ..." must not read as a session.
        // The second pattern is the Senate YouTube form, where a committee
        // abbreviation and a colon precede the subcommittee: "SFAC: Resources".
        if (stripos($title, 'subcommittee') !== false || preg_match('/Senate of Virginia: [^:]+: /i', $title)) {
            return 'subcommittee';
        }

        $floorTitle = '/\b(House|Senate|Floor|Regular|Special|Veto|Reconvened|Pro Forma|Commemorative)\s+Session\b'
            . '|\bJoint Assembly\b|\bSenate Chamber\b/i';
        if (preg_match($floorTitle, $title)) {
            return 'floor';
        }

        if ($description !== null && preg_match('/\b(Regular|Special|Veto|Reconvened|Pro Forma)\s+Session\b/i', $description)) {
            // House committee listings put the meeting room in the description;
            // a description that names a room is not evidence of a floor session.
            if (stripos($description, 'Room') === false) {
                return 'floor';
            }
        }

        return 'committee';
    }
}
