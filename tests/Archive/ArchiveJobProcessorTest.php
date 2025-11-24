<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJob;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobProcessor;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;
use RichmondSunlight\VideoProcessor\Archive\InternetArchiveUploader;
use RichmondSunlight\VideoProcessor\Archive\MetadataBuilder;

class ArchiveJobProcessorTest extends TestCase
{
    public function testProcessesJobsAndUpdatesDatabase(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec("INSERT INTO files (id, path) VALUES (1, 'https://s3.amazonaws.com/video.richmondsunlight.com/house/floor/file.mp4')");

        $job = new ArchiveJob(
            1,
            'house',
            'Floor Session',
            '2025-01-01',
            'https://s3.amazonaws.com/video.richmondsunlight.com/house/floor/file.mp4',
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
                    'collection' => 'opensource_movies',
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
            path TEXT
        )');
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
}

class ArchiveJobProcessorTestLogger extends \Log
{
    public array $entries = [];

    public function put($message, $level)
    {
        $this->entries[] = ['message' => $message, 'level' => $level];
        return true;
    }
}
