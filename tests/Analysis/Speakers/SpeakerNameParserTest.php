<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Speakers;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerNameParser;

class SpeakerNameParserTest extends TestCase
{
    public function testReadsANamePlate(): void
    {
        $parser = new SpeakerNameParser();
        $this->assertSame('JEREMY S. MCPIKE', $parser->parse("Senator JEREMY S. MCPIKE\n"));
        $this->assertSame('JENNIFER D. CARROLL FOY', $parser->parse('JENNIFER D. CARROLL FOY'));
    }

    /**
     * When a meeting shows a presentation, the speaker crop takes in a line of
     * the slide. This one, from file #17448 (2025-01-15, frame 940), was stored
     * as a speaker's name and was too long for video_index.raw_text.
     */
    public function testALineOfSlideTextIsNotAName(): void
    {
        $this->assertNull((new SpeakerNameParser())->parse(
            'blishing an External Affairs & Policy Division in the VGC to recommend gaming policy decisions to legislators'
        ));
    }
}
