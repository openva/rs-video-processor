<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJob;
use RichmondSunlight\VideoProcessor\Archive\InternetArchiveUploader;

class InternetArchiveUploaderTest extends TestCase
{
    private string $originalHome;

    protected function setUp(): void
    {
        $this->originalHome = getenv('HOME');
    }

    protected function tearDown(): void
    {
        putenv('HOME=' . $this->originalHome);
    }

    public function testUploadsWhenConfigExists(): void
    {
        $home = sys_get_temp_dir() . '/ia-home-' . uniqid();
        mkdir($home . '/.config', 0777, true);
        file_put_contents($home . '/.config/ia.ini', '[s3]\naccess = x\nsecret = y');
        putenv('HOME=' . $home);

        $logger = new TestLogger();
        $commandLog = [];
        $fixture = $this->getVideoFixture('house-committee.mp4');
        $uploader = new InternetArchiveUploader(
            $logger,
            function (string $command, array &$output) use (&$commandLog) {
                $commandLog[] = $command;
                $output[] = 'ok';
                return 0;
            },
            function (string $url) use ($fixture) {
                return $fixture;
            }
        );

        $job = new ArchiveJob(1, 'house', 'Test Video', '2025-01-01', 'https://s3.amazonaws.com/video.richmondsunlight.com/house/floor/file.mp4', 'WEBVTT', null, null, null);
        $metadata = ['title' => 'Test Video'];
        $result = $uploader->upload($job, $metadata);

        $this->assertSame('https://archive.org/details/rs-house-20250101-test-video', $result);
        $this->assertNotEmpty($commandLog);
    }

    public function testLogsMissingConfig(): void
    {
        $home = sys_get_temp_dir() . '/ia-home-' . uniqid();
        mkdir($home, 0777, true);
        putenv('HOME=' . $home);

        $logger = new TestLogger();
        $uploader = new InternetArchiveUploader($logger);
        $job = new ArchiveJob(1, 'house', 'Test Video', '2025-01-01', 'https://s3.amazonaws.com/video.richmondsunlight.com/house/floor/file.mp4', null, null, null, null);
        $result = $uploader->upload($job, ['title' => 'Test']);

        $this->assertNull($result);
        $this->assertNotEmpty($logger->entries);
        $this->assertSame(6, $logger->entries[0]['level']);
    }

    private function getVideoFixture(string $filename): string
    {
        $path = __DIR__ . '/../fixtures/' . $filename;
        if (!file_exists($path)) {
            $this->markTestSkipped('Missing video fixture ' . $filename . '. Run bin/fetch_test_fixtures.php.');
        }
        return $path;
    }
}

class TestLogger extends \Log
{
    public array $entries = [];

    public function put($message, $level)
    {
        $this->entries[] = ['level' => $level, 'message' => $message];
        return true;
    }
}
