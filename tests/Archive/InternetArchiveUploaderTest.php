<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJob;
use RichmondSunlight\VideoProcessor\Archive\InternetArchiveUploader;
use RichmondSunlight\VideoProcessor\Tests\Support\FakeBucket;

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
        mkdir($home . '/.config/internetarchive', 0777, true);
        file_put_contents($home . '/.config/internetarchive/ia.ini', '[s3]\naccess = x\nsecret = y');
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
            },
            function (string $identifier) use ($fixture) {
                return [
                    'files' => [
                        ['name' => basename($fixture), 'size' => 1234],
                    ],
                ];
            }
        );

        $job = new ArchiveJob(1, 'house', 'Test Video', '2025-01-01', 'https://video.richmondsunlight.com/house/floor/file.mp4', 'WEBVTT', null, null, null);
        $metadata = ['title' => 'Test Video'];
        $result = $uploader->upload($job, $metadata);

        $this->assertSame('https://archive.org/download/va-house-20250101-1/' . basename($fixture), $result);
        $this->assertNotEmpty($commandLog);
    }

    /** The progress bar ran to hundreds of log lines when an upload failed. */
    public function testAFailedUploadIsLoggedWithoutTheProgressBar(): void
    {
        $home = sys_get_temp_dir() . '/ia-home-' . uniqid();
        mkdir($home . '/.config/internetarchive', 0777, true);
        file_put_contents($home . '/.config/internetarchive/ia.ini', '[s3]');
        putenv('HOME=' . $home);
        $video = tempnam(sys_get_temp_dir(), 'video_') . '.mp4';
        file_put_contents($video, 'not really a video');

        $logger = new TestLogger();
        $commands = [];
        $uploader = new InternetArchiveUploader(
            $logger,
            function (string $command, array &$output) use (&$commands) {
                $commands[] = $command;
                // What exec() collects from ia: the bar redraws itself with \r.
                $bar = '';
                for ($i = 0; $i <= 100; $i += 5) {
                    $bar .= sprintf("\r uploading video.mp4: %3d%%|#####     | %d/1205 [00:%02d<00:40, 30.1MiB/s]", $i, $i * 12, $i % 60);
                }
                $output = ['va-house-20250101-1:', $bar, ' error uploading video.mp4: 503 Slow Down. Please reduce your request rate.'];
                return 1;
            },
            static fn(string $url) => $video
        );

        $result = $uploader->upload(new ArchiveJob(1, 'house', 'Test', '2025-01-01', 'https://video.richmondsunlight.com/house/floor/file.mp4', null, null, null, null), []);

        $this->assertNull($result);
        $this->assertStringStartsWith('TQDM_DISABLE=1 ia upload ', $commands[0], 'ia is told not to draw the bar.');
        $failure = array_values(array_filter($logger->entries, static fn($e) => str_contains($e['message'], 'upload failed')))[0]['message'];
        $this->assertStringContainsString('503 Slow Down', $failure);
        $this->assertStringNotContainsString('%|', $failure);
        $this->assertStringNotContainsString("\r", $failure);
        @unlink($video);
    }

    public function testLogsMissingConfig(): void
    {
        $home = sys_get_temp_dir() . '/ia-home-' . uniqid();
        mkdir($home, 0777, true);
        putenv('HOME=' . $home);

        $logger = new TestLogger();
        $uploader = new InternetArchiveUploader($logger);
        $job = new ArchiveJob(1, 'house', 'Test Video', '2025-01-01', 'https://video.richmondsunlight.com/house/floor/file.mp4', null, null, null, null);
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

    public function put($message, $level = 3)
    {
        $this->entries[] = ['level' => $level, 'message' => $message];
        return true;
    }

    /** Our own videos come from the bucket, never over HTTPS through CloudFront. */
    public function testDownloadsOurOwnVideosFromTheBucket(): void
    {
        $bucket = new FakeBucket([new Response(200, ['Content-Type' => 'video/mp4'], 'MP4DATA')]);
        $uploader = new InternetArchiveUploader(null, null, null, null, null, $bucket->reader);
        $downloader = (new \ReflectionProperty($uploader, 'downloader'))->getValue($uploader);

        $path = $downloader('https://video.richmondsunlight.com/house/floor/20260216-18637.mp4');

        try {
            $this->assertStringEndsWith('.mp4', $path);
            $this->assertSame('MP4DATA', file_get_contents($path));
            $this->assertSame(['/video.richmondsunlight.com/house/floor/20260216-18637.mp4'], $bucket->requestedPaths());
        } finally {
            @unlink($path);
        }
    }
}
