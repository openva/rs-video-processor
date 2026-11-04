<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\ColdStorage;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJob;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobProcessor;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;
use RichmondSunlight\VideoProcessor\Archive\InternetArchiveUploader;
use RichmondSunlight\VideoProcessor\Archive\MetadataBuilder;
use RichmondSunlight\VideoProcessor\Jobs\NotReadyYet;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class ArchiveJobProcessorTest extends TestCase
{
    public function testProcessesJobsAndUpdatesDatabase(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec("INSERT INTO files (id, path) VALUES (1, 'https://video.richmondsunlight.com/house/floor/file.mp4')");

        $job = new ArchiveJob(
            1,
            'house',
            'Floor Session',
            '2025-01-01',
            'https://video.richmondsunlight.com/house/floor/file.mp4',
            'WEBVTT',
            null,
            null,
            null
        );

        $queue = $this->queue([$job]);
        $metadataBuilder = new class extends MetadataBuilder {
            public array $jobs = [];

            public function build(ArchiveJob $job): array
            {
                $this->jobs[] = $job;
                return [
                    'title' => $job->title,
                    'mediatype' => 'movies',
                    'collection' => 'virginiageneralassembly',
                ];
            }
        };

        $uploader = new class extends InternetArchiveUploader {
            public array $payloads = [];

            public function __construct()
            {
                parent::__construct(null);
            }

            public function upload(ArchiveJob $job, array $metadata): ?string
            {
                $this->payloads[] = ['job' => $job, 'metadata' => $metadata];
                return 'https://archive.org/details/test-' . $job->fileId;
            }
        };

        $logger = new ArchiveJobProcessorTestLogger();
        $processor = new ArchiveJobProcessor($queue, $metadataBuilder, $uploader, $pdo, $logger);
        $processor->run(2);

        $this->assertSame(
            'https://archive.org/details/test-1',
            $pdo->query('SELECT path FROM files WHERE id = 1')->fetchColumn()
        );
        $this->assertCount(1, $metadataBuilder->jobs);
        $this->assertSame('Floor Session', $uploader->payloads[0]['metadata']['title']);
        $messages = array_column($logger->entries, 'message');
        $this->assertContains('Uploaded file #1 to https://archive.org/details/test-1', $messages);
    }

    public function testProcessJobThrowsWhenUploadReturnsNothing(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec("INSERT INTO files (id, path) VALUES (1, 'https://video.richmondsunlight.com/house/floor/file.mp4')");

        $job = new ArchiveJob(
            1,
            'house',
            'Floor Session',
            '2025-01-01',
            'https://video.richmondsunlight.com/house/floor/file.mp4',
            'WEBVTT',
            null,
            null,
            null
        );

        $uploader = new class extends InternetArchiveUploader {
            public function __construct()
            {
                parent::__construct(null);
            }

            public function upload(ArchiveJob $job, array $metadata): ?string
            {
                return null;
            }
        };

        $processor = new ArchiveJobProcessor(
            $this->queue([]),
            new MetadataBuilder(),
            $uploader,
            $pdo,
            new ArchiveJobProcessorTestLogger()
        );

        try {
            $processor->processJob($job);
            $this->fail('processJob must throw when the upload fails.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Internet Archive', $e->getMessage());
        }

        $this->assertSame(
            'https://video.richmondsunlight.com/house/floor/file.mp4',
            $pdo->query('SELECT path FROM files WHERE id = 1')->fetchColumn(),
            'A failed upload must not change the path.'
        );
    }

    public function testMovesTheS3CopyToColdStorageOnceArchived(): void
    {
        [$processor, $cold] = $this->processorWithColdStorage(null);

        $processor->processJob($this->job('https://video.richmondsunlight.com/senate/floor/20200226-6304.mp4'));

        $this->assertSame(['senate/floor/20200226-6304.mp4'], $cold->moved);
    }

    public function testArchivingSucceedsWhenTheMoveFails(): void
    {
        [$processor, $cold, $logger, $pdo] = $this->processorWithColdStorage(new \RuntimeException('Access Denied'));

        $processor->processJob($this->job('https://video.richmondsunlight.com/senate/floor/20200226-6304.mp4'));

        $this->assertSame('https://archive.org/download/va-senate-20200226-6304/video.mp4', $pdo->query('SELECT path FROM files WHERE id = 1')->fetchColumn());
        $this->assertNotEmpty(array_filter($logger->entries, static fn($e) => str_contains($e['message'], 'Could not move the S3 copy') && $e['level'] === 4));
    }

    /** Until the archive has the video, viewers keep playing the S3 copy. */
    public function testWhileTheArchiveIsProcessingTheVideoStaysOnS3(): void
    {
        [$processor, $cold, , $pdo] = $this->processorWithColdStorage(null, new NotReadyYet('Still processing.', 3600));

        try {
            $processor->processJob($this->job('https://video.richmondsunlight.com/senate/floor/20200226-6304.mp4'));
            $this->fail('Expected NotReadyYet.');
        } catch (NotReadyYet) {
        }

        $this->assertSame('https://video.richmondsunlight.com/senate/floor/20200226-6304.mp4', $pdo->query('SELECT path FROM files WHERE id = 1')->fetchColumn());
        $this->assertSame([], $cold->moved);
    }

    /** @return array{0:ArchiveJobProcessor,1:object,2:ArchiveJobProcessorTestLogger,3:PDO} */
    private function processorWithColdStorage(?\Throwable $failure, ?\Throwable $uploadThrows = null): array
    {
        $pdo = $this->createDatabase();
        $pdo->exec("INSERT INTO files (id, path) VALUES (1, 'https://video.richmondsunlight.com/senate/floor/20200226-6304.mp4')");
        $uploader = new class ($uploadThrows) extends InternetArchiveUploader {
            public function __construct(private ?\Throwable $throws)
            {
                parent::__construct(null);
            }

            public function upload(ArchiveJob $job, array $metadata): ?string
            {
                if ($this->throws) {
                    throw $this->throws;
                }
                return 'https://archive.org/download/va-senate-20200226-6304/video.mp4';
            }
        };
        $cold = new class ($failure) extends ColdStorage {
            public array $moved = [];

            public function __construct(private ?\Throwable $failure)
            {
            }

            public function move(string $key): string
            {
                if ($this->failure) {
                    throw $this->failure;
                }
                $this->moved[] = $key;
                return 'moved';
            }
        };
        $logger = new ArchiveJobProcessorTestLogger();
        $processor = new ArchiveJobProcessor($this->queue([]), new MetadataBuilder(), $uploader, $pdo, $logger, null, $cold);

        return [$processor, $cold, $logger, $pdo];
    }

    private function job(string $s3Path, ?string $captionAlignment = 'aligned'): ArchiveJob
    {
        return new ArchiveJob(1, 'senate', 'Senate Session', '2020-02-26', $s3Path, 'WEBVTT', null, null, null, null, null, $captionAlignment);
    }

    public function testLogsWhenNoJobsAvailable(): void
    {
        $pdo = $this->createDatabase();
        $queue = $this->queue([]);

        $metadataBuilder = new class extends MetadataBuilder {
            public function build(ArchiveJob $job): array
            {
                return [];
            }
        };

        $uploader = new class extends InternetArchiveUploader {
            public function __construct()
            {
                parent::__construct(null);
            }

            public function upload(ArchiveJob $job, array $metadata): ?string
            {
                return 'https://archive.org/details/test';
            }
        };

        $logger = new ArchiveJobProcessorTestLogger();
        $processor = new ArchiveJobProcessor($queue, $metadataBuilder, $uploader, $pdo, $logger);
        $processor->run(1);

        $this->assertSame('No files pending Internet Archive upload.', $logger->entries[0]['message'] ?? null);
    }

    private function createDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY,
            path TEXT,
            webvtt TEXT,
            caption_alignment TEXT
        )');
        TestDatabaseFactory::addPipelineTables($pdo);
        return $pdo;
    }

    private function queue(array $jobs): ArchiveJobQueue
    {
        return new class ($jobs) extends ArchiveJobQueue {
            public function __construct(private array $jobs)
            {
                parent::__construct(new PDO('sqlite::memory:'));
            }

            public function fetch(int $limit = 3): array
            {
                return array_slice($this->jobs, 0, $limit);
            }
        };
    }
    /**
     * An upload can take an hour, and the transcript stage can write new
     * captions meanwhile, while the video is still on S3, so nothing else
     * would ask for them to be sent.
     */
    public function testCaptionsWrittenDuringTheUploadAreSentAfterward(): void
    {
        [$processor, , , $pdo] = $this->processorWithColdStorage(null);
        $pdo->exec("UPDATE files SET webvtt = 'WEBVTT newer', caption_alignment = 'aligned' WHERE id = 1");

        $processor->processJob($this->job('https://video.richmondsunlight.com/senate/floor/20200226-6304.mp4'));

        $this->assertSame('pending', $pdo->query("SELECT status FROM video_jobs WHERE file_id = 1 AND stage = 'archive_captions'")->fetchColumn());
    }

    /** Uploaded alone because their timing was unchecked; checked by the time the upload finished. */
    public function testCaptionsCheckedDuringTheUploadAreSentAfterward(): void
    {
        [$processor, , , $pdo] = $this->processorWithColdStorage(null);
        $pdo->exec("UPDATE files SET webvtt = 'WEBVTT', caption_alignment = 'not_needed' WHERE id = 1");

        $processor->processJob($this->job('https://video.richmondsunlight.com/senate/floor/20200226-6304.mp4', null));

        $this->assertSame('pending', $pdo->query("SELECT status FROM video_jobs WHERE file_id = 1 AND stage = 'archive_captions'")->fetchColumn());
    }

    public function testCaptionsUnchangedDuringTheUploadAreNotSentAgain(): void
    {
        [$processor, , , $pdo] = $this->processorWithColdStorage(null);
        $pdo->exec("UPDATE files SET webvtt = 'WEBVTT', caption_alignment = 'aligned' WHERE id = 1");

        $processor->processJob($this->job('https://video.richmondsunlight.com/senate/floor/20200226-6304.mp4'));

        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM video_jobs')->fetchColumn());
    }
}

class ArchiveJobProcessorTestLogger extends \Log
{
    public array $entries = [];

    public function put($message, $level = 3)
    {
        $this->entries[] = ['message' => $message, 'level' => $level];
        return true;
    }
}
