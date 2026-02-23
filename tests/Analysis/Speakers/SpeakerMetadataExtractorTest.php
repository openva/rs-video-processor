<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Speakers;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerMetadataExtractor;

class SpeakerMetadataExtractorTest extends TestCase
{
    public function testExtractsSegments(): void
    {
        $metadata = [
            'Speakers' => [
                ['text' => 'Delegate Smith', 'startTime' => '2025-01-01T13:00:00'],
                ['text' => 'Delegate Jones', 'startTime' => '2025-01-01T13:05:00'],
            ],
        ];
        $extractor = new SpeakerMetadataExtractor();
        $segments = $extractor->extract($metadata);
        $this->assertCount(2, $segments);
        $this->assertSame('Smith', $segments[0]['name']);
    }

    public function testIsoTimestampsProduceRelativeOffsets(): void
    {
        $metadata = [
            'Speakers' => [
                ['text' => 'Speaker A', 'startTime' => '2026-02-11T12:00:00'],
                ['text' => 'Speaker B', 'startTime' => '2026-02-11T12:05:00'],
                ['text' => 'Speaker C', 'startTime' => '2026-02-11T12:10:30'],
            ],
        ];
        $extractor = new SpeakerMetadataExtractor();
        $segments = $extractor->extract($metadata);

        $this->assertCount(3, $segments);
        $this->assertSame(0.0, $segments[0]['start']);
        $this->assertSame(300.0, $segments[1]['start']); // 5 minutes
        $this->assertSame(630.0, $segments[2]['start']); // 10m30s
    }

    public function testHhmmssTimestampsStillWork(): void
    {
        $metadata = [
            'speakers' => [
                ['name' => 'Smith', 'start_time' => '00:00:10'],
                ['name' => 'Jones', 'start_time' => '00:05:00'],
            ],
        ];
        $extractor = new SpeakerMetadataExtractor();
        $segments = $extractor->extract($metadata);

        $this->assertCount(2, $segments);
        $this->assertSame(10.0, $segments[0]['start']);
        $this->assertSame(300.0, $segments[1]['start']);
    }
}
