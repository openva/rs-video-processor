<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use PHPUnit\Framework\TestCase;
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
}
