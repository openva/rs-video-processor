<?php

namespace RichmondSunlight\VideoProcessor\Tests\Maintenance;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Maintenance\CopyDurationCheck;

class CopyDurationCheckTest extends TestCase
{
    private const ARCHIVED = 'https://archive.org/download/rs-house-20260126-house-session/ia_video_am5g2evujpqs2yIDXfv.mp4';

    /** File 15141: the floor session (59:33) archived as an 83-minute subcommittee meeting. */
    public function testFindsACopyOfAnotherRecording(): void
    {
        $result = (new CopyDurationCheck(static fn() => 5000.0))->check([
            'path' => self::ARCHIVED,
            'video_index_cache' => '{"source": "house", "duration_seconds": 3573}',
            'length' => '00:59:33',
        ]);

        $this->assertSame('mismatch', $result['status']);
        $this->assertSame('the copy is 1:23:20 long but the recording is 0:59:33 (source record): it is another recording', $result['reason']);
    }

    public function testACopyWithinAMinuteMatches(): void
    {
        $check = new CopyDurationCheck(static fn() => 3575.4);

        $this->assertSame('match', $check->check(['path' => self::ARCHIVED, 'video_index_cache' => '{"duration_seconds": 3573}', 'length' => null])['status']);
    }

    /** Files 5175 and 5159: their captions line up with their copies, which are the right ones. */
    public function testSenateLengthsInWholeHoursCoverTheHour(): void
    {
        $check = new CopyDurationCheck(static fn() => 30057.0);
        $senate = ['path' => self::ARCHIVED, 'video_index_cache' => '{"source": "senate", "duration_seconds": 28800}', 'length' => null];

        $this->assertSame('match', $check->check($senate)['status']);

        $result = (new CopyDurationCheck(static fn() => 5000.0))->check($senate);
        $this->assertSame('mismatch', $result['status']);
        $this->assertStringContainsString('8:00:00 to 8:59:59 (source record, hours only)', $result['reason']);
    }

    public function testSenateLengthsCoverTheMinute(): void
    {
        $senate = ['path' => self::ARCHIVED, 'video_index_cache' => '{"source": "senate", "duration_seconds": 7140}', 'length' => null];

        $this->assertSame('match', (new CopyDurationCheck(static fn() => 7140.0 + 59 + 30))->check($senate)['status']);
        $this->assertSame('mismatch', (new CopyDurationCheck(static fn() => 7140.0 + 59 + 61))->check($senate)['status']);
    }

    public function testFallsBackToTheStoredLength(): void
    {
        $result = (new CopyDurationCheck(static fn() => 5730.9))->check([
            'path' => 'https://video.richmondsunlight.com/house/committee/x/20260205-15307.mp4',
            'video_index_cache' => '{"duration_seconds": null}',
            'length' => '01:47:38',
        ]);

        $this->assertSame('mismatch', $result['status']);
        $this->assertSame('files.length', $result['from']);
    }

    public function testCannotJudgeWithoutBothLengths(): void
    {
        $probed = [];
        $check = new CopyDurationCheck(static function (string $url) use (&$probed) {
            $probed[] = $url;
            return null;
        });

        $this->assertSame('unknown', $check->check(['path' => self::ARCHIVED, 'video_index_cache' => null, 'length' => null])['status']);
        $this->assertSame('unknown', $check->check(['path' => self::ARCHIVED, 'video_index_cache' => null, 'length' => '00:10:00'])['status']);
        // A source URL is the source; there is no copy to check.
        $this->assertSame('unknown', $check->check(['path' => 'https://sg001-harmony01.sliq.net/x.mp4', 'video_index_cache' => '{"duration_seconds": 3573}', 'length' => null])['status']);
        $this->assertSame([self::ARCHIVED], $probed);
    }
}
