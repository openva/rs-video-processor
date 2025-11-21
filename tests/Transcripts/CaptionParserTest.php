<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;

class CaptionParserTest extends TestCase
{
    public function testParsesWebVtt(): void
    {
        $vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:02.500\nHello world\n\n00:00:02.500 --> 00:00:04.000\nNext line";
        $parser = new CaptionParser();
        $segments = $parser->parseWebVtt($vtt);
        $this->assertCount(2, $segments);
        $this->assertSame('Hello world', $segments[0]['text']);
    }

    public function testParsesSrt(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\nHello\n\n2\n00:00:02,000 --> 00:00:03,000\nWorld";
        $parser = new CaptionParser();
        $segments = $parser->parseSrt($srt);
        $this->assertCount(2, $segments);
        $this->assertSame('World', $segments[1]['text']);
    }
}
