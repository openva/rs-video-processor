<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJob;
use RichmondSunlight\VideoProcessor\Archive\InternetArchiveUploader;
use RichmondSunlight\VideoProcessor\Jobs\NotReadyYet;
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

    /** @param array<int,?array> $responses metadata API replies, in order; the last repeats */
    private function uploader(array $responses, array &$commands, ?array &$downloads = null): InternetArchiveUploader
    {
        $home = sys_get_temp_dir() . '/ia-home-' . uniqid();
        mkdir($home . '/.config/internetarchive', 0777, true);
        file_put_contents($home . '/.config/internetarchive/ia.ini', '[s3]');
        putenv('HOME=' . $home);
        $downloads = [];

        $uploader = new InternetArchiveUploader(
            new TestLogger(),
            function (string $command, array &$output) use (&$commands) {
                $commands[] = $command;
                $output[] = 'ok';
                return 0;
            },
            function (string $url) use (&$downloads) {
                $downloads[] = $url;
                $path = tempnam(sys_get_temp_dir(), 'ia_video_') . '.mp4';
                file_put_contents($path, 'video');
                return $path;
            },
            function (string $identifier) use (&$responses) {
                return count($responses) > 1 ? array_shift($responses) : $responses[0];
            }
        );
        $uploader->firstCheckSeconds = 0;

        return $uploader;
    }

    private function job(): ArchiveJob
    {
        return new ArchiveJob(1, 'house', 'Test Video', '2025-01-01', 'https://video.richmondsunlight.com/house/floor/file.mp4', 'WEBVTT', null, null, null);
    }

    /** An item as the metadata API describes it: our original MP4 and what IA derives from it. */
    private static function item(bool $withMp4, bool $pending = false): array
    {
        $files = [['name' => 'va-house-20250101-1_meta.xml', 'source' => 'original']];
        if ($withMp4) {
            $files[] = ['name' => 'ia_video_abc.mp4', 'source' => 'original', 'size' => '685385803'];
            $files[] = ['name' => 'ia_video_abc.ia.mp4', 'source' => 'derivative', 'size' => '90000000'];
        }
        $item = ['metadata' => ['identifier' => 'va-house-20250101-1'], 'files' => $files];
        if ($pending) {
            $item['pending_tasks'] = true;
            $item['tasks'] = [['cmd' => 'archive.php', 'status' => 'queued']];
        }
        return $item;
    }

    public function testUploadsANewVideoAndReturnsItsOriginalMp4(): void
    {
        $commands = [];
        $uploader = $this->uploader([[], self::item(true)], $commands);

        $url = $uploader->upload($this->job(), ['title' => 'Test Video']);

        $this->assertSame('https://archive.org/download/va-house-20250101-1/ia_video_abc.mp4', $url);
        $this->assertCount(1, $commands);
    }

    /** A later try finds the earlier upload finished, and does not upload again. */
    public function testAVideoAlreadyOnTheArchiveIsNotUploadedAgain(): void
    {
        $commands = [];
        $uploader = $this->uploader([self::item(true)], $commands, $downloads);

        $url = $uploader->upload($this->job(), []);

        $this->assertSame('https://archive.org/download/va-house-20250101-1/ia_video_abc.mp4', $url);
        $this->assertSame([], $commands);
        $this->assertSame([], $downloads, 'Nor is it downloaded.');
    }

    public function testAnUploadStillBeingProcessedIsWaitedFor(): void
    {
        $commands = [];
        $uploader = $this->uploader([self::item(false, true)], $commands, $downloads);

        try {
            $uploader->upload($this->job(), []);
            $this->fail('Expected NotReadyYet.');
        } catch (NotReadyYet $e) {
            $this->assertStringContainsString('archive.php queued', $e->getMessage());
            $this->assertSame(InternetArchiveUploader::RECHECK_SECONDS, $e->retrySeconds);
        }
        $this->assertSame([], $commands);
        $this->assertSame([], $downloads);
    }

    public function testANewUploadNotYetProcessedIsWaitedFor(): void
    {
        $commands = [];
        $uploader = $this->uploader([[], self::item(false, true)], $commands);

        $this->expectException(NotReadyYet::class);
        try {
            $uploader->upload($this->job(), []);
        } finally {
            $this->assertCount(1, $commands, 'It was uploaded once.');
        }
    }

    /** An item left without its video and with nothing pending was a failed upload. */
    public function testAnItemWithoutItsVideoAndNothingPendingIsUploadedAgain(): void
    {
        $commands = [];
        $uploader = $this->uploader([self::item(false), self::item(true)], $commands);

        $this->assertNotNull($uploader->upload($this->job(), []));
        $this->assertCount(1, $commands);
    }

    /** If the archive cannot be asked, uploading might duplicate an upload in progress. */
    public function testNothingIsUploadedWhenTheArchiveCannotBeChecked(): void
    {
        $commands = [];
        $uploader = $this->uploader([null], $commands, $downloads);

        $this->assertNull($uploader->upload($this->job(), []));
        $this->assertSame([], $commands);
        $this->assertSame([], $downloads);
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
            static fn(string $url) => $video,
            static fn(string $identifier) => []
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
