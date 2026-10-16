<?php

namespace RichmondSunlight\VideoProcessor\Tests\Fetcher;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\VideoMetadataExtractor;

class VideoMetadataExtractorTest extends TestCase
{
    public function testReadsTheVideoStreamWhenAudioComesFirst(): void
    {
        exec('ffmpeg -version > /dev/null 2>&1', $output, $status);
        if ($status !== 0) {
            $this->markTestSkipped('ffmpeg is required.');
        }
        $fixture = __DIR__ . '/../fixtures/house-floor.mp4';
        if (!file_exists($fixture)) {
            $this->markTestSkipped('Missing video fixture house-floor.mp4. Run bin/fetch_test_fixtures.php.');
        }

        // The same video with its audio track first, as some files have.
        $reordered = sys_get_temp_dir() . '/audio-first-' . uniqid() . '.mp4';
        exec(sprintf(
            'ffmpeg -y -loglevel error -t 2 -i %s -map 0:a:0 -map 0:v:0 -c copy %s',
            escapeshellarg($fixture),
            escapeshellarg($reordered)
        ), $output, $status);
        $this->assertSame(0, $status, 'could not build the audio-first fixture');

        try {
            $meta = (new VideoMetadataExtractor())->extract($reordered);
        } finally {
            @unlink($reordered);
        }

        $this->assertEqualsWithDelta(29.97, $meta['fps'], 0.01);
        $this->assertNotNull($meta['width']);
        $this->assertNotNull($meta['height']);
        $this->assertNotNull($meta['duration_seconds']);
    }
}
