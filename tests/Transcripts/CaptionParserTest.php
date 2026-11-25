<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

class CaptionParserTest extends TestCase
{
    /** YouTube's automatic captions for the Senate floor session of March 2, 2026, 01:09:13–01:09:28. */
    private const YOUTUBE_CAPTIONS = __DIR__ . '/../fixtures/senate-youtube.vtt';

    /**
     * Each line once, in order, as said. The excerpt begins with a fleeting cue holding
     * "Madam President, can that bill go by", which was the line before.
     */
    private const YOUTUBE_LINES = [
        'temporarily, please?',
        'Without objection, House Bill 21 goes by',
        'temporarily. House Bill 40,',
        'a bill relating to manufacture, import,',
        'sale, transfer or possession of plastic',
    ];

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

    public function testYouTubeRollingCaptionsGiveEachLineOnce(): void
    {
        $segments = (new CaptionParser())->parseWebVtt((string) file_get_contents(self::YOUTUBE_CAPTIONS));

        $this->assertSame(self::YOUTUBE_LINES, array_column($segments, 'text'));
        // Each line keeps the cue in which it was spoken.
        $this->assertEqualsWithDelta(4154.799, $segments[1]['start'], 0.001);
        $this->assertEqualsWithDelta(4157.829, $segments[1]['end'], 0.001);
    }

    /**
     * Before November 2026 the rolling captions were stored as they were read, every line three
     * times, one cue per line in files.webvtt and one video_transcript row per cue. Parsing that
     * again gives what the captions should have given.
     */
    public function testRepairsCaptionsStoredWithEveryLineThreeTimes(): void
    {
        $stored = [
            [4153.749, 4153.759, '&gt;&gt; Madam President, can that bill go by'],
            [4153.759, 4154.789, '&gt;&gt; Madam President, can that bill go by temporarily, please?'],
            [4154.789, 4154.799, 'temporarily, please?'],
            [4154.799, 4157.829, 'temporarily, please? &gt;&gt; Without objection, House Bill 21 goes by'],
            [4157.829, 4157.839, '&gt;&gt; Without objection, House Bill 21 goes by'],
            [4157.839, 4162.550, '&gt;&gt; Without objection, House Bill 21 goes by temporarily. House Bill 40,'],
        ];
        $webVtt = TranscriptWriter::generateWebVtt(array_map(
            static fn (array $cue) => ['start' => $cue[0], 'end' => $cue[1], 'text' => $cue[2]],
            $stored
        ));
        $parser = new CaptionParser();

        $this->assertSame(
            [null, 'temporarily, please?', null, 'Without objection, House Bill 21 goes by', null, 'temporarily. House Bill 40,'],
            array_map(static fn (?array $cue) => $cue['text'] ?? null, $parser->unroll($parser->webVttCues($webVtt)))
        );
    }

    public function testEntitiesAreDecodedAndSpeakerMarksRemoved(): void
    {
        $vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:03.000\n&gt;&gt; Thank you, Madam President.\n"
            . "&gt;&gt; Senator O&#39;Quinn &amp; Senator Surovell\n\n"
            . "00:00:03.000 --> 00:00:05.000\n>> the senate will come to\norder.";

        $segments = (new CaptionParser())->parseWebVtt($vtt);

        $this->assertSame([
            "Thank you, Madam President. Senator O'Quinn & Senator Surovell",
            'the senate will come to order.',
        ], array_column($segments, 'text'));
    }

    public function testACueRepeatingTheOneBeforeIsKeptOutsideRollingCaptions(): void
    {
        $vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nAye.\n\n00:00:02.000 --> 00:00:03.000\nAye.\n\n"
            . "00:00:03.000 --> 00:00:04.000\nAye. The motion carries.";

        $segments = (new CaptionParser())->parseWebVtt($vtt);

        $this->assertSame(['Aye.', 'Aye.', 'Aye. The motion carries.'], array_column($segments, 'text'));
    }

    public function testACueLeftWithOnlyASpeakerMarkIsDropped(): void
    {
        $vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n&gt;&gt;\n\n00:00:02.000 --> 00:00:03.000\nHello";

        $this->assertSame(['Hello'], array_column((new CaptionParser())->parseWebVtt($vtt), 'text'));
    }
}
