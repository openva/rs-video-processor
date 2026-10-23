<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Tests\Support\FakeBucket;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;

class AudioExtractorTest extends TestCase
{
    public function testSplitsAudioIntoChunksWithOffsets(): void
    {
        exec('ffmpeg -version > /dev/null 2>&1', $output, $status);
        if ($status !== 0) {
            $this->markTestSkipped('ffmpeg is required for audio extraction tests.');
        }
        $fixture = __DIR__ . '/../fixtures/house-floor.mp4';
        if (!file_exists($fixture)) {
            $this->markTestSkipped('Missing video fixture house-floor.mp4. Run bin/fetch_test_fixtures.php.');
        }

        $chunks = (new AudioExtractor())->extractChunks('file://' . $fixture, 5);

        try {
            $this->assertGreaterThanOrEqual(2, count($chunks), 'A fixture longer than 5 seconds must produce several chunks.');
            $this->assertSame(0.0, $chunks[0]['offset']);
            $this->assertSame(5.0, $chunks[1]['offset']);
            foreach ($chunks as $chunk) {
                $this->assertFileExists($chunk['path']);
                $this->assertGreaterThan(0, filesize($chunk['path']));
            }
        } finally {
            foreach ($chunks as $chunk) {
                @unlink($chunk['path']);
            }
            if (!empty($chunks)) {
                @rmdir(dirname($chunks[0]['path']));
            }
        }
    }

    /** Our own videos come from the bucket, never over HTTPS through CloudFront. */
    public function testDownloadsOurOwnVideosFromTheBucket(): void
    {
        exec('ffmpeg -version > /dev/null 2>&1', $output, $status);
        if ($status !== 0) {
            $this->markTestSkipped('ffmpeg is required for audio extraction tests.');
        }
        // The smallest fixture: Guzzle's mock handler holds the whole body in memory.
        $fixture = __DIR__ . '/../fixtures/senate-committee.mp4';
        if (!file_exists($fixture)) {
            $this->markTestSkipped('Missing video fixture senate-committee.mp4. Run bin/fetch_test_fixtures.php.');
        }
        $bucket = new FakeBucket([new Response(200, ['Content-Type' => 'video/mp4'], fopen($fixture, 'rb'))]);

        $chunks = (new AudioExtractor(null, $bucket->reader))->extractChunks('https://video.richmondsunlight.com/house/floor/20260216-1.mp4', 5);

        try {
            $this->assertNotEmpty($chunks);
            $this->assertSame(['/video.richmondsunlight.com/house/floor/20260216-1.mp4'], $bucket->requestedPaths());
        } finally {
            foreach ($chunks as $chunk) {
                @unlink($chunk['path']);
            }
            if (!empty($chunks)) {
                @rmdir(dirname($chunks[0]['path']));
            }
        }
    }
}
