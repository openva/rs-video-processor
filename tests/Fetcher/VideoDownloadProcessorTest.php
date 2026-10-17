<?php

namespace RichmondSunlight\VideoProcessor\Tests\Fetcher;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\StorageInterface;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadJob;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadProcessor;
use RichmondSunlight\VideoProcessor\Fetcher\VideoMetadataExtractor;
use RichmondSunlight\VideoProcessor\Fetcher\VideoValidator;

class VideoDownloadProcessorTest extends TestCase
{
    public function testSenateGranicusVideosUseHttpDownloader(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec("INSERT INTO files (id, chamber, committee_id, path, capture_directory, date_created, date_modified) VALUES (1, 'senate', NULL, '', '', '2025-01-01', '2025-01-01')");
        $pdo->exec("INSERT INTO committees (id, name, shortname, chamber, parent_id) VALUES (1, 'State Water Commission', 'water', 'senate', NULL)");

        $storage = new InMemoryStorage();
        $extractor = new StubMetadataExtractor();
        $processor = new TestableVideoDownloadProcessor(
            $pdo,
            $storage,
            new CommitteeDirectory($pdo),
            $extractor,
            new S3KeyBuilder(),
            null
        );

        $job = new VideoDownloadJob(
            1,
            'senate',
            null,
            '2025-02-01',
            'https://archive-video.granicus.com/virginia-senate/clip123.mp4',
            [],
            'Test Senate Meeting'
        );

        $processor->process($job);

        $this->assertSame(['https://archive-video.granicus.com/virginia-senate/clip123.mp4'], $processor->httpCalls);
        $this->assertSame([], $processor->ytCalls);

        $record = $pdo->query('SELECT path, capture_directory FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('https://example.test/senate/floor/20250201-1.mp4', $record['path']);
        $this->assertEmpty($record['capture_directory']); // capture_directory is set during screenshot generation, not download
    }

    /**
     * Granicus's CloudFront answers 403 to non-browser user agents (curl's,
     * Guzzle's) and 200 to a browser's, for the same video. Checked 2026-10-17.
     */
    public function testDownloadsIdentifyAsABrowser(): void
    {
        $pdo = $this->createDatabase();
        $processor = new VideoDownloadProcessor(
            $pdo,
            new InMemoryStorage(),
            new CommitteeDirectory($pdo),
            new StubMetadataExtractor(),
            new S3KeyBuilder(),
            null,
            null,
            sys_get_temp_dir()
        );

        $http = (new \ReflectionProperty(VideoDownloadProcessor::class, 'http'))->getValue($processor);
        $this->assertStringStartsWith('Mozilla/5.0', $http->getConfig('headers')['User-Agent'] ?? '');
    }

    public function testVideoMetadataIsCaptured(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec("INSERT INTO files (id, chamber, committee_id, path, capture_directory, date_created, date_modified) VALUES (3, 'house', NULL, '', '', '2025-01-01', '2025-01-01')");
        $pdo->exec("INSERT INTO committees (id, name, shortname, chamber, parent_id) VALUES (2, 'Appropriations', 'appropriations', 'house', NULL)");

        $storage = new InMemoryStorage();
        $extractor = new StubMetadataExtractor();
        $processor = new TestableVideoDownloadProcessor(
            $pdo,
            $storage,
            new CommitteeDirectory($pdo),
            $extractor,
            new S3KeyBuilder(),
            null
        );

        $job = new VideoDownloadJob(
            3,
            'house',
            null,
            '2025-04-10',
            'https://example.com/test.mp4',
            [],
            'Test Video'
        );

        $processor->process($job);

        $record = $pdo->query('SELECT length, width, height, fps FROM files WHERE id = 3')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('00:02:00', $record['length']);
        $this->assertSame(1280, (int) $record['width']);
        $this->assertSame(720, (int) $record['height']);
        $this->assertSame(30.0, (float) $record['fps']);
    }

    public function testCaptionFileIsStoredWhenProvided(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec("INSERT INTO files (id, chamber, committee_id, path, capture_directory, date_created, date_modified) VALUES (2, 'house', NULL, '', '', '2025-01-01', '2025-01-01')");
        $pdo->exec("INSERT INTO committees (id, name, shortname, chamber, parent_id) VALUES (1, 'Finance', 'finance', 'house', NULL)");

        $storage = new InMemoryStorage();
        $extractor = new StubMetadataExtractor();
        $processor = new TestableVideoDownloadProcessor(
            $pdo,
            $storage,
            new CommitteeDirectory($pdo),
            $extractor,
            new S3KeyBuilder(),
            null
        );

        $captionPath = tempnam(sys_get_temp_dir(), 'caption_') . '.vtt';
        file_put_contents($captionPath, "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHello");
        $processor->forcedCaptionPath = $captionPath;

        $job = new VideoDownloadJob(
            2,
            'house',
            1,
            '2025-03-05',
            'https://example.com/house.mp4',
            [],
            'Finance Committee'
        );

        $processor->process($job);

        $record = $pdo->query('SELECT webvtt, path FROM files WHERE id = 2')->fetch(PDO::FETCH_ASSOC);
        $this->assertStringContainsString('WEBVTT', $record['webvtt']);
        $this->assertSame('https://example.test/house/committee/finance/20250305-2.mp4', $record['path']);
    }

    public function testAFileThatIsNotAVideoIsNeverUploaded(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec("INSERT INTO files (id, chamber, committee_id, path, capture_directory, date_created, date_modified) VALUES (4, 'house', NULL, '', '', '2025-01-01', '2025-01-01')");

        $validator = new class extends VideoValidator {
            public function assertValid(string $path): void
            {
                throw new \RuntimeException('Downloaded file is not a valid video: test.');
            }
        };

        $storage = new InMemoryStorage();
        $processor = new TestableVideoDownloadProcessor(
            $pdo,
            $storage,
            new CommitteeDirectory($pdo),
            new StubMetadataExtractor(),
            new S3KeyBuilder(),
            null,
            null,
            $validator
        );

        $job = new VideoDownloadJob(4, 'house', null, '2025-04-10', 'https://example.com/error-page.mp4', [], 'Test');

        try {
            $processor->process($job);
            $this->fail('An invalid download must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not a valid video', $e->getMessage());
        }

        $this->assertSame([], $storage->uploads);
        $this->assertSame('', $pdo->query('SELECT path FROM files WHERE id = 4')->fetchColumn());
    }

    /**
     * A multi-gigabyte download and upload can outlast the database's idle
     * timeout. The result must be written on a fresh connection, not the one
     * opened before the download began.
     */
    public function testWritesResultOnAFreshConnection(): void
    {
        $stale = $this->createDatabase();
        $fresh = $this->createDatabase();
        foreach ([$stale, $fresh] as $pdo) {
            $pdo->exec("INSERT INTO files (id, chamber, committee_id, path, capture_directory, date_created, date_modified) VALUES (5, 'house', NULL, '', '', '2025-01-01', '2025-01-01')");
        }
        $fresh->exec('CREATE TABLE video_index (id INTEGER PRIMARY KEY AUTOINCREMENT, file_id INTEGER, time TEXT, screenshot TEXT, raw_text TEXT, type TEXT, linked_id INTEGER, ignored TEXT, date_created TEXT)');

        $processor = new TestableVideoDownloadProcessor(
            $stale,
            new InMemoryStorage(),
            new CommitteeDirectory($stale),
            new StubMetadataExtractor(),
            new S3KeyBuilder(),
            null,
            null,
            null,
            fn() => $fresh
        );

        $processor->process(new VideoDownloadJob(
            5,
            'house',
            null,
            '2025-04-10',
            'https://example.com/test.mp4',
            ['speakers' => [['name' => 'Smith', 'start_time' => '00:00:10']]],
            'Test'
        ));

        $this->assertSame(
            'https://example.test/house/floor/20250410-5.mp4',
            $fresh->query('SELECT path FROM files WHERE id = 5')->fetchColumn()
        );
        $this->assertSame('', $stale->query('SELECT path FROM files WHERE id = 5')->fetchColumn());
        $this->assertSame(1, (int) $fresh->query('SELECT COUNT(*) FROM video_index')->fetchColumn(), 'Speaker metadata is indexed on the fresh connection too.');
    }

    private function createDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY,
            chamber TEXT,
            committee_id INTEGER,
            path TEXT,
            html TEXT,
            capture_directory TEXT,
            length TEXT,
            width INTEGER,
            height INTEGER,
            fps REAL,
            webvtt TEXT,
            date_created TEXT,
            date_modified TEXT
        )');
        $pdo->exec('CREATE TABLE committees (
            id INTEGER PRIMARY KEY,
            name TEXT,
            shortname TEXT,
            chamber TEXT,
            parent_id INTEGER
        )');
        return $pdo;
    }
}

class TestableVideoDownloadProcessor extends VideoDownloadProcessor
{
    public array $httpCalls = [];
    public array $ytCalls = [];
    public ?string $forcedCaptionPath = null;
    private string $dummyVideo;

    public function __construct(
        PDO $pdo,
        StorageInterface $storage,
        CommitteeDirectory $committeeDirectory,
        VideoMetadataExtractor $metadataExtractor,
        S3KeyBuilder $keyBuilder,
        ?\Log $logger = null,
        ?string $downloadDir = null,
        ?VideoValidator $validator = null,
        ?\Closure $pdoFactory = null
    ) {
        parent::__construct(
            $pdo,
            $storage,
            $committeeDirectory,
            $metadataExtractor,
            $keyBuilder,
            null,
            $logger,
            $downloadDir,
            $validator,
            $pdoFactory
        );
        $this->dummyVideo = $this->createDummyVideo();
    }

    protected function downloadViaHttp(string $url, string $destination): void
    {
        $this->httpCalls[] = $url;
        copy($this->dummyVideo, $destination);
    }

    protected function downloadViaYtDlp(string $url, string $destination): void
    {
        $this->ytCalls[] = $url;
        copy($this->dummyVideo, $destination);
    }

    protected function downloadViaFfmpeg(string $url, string $destination): void
    {
        copy($this->dummyVideo, $destination);
    }

    protected function downloadCaptions(VideoDownloadJob $job): ?string
    {
        return $this->forcedCaptionPath ?? null;
    }

    private function createDummyVideo(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'video_') . '.mp4';
        file_put_contents($path, str_repeat('0', 2 * 1024 * 1024));
        return $path;
    }
}

class InMemoryStorage implements StorageInterface
{
    public array $uploads = [];

    public function upload(string $localPath, string $key): string
    {
        $this->uploads[] = ['path' => $localPath, 'key' => $key];
        return 'https://example.test/' . $key;
    }
}

class StubMetadataExtractor extends VideoMetadataExtractor
{
    public function extract(string $filePath): array
    {
        return [
            'duration_seconds' => 120,
            'length' => '00:02:00',
            'width' => 1280,
            'height' => 720,
            'fps' => 30.0,
        ];
    }
}
