<?php

namespace RichmondSunlight\VideoProcessor\Jobs;

/**
 * The order in which every stage takes videos: floor sessions first, then
 * committee meetings, then subcommittee meetings. Each queue sorts by this and
 * then by date, newest first.
 *
 * The kind of meeting is read from the title, because committee_id is not
 * reliable for it: unresolved committees and Senate YouTube videos are often
 * stored with no committee at all.
 */
class ProcessingPriority
{
    /**
     * An SQL expression giving 0 for a floor session, 1 for a committee meeting
     * and 2 for a subcommittee meeting. Anything unrecognised ranks as a committee.
     *
     * Subcommittees are tested first, so "Joint Subcommittee ... Session" is not
     * mistaken for a floor session. The second pattern matches Senate YouTube
     * titles such as "Senate of Virginia: SFAC: Health and Human Resources",
     * where a committee abbreviation and a colon precede the subcommittee name.
     */
    public static function orderSql(string $alias = 'f'): string
    {
        return "CASE
            WHEN {$alias}.title LIKE '%ubcommittee%' OR {$alias}.title LIKE '%Senate of Virginia: %: %' THEN 2
            WHEN {$alias}.title LIKE '%Session%' OR {$alias}.title LIKE '%Senate Chamber%' OR {$alias}.title LIKE '%Floor%' THEN 0
            ELSE 1
        END";
    }
}
