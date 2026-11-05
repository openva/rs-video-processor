<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\ArchiveCaptionUploader;
use RichmondSunlight\VideoProcessor\Archive\ArchiveMetadata;
use RichmondSunlight\VideoProcessor\Archive\InternetArchiveUploader;
use RichmondSunlight\VideoProcessor\Jobs\NotReadyYet;
use RichmondSunlight\VideoProcessor\Tests\Support\TestLogger;

class ArchiveCaptionUploaderTest extends TestCase
{
    private const OLD_ITEM = 'https://archive.org/download/rs-house-20260309-house-session/ia_video_3u9kpajsffjlaaeB9Lt.mp4';
    private const CAPTIONS = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHello\n";

    private PDO $pdo;
    /** @var array<int,array{command:string,name:string,contents:string}> */
    private array $uploads = [];
    /** @var callable(): int */
    private $exitStatus;
    /** @var string[] */
    private array $output = [];
    /** @var callable(): void|null run during an upload, to change the database meanwhile */
    private $duringUpload = null;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE files (id INTEGER PRIMARY KEY, path TEXT, webvtt TEXT, caption_alignment TEXT)');
        $this->exitStatus = static fn(): int => 0;
    }

    private function video(string $path, ?string $webvtt = self::CAPTIONS, ?string $alignment = 'aligned'): void
    {
        $this->pdo->prepare('INSERT INTO files (id, path, webvtt, caption_alignment) VALUES (1, :path, :webvtt, :alignment)')
            ->execute([':path' => $path, ':webvtt' => $webvtt, ':alignment' => $alignment]);
    }

    /** @param array<string,mixed>|null $item the archive's reply */
    private function uploader(?array $item, ?TestLogger $logger = null): ArchiveCaptionUploader
    {
        return new ArchiveCaptionUploader(
            fn(): PDO => $this->pdo,
            $logger ?? new TestLogger(),
            function (string $command, array &$output): int {
                preg_match("/'([^']+\\.vtt)'/", $command, $m);
                $this->uploads[] = [
                    'command' => $command,
                    'name' => basename($m[1]),
                    'contents' => (string) file_get_contents($m[1]),
                ];
                if ($this->duringUpload) {
                    ($this->duringUpload)();
                }
                $output = $this->output;
                return ($this->exitStatus)();
            },
            static fn(string $identifier): ?array => $item
        );
    }

    private static function item(array $extraFiles = [], bool $pending = false): array
    {
        $files = array_merge([
            ['name' => 'ia_video_3u9kpajsffjlaaeB9Lt.mp4', 'source' => 'original', 'md5' => 'aaa'],
            ['name' => 'ia_vtt_uq4j2q06so0u7xFQUuV.vtt', 'source' => 'original', 'md5' => 'bbb'],
        ], $extraFiles);
        return ['files' => $files, 'pending_tasks' => $pending];
    }

    /** Named after the video in the item, not rebuilt from today's identifier scheme. */
    public function testSendsTheCaptionsNamedAfterTheVideo(): void
    {
        $this->video(self::OLD_ITEM);

        $result = $this->uploader(self::item())->send(1);

        $this->assertSame(ArchiveCaptionUploader::SENT, $result);
        $this->assertCount(1, $this->uploads);
        $this->assertSame('ia_video_3u9kpajsffjlaaeB9Lt.en.vtt', $this->uploads[0]['name']);
        $this->assertSame(self::CAPTIONS, $this->uploads[0]['contents']);
        $this->assertStringStartsWith("TQDM_DISABLE=1 ia upload 'rs-house-20260309-house-session' ", $this->uploads[0]['command']);
    }

    public function testNewItemsAreNamedAfterTheirIdentifier(): void
    {
        $this->video('https://archive.org/download/va-house-20261101-19000/va-house-20261101-19000.mp4');

        $this->uploader(['files' => [['name' => 'va-house-20261101-19000.mp4', 'source' => 'original']]])->send(1);

        $this->assertSame('va-house-20261101-19000.en.vtt', $this->uploads[0]['name']);
    }

    /** The backfill asks for every archived video; most need no upload. */
    public function testIdenticalCaptionsAlreadyThereAreNotSentAgain(): void
    {
        $this->video(self::OLD_ITEM);
        $item = self::item([['name' => 'ia_video_3u9kpajsffjlaaeB9Lt.en.vtt', 'source' => 'original', 'md5' => md5(self::CAPTIONS)]], true);

        $this->assertSame(ArchiveCaptionUploader::UNCHANGED, $this->uploader($item)->send(1));
        $this->assertSame([], $this->uploads);
    }

    public function testDifferentCaptionsUnderTheNameAreReplaced(): void
    {
        $this->video(self::OLD_ITEM);
        $item = self::item([['name' => 'ia_video_3u9kpajsffjlaaeB9Lt.en.vtt', 'source' => 'original', 'md5' => 'old']]);

        $this->assertSame(ArchiveCaptionUploader::SENT, $this->uploader($item)->send(1));
        $this->assertCount(1, $this->uploads);
    }

    public function testNothingToSendWithoutCheckedCaptionsOrOffTheArchive(): void
    {
        $this->video(self::OLD_ITEM, self::CAPTIONS, null);
        $this->assertSame(ArchiveCaptionUploader::NOTHING, $this->uploader(self::item())->send(1));

        $this->pdo->exec("UPDATE files SET caption_alignment = 'aligned', path = 'https://video.richmondsunlight.com/house/floor/x.mp4'");
        $this->assertSame(ArchiveCaptionUploader::NOTHING, $this->uploader(self::item())->send(1));

        $this->pdo->exec("UPDATE files SET path = 'https://archive.org/details/rs-house-20260309-house-session'");
        $this->assertSame(ArchiveCaptionUploader::NOTHING, $this->uploader(self::item())->send(1));
        $this->assertSame([], $this->uploads);
    }

    public function testWaitsWhileTheArchiveIsProcessingTheItem(): void
    {
        $this->video(self::OLD_ITEM);

        try {
            $this->uploader(self::item([], true))->send(1);
            $this->fail('Expected NotReadyYet.');
        } catch (NotReadyYet $e) {
            $this->assertSame(InternetArchiveUploader::RECHECK_SECONDS, $e->retrySeconds);
            $this->assertFalse($e->everyJobWaits);
        }
        $this->assertSame([], $this->uploads);
    }

    public function testAnUnreadableItemIsAFailure(): void
    {
        $this->video(self::OLD_ITEM);

        $this->expectException(\RuntimeException::class);
        $this->uploader(null)->send(1);
    }

    /** "Internet Archive: Temporarily Offline" is the archive's outage, not this video's failure. */
    public function testTheArchiveBeingDownMakesEveryJobWait(): void
    {
        $this->video(self::OLD_ITEM);
        $offline = new Response(503, [], '<html><head><title>Internet Archive: Temporarily Offline</title></head></html>');
        $uploader = new ArchiveCaptionUploader(
            fn(): PDO => $this->pdo,
            new TestLogger(),
            function (string $command, array &$output): int {
                $this->uploads[] = ['command' => $command, 'name' => '', 'contents' => ''];
                return 0;
            },
            null,
            new Client(['handler' => HandlerStack::create(new MockHandler([$offline])), 'http_errors' => false])
        );

        try {
            $uploader->send(1);
            $this->fail('Expected NotReadyYet.');
        } catch (NotReadyYet $e) {
            $this->assertTrue($e->everyJobWaits);
            $this->assertSame(ArchiveMetadata::OFFLINE_RECHECK_SECONDS, $e->retrySeconds);
        }
        $this->assertSame([], $this->uploads);
    }

    public function testATurnedAwayUploadMakesEveryJobWait(): void
    {
        $this->video(self::OLD_ITEM);
        $this->exitStatus = static fn(): int => 1;
        $this->output = ['rs-house-20260309-house-session:', ' error uploading ia_video_3u9kpajsffjlaaeB9Lt.en.vtt: Please reduce your request rate. - total_tasks_queued exceeds global_limit'];

        try {
            $this->uploader(self::item())->send(1);
            $this->fail('Expected NotReadyYet.');
        } catch (NotReadyYet $e) {
            $this->assertTrue($e->everyJobWaits);
            $this->assertSame(InternetArchiveUploader::BUSY_RECHECK_SECONDS, $e->retrySeconds);
        }
    }

    public function testAFailedUploadIsReportedWithoutTheProgressBar(): void
    {
        $this->video(self::OLD_ITEM);
        $this->exitStatus = static fn(): int => 1;
        $this->output = ["\r uploading x.vtt:  50%|#####     | 1/2 [00:01<00:01]", ' error uploading x.vtt: 403 Forbidden'];

        try {
            $this->uploader(self::item())->send(1);
            $this->fail('Expected a failure.');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(NotReadyYet::class, $e);
            $this->assertStringContainsString('403 Forbidden', $e->getMessage());
            $this->assertStringNotContainsString('%|', $e->getMessage());
        }
    }

    /** The transcript stage may write new captions while the old ones are going up. */
    public function testCaptionsChangedDuringTheUploadAreSentAgain(): void
    {
        $this->video(self::OLD_ITEM);
        $this->duringUpload = function (): void {
            $this->duringUpload = null;
            $this->pdo->exec("UPDATE files SET webvtt = 'WEBVTT newer' WHERE id = 1");
        };

        $this->uploader(self::item())->send(1);

        $this->assertSame([self::CAPTIONS, 'WEBVTT newer'], array_column($this->uploads, 'contents'));
    }

    public function testTheTemporaryFileIsRemoved(): void
    {
        $this->video(self::OLD_ITEM);

        $this->uploader(self::item())->send(1);

        preg_match("/'([^']+\\.vtt)'/", $this->uploads[0]['command'], $m);
        $this->assertFileDoesNotExist($m[1]);
        $this->assertDirectoryDoesNotExist(dirname($m[1]));
    }
}
