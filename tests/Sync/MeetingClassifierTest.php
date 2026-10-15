<?php

namespace RichmondSunlight\VideoProcessor\Tests\Sync;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Sync\MeetingClassifier;

class MeetingClassifierTest extends TestCase
{
    /**
     * Titles as the scrapers record them (before "House "/"Senate " is added).
     *
     * @return array<string,array{0:string,1:?string,2:string}>
     */
    public static function meetings(): array
    {
        $room = 'House Committee Room B, General Assembly Building';

        return [
            'house regular session' => ['February 20, 2026 - Regular Session', 'February 20, 2026 - Regular Session', 'floor'],
            'house pro forma' => ['February 15, 2026 - Pro Forma Session', null, 'floor'],
            'house reconvened' => ['April 22, 2026 - Reconvened Session', null, 'floor'],
            'joint assembly' => ['January 17, 2026 - Joint Assembly', null, 'floor'],
            'senate youtube floor' => ['Senate of Virginia: Senate Chamber on 2026-02-04 [Finished]', null, 'floor'],
            'old row title' => ['House Session', null, 'floor'],
            'house committee' => ['Transportation', $room, 'committee'],
            'joint rules committee' => ['Committee on Joint Rules', $room, 'committee'],
            'senate youtube committee' => ['Senate of Virginia: Finance and Appropriations on 2026-02-04 [Finished]', null, 'committee'],
            'house subcommittee' => ['Civil Subcommittee', 'House North Subcommittee Room, General Assembly Building', 'subcommittee'],
            'numbered subcommittee' => ['Finance Subcommittee #2', $room, 'subcommittee'],
            'senate youtube subcommittee' => ['Senate of Virginia: SFAC: Health and Human Resources on 2026-01-29 [Finished]', null, 'subcommittee'],
        ];
    }

    /**
     * @dataProvider meetings
     */
    public function testClassifies(string $title, ?string $description, string $expected): void
    {
        $this->assertSame($expected, MeetingClassifier::classify($title, $description));
    }

    /**
     * A subcommittee meeting room is not a floor session, even though its
     * description names a room, and its title wins over its description.
     */
    public function testSubcommitteeWinsOverSessionInDescription(): void
    {
        $this->assertSame('subcommittee', MeetingClassifier::classify('Civil Subcommittee', '2026 Regular Session'));
    }
}
