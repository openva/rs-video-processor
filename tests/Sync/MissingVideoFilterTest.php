<?php

namespace RichmondSunlight\VideoProcessor\Tests\Sync;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Sync\ExistingVideoKeyProviderInterface;
use RichmondSunlight\VideoProcessor\Sync\MissingVideoFilter;

class MissingVideoFilterTest extends TestCase
{
    public function testFiltersOutVideosAlreadyInFiles(): void
    {
        $provider = new InMemoryKeyProvider([
            'house|2025-01-31|3300' => true,
        ]);
        $filter = new MissingVideoFilter($provider);

        $records = [
            [
                'chamber' => 'house',
                'scheduled_start' => '2025-01-31T13:40:09',
                'duration_seconds' => 3300,
            ],
            [
                'chamber' => 'senate',
                'meeting_date' => '2025-11-19',
                'duration_seconds' => 3600,
            ],
        ];

        $result = $filter->filter($records);

        $this->assertCount(1, $result);
        $this->assertSame('senate', $result[0]['chamber']);
    }

    public function testRecordsWithoutCompleteMetadataRemain(): void
    {
        $provider = new InMemoryKeyProvider([]);
        $filter = new MissingVideoFilter($provider);

        $records = [
            [
                'chamber' => 'house',
                'scheduled_start' => '2025-03-15T10:00:00',
                'duration_seconds' => null,
            ],
        ];

        $result = $filter->filter($records);

        $this->assertCount(1, $result);
    }

    public function testParsesDurationFromTimeString(): void
    {
        $provider = new InMemoryKeyProvider([
            'senate|2025-11-19|3900' => true,
        ]);
        $filter = new MissingVideoFilter($provider);

        $records = [
            [
                'chamber' => 'senate',
                'meeting_date' => '2025-11-19',
                'length' => '01:05:00',
            ],
            [
                'chamber' => 'senate',
                'meeting_date' => '2025-11-19',
                'length' => '01:10:00',
            ],
        ];

        $result = $filter->filter($records);

        $this->assertCount(1, $result);
        $this->assertSame('01:10:00', $result[0]['length']);
    }
}

class InMemoryKeyProvider implements ExistingVideoKeyProviderInterface
{
    /**
     * @param array<string,bool> $keys
     */
    public function __construct(private array $keys)
    {
    }

    public function fetchKeys(): array
    {
        return $this->keys;
    }
}
