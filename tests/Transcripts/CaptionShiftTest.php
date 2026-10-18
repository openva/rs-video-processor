<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionShift;

class CaptionShiftTest extends TestCase
{
    public function testShiftsEveryCueInAWebVttDocument(): void
    {
        $webvtt = "WEBVTT\n\n00:00:10.000 --> 00:00:12.500\nHello\n\n01:59:59.900 --> 02:00:01.000\nLater\n";

        $this->assertSame(
            "WEBVTT\n\n00:00:02.800 --> 00:00:05.300\nHello\n\n01:59:52.700 --> 01:59:53.800\nLater\n",
            CaptionShift::webVtt($webvtt, 7.2)
        );
        // Captions that run early move later.
        $this->assertStringContainsString('00:15:00.000 --> 00:15:02.500', CaptionShift::webVtt($webvtt, -890.0));
        // Nothing goes below zero.
        $this->assertStringContainsString('00:00:00.000 --> 00:00:00.000', CaptionShift::webVtt($webvtt, 20.0));
    }

    public function testShiftsTranscriptRowTimes(): void
    {
        $this->assertSame('02:01:19.05', CaptionShift::time('02:01:26.25', 7.2));
        $this->assertSame('00:15:10.00', CaptionShift::time('00:00:10', -900.0));
        $this->assertSame('00:00:00.00', CaptionShift::time('00:00:03', 7.2));
    }

    public function testShiftsSegments(): void
    {
        $this->assertSame(
            [['start' => 3.0, 'end' => 5.0, 'text' => 'a'], ['start' => 0.0, 'end' => 0.0, 'text' => 'b']],
            CaptionShift::segments([['start' => 10.0, 'end' => 12.0, 'text' => 'a'], ['start' => 2.0, 'end' => 4.0, 'text' => 'b']], 7.0)
        );
    }
}
