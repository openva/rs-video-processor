<?php

namespace RichmondSunlight\VideoProcessor\Tests\Sync;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Sync\TitleCleaner;

class TitleCleanerTest extends TestCase
{
    /**
     * Scraped titles, taken from the 2018-2026 records, and the meeting name
     * that should remain once dates, times, rooms and scheduling notes are gone.
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function titles(): array
    {
        return [
            'date, room, minutes after' => ['January 9, 2019 - Senate Privileges and Elections (SR A) - 15 min after adjournment', 'senate', 'Privileges and Elections'],
            'session with noon time' => ['March 5, 2018 - Regular Session (12 m.)', 'senate', 'Regular Session'],
            'room glued to dash' => ['Commerce and Labor (SR A)- February 7, 2018 - 20 min. after adjournment', 'senate', 'Commerce and Labor'],
            'room without space' => ['February 4, 2019 - General Laws and Technology (SR3) - 45 min. after adjournment', 'senate', 'General Laws and Technology'],
            'upon recess of session' => ['January 13, 2021 - General Laws and Technology - upon recess of Session', 'senate', 'General Laws and Technology'],
            'room segment with number' => ['April 1, 2025 - Rules - SR A (305) - 10:00 a.m.', 'senate', 'Rules'],
            'comma in a committee name' => ['February 10, 2020 - Agriculture, Conservation and Natural Resources (SR A) - 30 min. after adjournment', 'senate', 'Agriculture, Conservation and Natural Resources'],
            'abbreviated month, time first' => ['Aug 17, 2018 - 9:30 AM - Joint Meeting of Senate Finance, House Appropriations (Comm Room B)', 'senate', 'Joint Meeting of Senate Finance, House Appropriations'],
            'virtual' => ['August 3, 2020 - Privileges and Elections (Virtual) at 1:00 pm', 'senate', 'Privileges and Elections'],
            'part number' => ['August 20, 2020 - Judiciary - 9:30 am - part 2', 'senate', 'Judiciary'],
            'pt in brackets' => ['February 15, 2023 - Judiciary - SR A - 30 min. after adjournment (pt 2)', 'senate', 'Judiciary'],
            'pt after time' => ['January 18, 2021 - Judiciary - 8:00 am pt.1', 'senate', 'Judiciary'],
            'time in brackets' => ['February 5, 2021 - SESSION (6:00 pm)', 'senate', 'Session'],
            'time glued to name' => ['October 16, 2020 - Session 4:00 pm', 'senate', 'Session'],
            'chamber word and time' => ['September 23, 2020 - Senate Rules 11:00 am', 'senate', 'Rules'],
            'room and time after commas' => ['December 3, 2019 - General Laws and Technology, SR A, 1:00 pm', 'senate', 'General Laws and Technology'],
            'floor note in brackets' => ['January 18, 2023 - SFAC: Public Safety Subcommittee (4th floor)', 'senate', 'SFAC: Public Safety Subcommittee'],
            'long bracketed schedule' => ['February 8, 2022 - SFAC: Resources subcommittee (after K-12 subcommittee and upon adjournment of Public Safety)', 'senate', 'SFAC: Resources subcommittee'],
            'trim backup' => ['April 16, 2024 - Major Employment Investment (MEI) - 2:00 pm (Auto-Generated Trim Backup)', 'senate', 'Major Employment Investment (MEI)'],
            'following another meeting' => ['February 1, 2021 - Special Subcommittee on Gaming - following Commerce and Labor', 'senate', 'Special Subcommittee on Gaming'],
            'two meetings on one video' => ['February 19, 2021 - SESSION - 11:00 am; Judiciary, upon recess', 'senate', 'Session; Judiciary'],
            'room, capitol, recess' => ['August 4, 2021 - Senate Finance and Appropriations - SR3, Capitol, upon recess or adjournment of Session', 'senate', 'Finance and Appropriations'],
            'typo in minutes' => ['September 10, 2020 - Judiciary - 15 in after Session', 'senate', 'Judiciary'],
            'trailing dots' => ['February 16, 2024 - Agriculture, Conservation and Natural Resources - 8:00 am..', 'senate', 'Agriculture, Conservation and Natural Resources'],
            'senate room' => ['November 30, 2023 - Chesapeake Bay Restoration - Senate Room 3', 'senate', 'Chesapeake Bay Restoration'],
            'senate youtube' => ['Senate of Virginia: Finance and Appropriations on 2026-02-04 [Finished]', 'senate', 'Finance and Appropriations'],
            'senate youtube subcommittee' => ['Senate of Virginia: SFAC: Health and Human Resources on 2026-01-29 [Finished]', 'senate', 'SFAC: Health and Human Resources'],
            'senate youtube archival' => ['Senate of Virginia: Senate Chamber on 2020-02-11 (Part 3) [Archival]', 'senate', 'Senate Chamber'],
            'house dated title' => ['March 10, 2026 - House Page Debate', 'house', 'Page Debate'],
            'already clean' => ['Civil Subcommittee', 'house', 'Civil Subcommittee'],
            'hyphenated name kept' => ['Procurement-Open Government Subcommittee', 'house', 'Procurement-Open Government Subcommittee'],
            'meaningful brackets kept' => ['November 12, 2024 - Northern Virginia Growing Needs of Public Transit (SJ 28 Subcommittee) - 1:00 pm', 'senate', 'Northern Virginia Growing Needs of Public Transit (SJ 28 Subcommittee)'],
            'day of a summit kept' => ['December 4, 2023 - Virginia Education Summit - Day One', 'senate', 'Virginia Education Summit - Day One'],
        ];
    }

    /**
     * @dataProvider titles
     */
    public function testMeetingName(string $raw, string $chamber, string $expected): void
    {
        $this->assertSame($expected, TitleCleaner::meetingName($raw, $chamber));
    }

    public function testStoredTitle(): void
    {
        $this->assertSame('Senate Privileges and Elections', TitleCleaner::storedTitle('January 9, 2019 - Senate Privileges and Elections (SR A) - 15 min after adjournment', 'senate', 'committee'));
        $this->assertSame('Senate Session', TitleCleaner::storedTitle('March 5, 2018 - Regular Session (12 m.)', 'senate', 'floor'));
        $this->assertSame('House Civil Subcommittee', TitleCleaner::storedTitle('Civil Subcommittee', 'house', 'subcommittee'));
        $this->assertSame('House Session', TitleCleaner::storedTitle('', 'house', 'committee'), 'Nothing usable left means a session title.');
    }
}
