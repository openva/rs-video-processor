<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionAligner;
use RichmondSunlight\VideoProcessor\Transcripts\WordTranscriber;

class CaptionAlignerTest extends TestCase
{
    public function testRecoversCaptionsThatRunLate(): void
    {
        $speech = $this->speech(1, 0, 3600);
        $result = CaptionAligner::measure($this->captions($speech, 7.2), $this->windows($speech, [600, 1500, 2400, 3300]));

        $this->assertTrue($result->aligned, $result->summary());
        $this->assertEqualsWithDelta(7.2, $result->offset, 0.5);
        $this->assertTrue($result->shouldShift());
        $this->assertSame('aligned', $result->status());
    }

    public function testRecoversCaptionsThatRunEarly(): void
    {
        $speech = $this->speech(2, 0, 3600);
        $result = CaptionAligner::measure($this->captions($speech, -26.6), $this->windows($speech, [600, 1500, 2400, 3300]));

        $this->assertTrue($result->aligned, $result->summary());
        $this->assertEqualsWithDelta(-26.6, $result->offset, 0.5);
    }

    /**
     * File 18608's captions were about fifteen minutes early, so the whole
     * caption file is searched, not just the stretch near each window.
     */
    public function testRecoversAnOffsetOfFifteenMinutes(): void
    {
        $speech = $this->speech(3, 0, 5400);
        $result = CaptionAligner::measure($this->captions($speech, -900), $this->windows($speech, [300, 1500, 2700, 3900, 5100]));

        $this->assertTrue($result->aligned, $result->summary());
        $this->assertEqualsWithDelta(-900, $result->offset, 0.5);
        // The first window comes before anything the captions cover.
        $this->assertCount(4, $result->windows);
    }

    public function testSmallOffsetIsLeftAlone(): void
    {
        $speech = $this->speech(4, 0, 3600);
        $result = CaptionAligner::measure($this->captions($speech, 0.4), $this->windows($speech, [600, 1500, 2400]));

        $this->assertTrue($result->aligned);
        $this->assertFalse($result->shouldShift());
        $this->assertSame('not_needed', $result->status());
    }

    /** File 15307: captions from some other recording. */
    public function testRejectsCaptionsOfSomethingElse(): void
    {
        $speech = $this->speech(5, 0, 3600);
        $other = $this->speech(6, 0, 3600);
        // A few phrases in common, at unrelated times, as real meetings share.
        foreach ([100, 900, 1700, 2500, 3100] as $i => $index) {
            array_splice($other, $index, 0, array_slice($speech, 400 * ($i + 1), 4));
        }
        $result = CaptionAligner::measure($this->captions($other, 0), $this->windows($speech, [600, 1500, 2400, 3300]));

        $this->assertFalse($result->aligned, $result->summary());
        $this->assertSame('unaligned', $result->status());
        $this->assertNotSame('', $result->reason);
    }

    public function testRejectsMatchesThatDisagree(): void
    {
        $speech = $this->speech(7, 0, 3600);
        mt_srand(70);
        $captions = [];
        foreach (array_chunk($speech, 3) as $cue) {
            // Each cue off by its own amount, up to 15 seconds either way.
            $shift = mt_rand(-150, 150) / 10;
            $captions[] = [
                'start' => $cue[0]['start'] + $shift,
                'end' => end($cue)['end'] + $shift,
                'text' => implode(' ', array_column($cue, 'word')),
            ];
        }
        $result = CaptionAligner::measure($captions, $this->windows($speech, [600, 1500, 2400, 3300]));

        $this->assertFalse($result->aligned, $result->summary());
        $this->assertStringContainsString('IQR', $result->reason);
    }

    public function testFlagsCaptionsThatDrift(): void
    {
        $speech = $this->speech(8, 0, 3600);
        $captions = [];
        foreach (array_chunk($speech, 3) as $cue) {
            // Five seconds late at the start, twelve by the end.
            $shift = 5 + 7 * $cue[0]['start'] / 3600;
            $captions[] = [
                'start' => $cue[0]['start'] + $shift,
                'end' => end($cue)['end'] + $shift,
                'text' => implode(' ', array_column($cue, 'word')),
            ];
        }
        $result = CaptionAligner::measure($captions, $this->windows($speech, [300, 1200, 2100, 3000]));

        $this->assertFalse($result->aligned, $result->summary());
        $this->assertTrue($result->drift);
        $this->assertStringContainsString('drift', $result->reason);
    }

    /**
     * File 17476: windows at +5.2, +6.4 and +4.2s. Shifted by the median, every
     * part is within about a second of the audio; unshifted, off by five.
     */
    public function testWindowsThatDifferALittleAreStillShifted(): void
    {
        $speech = $this->speech(13, 0, 3600);
        $captions = [];
        foreach (array_chunk($speech, 3) as $cue) {
            $t = $cue[0]['start'];
            $shift = $t < 1200 ? 5.2 : ($t < 2400 ? 6.4 : 4.2);
            $captions[] = ['start' => $t + $shift, 'end' => end($cue)['end'] + $shift, 'text' => implode(' ', array_column($cue, 'word'))];
        }
        $result = CaptionAligner::measure($captions, $this->windows($speech, [300, 1500, 2700]));

        $this->assertTrue($result->aligned, $result->summary());
        $this->assertTrue($result->shouldShift());
        $this->assertStringContainsString('windows disagree by 2.2s', $result->reason);
    }

    /** File 5175: +6.1s rising to +9.3s over eight hours. */
    public function testSlightDriftIsShiftedByTheMedian(): void
    {
        $speech = $this->speech(14, 0, 3600);
        $captions = [];
        foreach (array_chunk($speech, 3) as $cue) {
            $shift = 6.1 + 3.2 * $cue[0]['start'] / 3600;
            $captions[] = ['start' => $cue[0]['start'] + $shift, 'end' => end($cue)['end'] + $shift, 'text' => implode(' ', array_column($cue, 'word'))];
        }
        $result = CaptionAligner::measure($captions, $this->windows($speech, [0, 1100, 2200, 3300]));

        $this->assertTrue($result->aligned, $result->summary());
        $this->assertTrue($result->drift);
        $this->assertStringContainsString('drift', $result->reason);
    }

    /** A phrase said over and over could match any of its sayings. */
    public function testIgnoresPhrasesThatRepeat(): void
    {
        $phrase = ['thank', 'you', 'mister', 'chairman'];
        $speech = [];
        for ($t = 0.0; $t < 3600; $t += 2.0) {
            $speech[] = ['word' => $phrase[(int) ($t / 2) % 4], 'start' => $t, 'end' => $t + 0.5];
        }
        $result = CaptionAligner::measure($this->captions($speech, 5), $this->windows($speech, [600, 1500, 2400]));

        $this->assertSame(0, $result->matches);
        $this->assertFalse($result->aligned);
    }

    /** Roll-up captions repeat the end of the previous cue in each new one. */
    public function testRollUpCaptionsStillAlign(): void
    {
        $speech = $this->speech(9, 0, 3600);
        $captions = [];
        $previous = [];
        foreach (array_chunk($speech, 3) as $cue) {
            $words = array_merge($previous, array_column($cue, 'word'));
            $captions[] = ['start' => $cue[0]['start'] + 6, 'end' => end($cue)['end'] + 6, 'text' => implode(' ', $words)];
            $previous = array_column($cue, 'word');
        }
        $result = CaptionAligner::measure($captions, $this->windows($speech, [600, 1500, 2400]));

        $this->assertTrue($result->aligned, $result->summary());
        $this->assertEqualsWithDelta(6, $result->offset, 1.0);
    }

    public function testCueLongerThanTwentySecondsIsNotUsed(): void
    {
        $speech = $this->speech(10, 0, 3600);
        // All of it in one cue, as file 15257 opens with eight-minute cues.
        $captions = [[
            'start' => 0.0,
            'end' => 3600.0,
            'text' => implode(' ', array_column($speech, 'word')),
        ]];
        $result = CaptionAligner::measure($captions, $this->windows($speech, [600, 1500]));

        $this->assertSame(0, $result->matches);
    }

    public function testWindowsSpreadAcrossWhatTheCaptionsCover(): void
    {
        $aligner = new CaptionAligner($this->createMock(WordTranscriber::class), new AudioExtractor());

        $starts = $aligner->windowStarts([
            ['start' => 10.0, 'end' => 12.0, 'text' => 'a'],
            ['start' => 3500.0, 'end' => 3720.0, 'text' => 'b'],
        ]);
        $this->assertSame([180.0, 1035.0, 1890.0, 2745.0, 3600.0], $starts);

        // Shorter than the part skipped at the start: one window over all of it.
        $this->assertSame([0.0], $aligner->windowStarts([
            ['start' => 5.0, 'end' => 100.0, 'text' => 'a'],
        ]));
    }

    public function testAlignTranscribesEachWindowAndTimesItFromTheVideoStart(): void
    {
        $speech = $this->speech(11, 0, 3600);
        $audio = new class extends AudioExtractor {
            public array $starts = [];

            public function extractWindows(string $videoUrl, array $starts, int $seconds): array
            {
                $paths = [];
                foreach ($starts as $start) {
                    $this->starts[] = $start;
                    $path = tempnam(sys_get_temp_dir(), 'window_');
                    file_put_contents($path, (string) $start);
                    $paths[] = $path;
                }
                return $paths;
            }
        };
        // Hears whatever the speech has in the window, timed from the window's start.
        $transcriber = new class ($speech) implements WordTranscriber {
            public function __construct(private array $speech)
            {
            }

            public function transcribeWords(string $audioPath): array
            {
                $start = (float) file_get_contents($audioPath);
                $words = [];
                foreach ($this->speech as $word) {
                    if ($word['start'] >= $start && $word['start'] < $start + 120) {
                        $words[] = ['word' => $word['word'], 'start' => $word['start'] - $start, 'end' => $word['end'] - $start];
                    }
                }
                return $words;
            }
        };

        $result = (new CaptionAligner($transcriber, $audio))->align($this->captions($speech, -12.5), 'https://example.com/v.mp4');

        $this->assertCount(5, $audio->starts);
        $this->assertTrue($result->aligned, $result->summary());
        $this->assertEqualsWithDelta(-12.5, $result->offset, 0.5);
    }

    public function testAWindowThatFailsIsSkipped(): void
    {
        $speech = $this->speech(12, 0, 3600);
        $audio = new class extends AudioExtractor {
            public function extractWindows(string $videoUrl, array $starts, int $seconds): array
            {
                $paths = [];
                foreach ($starts as $start) {
                    if ($start > 3000) {
                        $paths[] = new \RuntimeException('No audio at 3480s; the video is shorter than that.');
                        continue;
                    }
                    $path = tempnam(sys_get_temp_dir(), 'window_');
                    file_put_contents($path, (string) $start);
                    $paths[] = $path;
                }
                return $paths;
            }
        };
        $transcriber = new class ($speech) implements WordTranscriber {
            public function __construct(private array $speech)
            {
            }

            public function transcribeWords(string $audioPath): array
            {
                $start = (float) file_get_contents($audioPath);
                $words = [];
                foreach ($this->speech as $word) {
                    if ($word['start'] >= $start && $word['start'] < $start + 120) {
                        $words[] = ['word' => $word['word'], 'start' => $word['start'] - $start, 'end' => $word['end'] - $start];
                    }
                }
                return $words;
            }
        };

        $result = (new CaptionAligner($transcriber, $audio))->align($this->captions($speech, 4.0), 'https://example.com/v.mp4');

        $this->assertTrue($result->aligned, $result->summary());
        $this->assertCount(4, $result->windows);
        $this->assertStringContainsString('skipped: No audio', $result->reason);
    }

    public function testExtractingPastTheEndOfTheVideoFails(): void
    {
        exec('ffmpeg -version > /dev/null 2>&1', $output, $status);
        if ($status !== 0) {
            $this->markTestSkipped('ffmpeg is required.');
        }
        $video = sys_get_temp_dir() . '/tone_' . uniqid() . '.m4a';
        exec(sprintf('ffmpeg -y -loglevel error -f lavfi -i sine=frequency=440:duration=10 %s', escapeshellarg($video)));
        $extractor = new AudioExtractor();
        $extractor->retryPauseSeconds = 0;

        try {
            $path = $extractor->extractWindow('file://' . $video, 2, 5);
            $this->assertGreaterThan(1024, filesize($path));
            @unlink($path);

            $both = $extractor->extractWindows('file://' . $video, [1, 60], 5);
            $this->assertFileExists($both[0]);
            @unlink($both[0]);
            $this->assertInstanceOf(\RuntimeException::class, $both[1]);

            // A video that cannot be read says why.
            $unreadable = $extractor->extractWindows('file://' . $video . '.missing', [1], 5);
            $this->assertInstanceOf(\RuntimeException::class, $unreadable[0]);
            $this->assertStringContainsString('No such file or directory', $unreadable[0]->getMessage());

            $this->expectExceptionMessage('No audio at 60s');
            $extractor->extractWindow('file://' . $video, 60, 5);
        } finally {
            @unlink($video);
        }
    }

    /**
     * Made-up words, about 2.5 a second, from a vocabulary large enough that
     * four-word phrases rarely repeat by chance.
     *
     * @return list<array{word:string,start:float,end:float}>
     */
    private function speech(int $seed, float $from, float $to): array
    {
        mt_srand($seed);
        $syllables = ['ba', 'ke', 'lo', 'mi', 'nu', 'ra', 'se', 'ti', 'vo', 'da', 'fe', 'go', 'hu', 'ja', 'po', 'ze', 'cy', 'wa'];
        $vocabulary = [];
        for ($i = 0; $i < 400; $i++) {
            $word = '';
            for ($s = 0, $n = mt_rand(1, 3); $s < $n; $s++) {
                $word .= $syllables[mt_rand(0, count($syllables) - 1)];
            }
            $vocabulary[] = $word;
        }

        $words = [];
        for ($t = $from; $t < $to; $t += 0.4) {
            $words[] = ['word' => $vocabulary[mt_rand(0, 399)], 'start' => round($t, 2), 'end' => round($t + 0.3, 2)];
        }

        return $words;
    }

    /**
     * Three-word cues, $offset seconds from the speech; cues that would start
     * before zero are dropped, as captions cannot.
     */
    private function captions(array $speech, float $offset): array
    {
        $captions = [];
        foreach (array_chunk($speech, 3) as $cue) {
            $start = $cue[0]['start'] + $offset;
            if ($start < 0) {
                continue;
            }
            $captions[] = ['start' => $start, 'end' => end($cue)['end'] + $offset, 'text' => ucfirst(implode(' ', array_column($cue, 'word'))) . '.'];
        }

        return $captions;
    }

    /**
     * What Whisper would hear in two-minute windows: the same words, with times
     * a little off.
     */
    private function windows(array $speech, array $starts): array
    {
        mt_srand(99);
        $windows = [];
        foreach ($starts as $start) {
            $words = [];
            foreach ($speech as $word) {
                if ($word['start'] >= $start && $word['start'] < $start + 120) {
                    $jitter = mt_rand(-3, 3) / 10;
                    $words[] = ['word' => ' ' . $word['word'], 'start' => $word['start'] + $jitter, 'end' => $word['end'] + $jitter];
                }
            }
            $windows[] = ['start' => (float) $start, 'words' => $words];
        }

        return $windows;
    }
}
