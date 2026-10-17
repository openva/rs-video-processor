<?php

namespace RichmondSunlight\VideoProcessor\Tests\Sync;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Sync\VideoFilter;

class VideoFilterTest extends TestCase
{
    public function testKeepsDesiredVideos(): void
    {
        $wanted = [
            'October 29, 2025 - Privileges and Elections - SR B',
            'October 29, 2025 - 2024 Special Session I - 3:30 pm',
            'January 23, 2025 - Regular Session - 12:00 m.',
            'January 23, 2025 - SFAC: Health and Human Resources - SR 1300 - 7:30 am',
        ];

        foreach ($wanted as $title) {
            $this->assertTrue(VideoFilter::shouldKeep(['title' => $title]), $title . ' should be kept');
        }
    }

    public function testSkipsUndesiredVideos(): void
    {
        $unwanted = [
            'Virginia State Crime Commission',
            'Commission to Study the History of the Uprooting of Black Communities by Public Institutions of Higher Education in the Commonwealth - Hiring Subcommittee',
            'Joint Legislative Audit and Review Commission',
            'August 26, 2025 - Booker T. Washington Commemorative Commission - SR C (311) - 10:00 am',
            'Regional Public Hearings on the Governor\'s Proposed 2024-2026 Biennium Budget; Virtual Public Hearing - Western Virginia - 10:00 am.',
        ];

        foreach ($unwanted as $title) {
            $this->assertFalse(VideoFilter::shouldKeep(['title' => $title]), $title . ' should be skipped');
        }
    }

    /**
     * Meetings of bodies that do not advance legislation: task forces, work
     * groups, councils, retreats and the like. Taken from real 2026 listings.
     */
    public function testSkipsMeetingsThatDoNotAdvanceLegislation(): void
    {
        $unwanted = [
            'Tribute to Senator Yvonne Miller Work Group',
            'Distributed Energy Resources Task Force',
            'The Virginia Blueprint: Strategies for Boys and Men',
            'Public Body Procurement Workgroup',
            'Page Capstone',
            'Autism Advisory Council',
            'Freedom of Information Advisory Council Meeting',
            'Task Force on Maternal Health Data and Quality Measures',
            'Joint House and Senate ACNR Committee Retreat',
            'Joint House and Senate Bootcamp and Select Committees',
            'Joint Subcommittee on Tax Policy',
            'Joint Rules',
        ];

        foreach ($unwanted as $name) {
            $this->assertFalse(VideoFilter::shouldKeep(['title' => $name]), $name . ' should be skipped');
            $this->assertFalse(
                VideoFilter::shouldKeep(['title' => 'August 17, 2026 - Meeting', 'committee_name' => $name]),
                $name . ' should be skipped when only the committee name says what it is'
            );
        }
    }

    /**
     * Real committees and subcommittees, including ones the committees table
     * did not know in 2026, and floor sessions, which are always kept.
     */
    public function testKeepsCommitteesSubcommitteesAndTheFloor(): void
    {
        $wanted = [
            'Labor and Commerce',
            'Housing-Consumer Protection Subcommittee',
            'Highway Safety and Policy Subcommittee',
            'Communications, Technology and Innovation',
            'Procurement-Open Government Subcommittee',
            'Firearms Subcommittee',
            'Department of Motor Vehicles Subcommittee',
            'Behavioral Health Subcommittee',
            'Rehabilitation and Social Services',
            'House Session',
            'Joint Assembly',
            'Senate of Virginia: Senate Chamber on 2026-02-27 [Finished]',
        ];

        foreach ($wanted as $name) {
            $this->assertTrue(VideoFilter::shouldKeep(['title' => $name]), $name . ' should be kept');
        }
    }

    public function testKeepWinsOnOverlap(): void
    {
        $title = 'Finance Committee Commission Update';
        // Contains both "committee" (keep) and "commission" (skip); skip should win unless session/floor present.
        $this->assertFalse(VideoFilter::shouldKeep(['title' => $title]));
    }

    public function testTitleNormalization(): void
    {
        $cases = [
            'October 29, 2025 - Privileges and Elections - SR B' => 'Privileges and Elections',
            'October 29, 2025 - 2024 Special Session I - 3:30 pm' => '2024 Special Session I',
            'January 23, 2025 - SFAC: Health and Human Resources - SR 1300 - 7:30 am' => 'SFAC: Health and Human Resources',
            'February 12, 2025 - General Laws and Technology - SR B (306) - 30 min. after adjournment' => 'General Laws and Technology',
        ];

        foreach ($cases as $raw => $expected) {
            $this->assertSame($expected, VideoFilter::normalizeTitle($raw), 'Normalization failed for ' . $raw);
        }
    }
}
