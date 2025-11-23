<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Speakers;

use PHPUnit\Framework\TestCase;

/**
 * Stub for verifying real-world fixtures once the expected speaker lists are populated.
 */
class SpeakerDetectionFixtureTest extends TestCase
{
    /**
     * Fill in the `expected` arrays with timestamp => legislator name (e.g., '00:00:15' => 'Delegate Smith').
     */
    private const FIXTURES = [
        'house-floor' => [
            'path' => __DIR__ . '/../../fixtures/house-floor.mp4',
            'expected' => [
                '00:00:25' => 'Senator Richard H. Stuart',
                '00:01:41' => 'Senator Danica A. Roem',
                '00:04:02' => 'Senator Stella G. Pekarsky',
                '00:08:08' => 'Senator Adam P. Ebbin',
                '00:09:54' => 'Senator Ghazala F. Hashmi',
            ],
        ],
        'house-committee' => [
            'path' => __DIR__ . '/../../fixtures/house-committee.mp4',
            'expected' => [
                '00:00:00' => 'Delegate Torian',
                '00:00:17' => 'Delegate Sickles',
                '00:02:41' => 'Delegate Torian',
                '00:04:09' => 'Delegate Sickles',
            ],
        ],
        'senate-floor' => [
            'path' => __DIR__ . '/../../fixtures/senate-floor.mp4',
            'expected' => [
                '00:00:025' => 'Senator Richard H. Stuart',
                '00:01:40' => 'Danica A. Roem',
                '00:04:02' => 'Senator Stella G. Pekarsky',
                '00:08:01' => 'Senator Adam P. Ebbin',
                '00:09:56' => 'Senator Ghazala F. Hashmi',
            ],
        ],
        'senate-committee' => [
            'path' => __DIR__ . '/../../fixtures/senate-committee.mp4',
            'expected' => [
                '00:00:12' => 'Senator David R. Suetterlein',
                '00:00:50' => 'Senator Ghazala F. Hashmi',
                '00:01:47' => 'Senator Ghazala F. Hashmi',
                '00:02:50' => 'Senator Ghazala F. Hashmi',
                '00:03:45' => 'Senator Ghazala F. Hashmi',
                '00:04:30' => 'Senator Stella G. Pekarsky',
            ],
        ],
    ];

    public function testHouseFloorSpeakers(): void
    {
        $this->assertFixtureTodo('house-floor');
    }

    public function testHouseCommitteeSpeakers(): void
    {
        $this->assertFixtureTodo('house-committee');
    }

    public function testSenateFloorSpeakers(): void
    {
        $this->assertFixtureTodo('senate-floor');
    }

    public function testSenateCommitteeSpeakers(): void
    {
        $this->assertFixtureTodo('senate-committee');
    }

    private function assertFixtureTodo(string $key): void
    {
        $fixture = self::FIXTURES[$key];
        if (empty($fixture['expected'])) {
            $this->markTestIncomplete(sprintf('Populate expected speaker names for %s in %s', $key, __CLASS__));
        }
        $this->fail('Populate the expected speaker names array before enabling this assertion.');
    }
}
