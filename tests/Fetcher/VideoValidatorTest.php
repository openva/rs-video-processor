<?php

namespace RichmondSunlight\VideoProcessor\Tests\Fetcher;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\VideoValidator;

class VideoValidatorTest extends TestCase
{
    public function testRejectsAFileTooSmallToBeAVideo(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tiny');
        file_put_contents($path, '<html>502 Bad Gateway</html>');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/not a valid video/i');
            (new VideoValidator())->assertValid($path);
        } finally {
            @unlink($path);
        }
    }

    public function testAcceptsARealVideo(): void
    {
        exec('ffprobe -version > /dev/null 2>&1', $output, $status);
        $fixture = __DIR__ . '/../fixtures/house-floor.mp4';
        if ($status !== 0 || !file_exists($fixture)) {
            $this->markTestSkipped('Needs ffprobe and the house-floor.mp4 fixture.');
        }

        (new VideoValidator())->assertValid($fixture);
        $this->addToAssertionCount(1);
    }
}
