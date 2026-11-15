<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Transcripts\LocalWhisperTranscriber;

class LocalWhisperTranscriberTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fake_whisper_' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * A stand-in for mlx_whisper: records its arguments, and writes the JSON
     * mlx_whisper would to <output-dir>/words.json.
     */
    private function fakeMlx(string $json): LocalWhisperTranscriber
    {
        $script = $this->dir . '/mlx_whisper';
        file_put_contents($script, "#!/bin/sh\n"
            . 'echo "$@" > ' . escapeshellarg($this->dir . '/args') . "\n"
            . 'while [ $# -gt 0 ]; do if [ "$1" = "--output-dir" ]; then out="$2"; fi; shift; done' . "\n"
            . "cat > \"\$out/words.json\" <<'JSON'\n{$json}\nJSON\n");
        chmod($script, 0755);

        return new LocalWhisperTranscriber('some-model', $script, true);
    }

    public function testTranscribeReturnsSegmentsAndSkipsEmptyOnes(): void
    {
        $whisper = $this->fakeMlx('{"segments":[{"start":1.5,"end":4,"text":" The Senate will come to order. "},{"start":4,"end":5,"text":" "}]}');

        $this->assertSame(
            [['start' => 1.5, 'end' => 4.0, 'text' => 'The Senate will come to order.']],
            $whisper->transcribe('/tmp/audio.mp3')
        );
        // A whole meeting is not fed Whisper's own earlier output, which can make it repeat itself.
        $this->assertStringContainsString('--word-timestamps True --condition-on-previous-text False', (string) file_get_contents($this->dir . '/args'));
    }

    public function testTranscribeWordsAsksForWordTimestamps(): void
    {
        $whisper = $this->fakeMlx('{"segments":[{"words":[{"word":" Order","start":1,"end":1.4}]}]}');

        $this->assertSame([['word' => ' Order', 'start' => 1.0, 'end' => 1.4]], $whisper->transcribeWords('/tmp/audio.mp3'));
        $this->assertStringContainsString('--word-timestamps True --condition-on-previous-text True', (string) file_get_contents($this->dir . '/args'));
    }

    /** video_transcript.text is TINYTEXT: a long segment is split, each piece timed by its words. */
    public function testALongSegmentIsSplitAtItsWords(): void
    {
        $words = [];
        $t = 10.0;
        foreach (['The bill is reported.', ...array_fill(0, 60, 'word')] as $w) {
            foreach (explode(' ', $w) as $part) {
                $words[] = ['word' => ' ' . $part, 'start' => $t, 'end' => $t + 0.4];
                $t += 0.5;
            }
        }
        $text = implode('', array_column($words, 'word'));
        $whisper = $this->fakeMlx(json_encode(['segments' => [['start' => 10, 'end' => $t, 'text' => $text, 'words' => $words]]]));

        $segments = $whisper->transcribe('/tmp/audio.mp3');

        $this->assertGreaterThan(1, count($segments));
        foreach ($segments as $segment) {
            $this->assertLessThanOrEqual(LocalWhisperTranscriber::MAX_SEGMENT_BYTES, strlen($segment['text']));
        }
        $this->assertSame(trim($text), implode(' ', array_column($segments, 'text')), 'No word is lost.');
        $this->assertSame(10.0, $segments[0]['start']);
        $this->assertEqualsWithDelta($segments[0]['end'] + 0.1, $segments[1]['start'], 0.001, 'Each piece starts with its first word.');
    }
}
