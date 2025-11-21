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
}
