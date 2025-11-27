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

    public function testKeepWinsOnOverlap(): void
    {
        $title = 'Finance Committee Commission Update';
        // Contains both "committee" (keep) and "commission" (skip); keep should win.
        $this->assertTrue(VideoFilter::shouldKeep(['title' => $title]));
    }
}
