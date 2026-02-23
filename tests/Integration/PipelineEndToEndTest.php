<?php

namespace RichmondSunlight\VideoProcessor\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\AgendaExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJob;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillParser;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillResultWriter;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillTextExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ChamberConfig;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;
use RichmondSunlight\VideoProcessor\Analysis\Bills\TesseractOcrEngine;
use RichmondSunlight\VideoProcessor\Analysis\Classification\ClassificationCorrectionWriter;
use RichmondSunlight\VideoProcessor\Analysis\Classification\ClassificationVerificationJob;
use RichmondSunlight\VideoProcessor\Analysis\Classification\ClassificationVerificationProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Classification\FrameClassifier;
use RichmondSunlight\VideoProcessor\Analysis\Metadata\MetadataIndexer;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\DiarizerInterface;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\LegislatorDirectory;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\OcrSpeakerExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJob;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerMetadataExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerResultWriter;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJob;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobProcessor;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;
use RichmondSunlight\VideoProcessor\Archive\InternetArchiveUploader;
use RichmondSunlight\VideoProcessor\Archive\MetadataBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\StorageInterface;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadJob;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadProcessor;
use RichmondSunlight\VideoProcessor\Fetcher\VideoMetadataExtractor;
use RichmondSunlight\VideoProcessor\Scraper\House\HouseScraper;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotGenerator;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJob;
use RichmondSunlight\VideoProcessor\Sync\VideoFilter;
use RichmondSunlight\VideoProcessor\Sync\VideoImporter;
use RichmondSunlight\VideoProcessor\Tests\Support\FakeHttpClient;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;
use RichmondSunlight\VideoProcessor\Tests\Support\TestLogger;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAITranscriber;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJob;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptProcessor;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

/**
 * End-to-end pipeline test that exercises:
 *   scrape -> filter -> import -> download -> screenshots -> classification
 *   -> transcripts -> bill detection -> speaker detection -> metadata indexing -> archive
 *
 * All stages share a single SQLite PDO, so stage N's DB writes become stage N+1's input.
 *
 * Requires: ffmpeg, tesseract, house-floor.mp4 fixture, house-floor-video.html fixture,
 *           house-floor-listing.json fixture, house-floor.vtt fixture.
 */
class PipelineEndToEndTest extends TestCase
{
    private PDO $pdo;
    private TestLogger $logger;
    private string $tempDir;
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->requireBinaries();
        $this->fixtureDir = __DIR__ . '/../fixtures';
        $this->requireFixtures();

        $this->pdo = TestDatabaseFactory::create();
        TestDatabaseFactory::seedCommittees($this->pdo);
        $this->logger = new TestLogger();
        $this->tempDir = sys_get_temp_dir() . '/pipeline-e2e-' . uniqid();
        mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $this->cleanup($this->tempDir);
        }
    }

    public function testFullPipelineHouseFloor(): void
    {
        // ── Stage 1: SCRAPE ──
        $listingJson = file_get_contents($this->fixtureDir . '/house-floor-listing.json');
        $detailHtml = file_get_contents($this->fixtureDir . '/house-floor-video.html');

        $client = new FakeHttpClient([
            'GetListViewData' => $listingJson,
            'PowerBrowser' => $detailHtml,
        ]);
        $scraper = new HouseScraper($client, 'https://example.test', [], $this->logger, 1);
        $records = $scraper->scrape();

        $this->assertNotEmpty($records, 'Scraper should return at least one record');
        $record = $records[0];
        $this->assertSame('house', $record['chamber']);
        $this->assertNotEmpty($record['video_url'], 'Record must have a video URL');

        // ── Stage 2: FILTER ──
        $this->assertTrue(VideoFilter::shouldKeep($record), 'Floor session should pass filter');

        // ── Stage 3: IMPORT ──
        $committees = new CommitteeDirectory($this->pdo);
        $importer = new VideoImporter($this->pdo, $committees, $this->logger);
        $count = $importer->import([$record]);

        $this->assertSame(1, $count, 'Import should insert exactly one record');

        $fileRow = $this->pdo->query('SELECT * FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('house', $fileRow['chamber']);
        $this->assertNotEmpty($fileRow['path'], 'Imported file should have a video path');
        $this->assertNotNull($fileRow['video_index_cache'], 'Import should store video_index_cache');

        // ── Stage 4: DOWNLOAD (stubbed) ──
        $fixtureVideo = $this->fixtureDir . '/house-floor.mp4';
        $storage = new LocalTestStorage($this->tempDir);
        $extractor = new StubMetadataExtractor();

        $processor = new TestableVideoDownloadProcessor(
            $this->pdo,
            $storage,
            $committees,
            $extractor,
            new S3KeyBuilder(),
            new MetadataIndexer($this->pdo),
            $this->logger,
            $this->tempDir
        );
        $processor->setFixtureVideo($fixtureVideo);

        // Use captions extracted by the scraper from the detail page's ccItems
        if (!empty($record['captions'])) {
            $captionPath = $this->tempDir . '/house-floor.vtt';
            file_put_contents($captionPath, $record['captions']);
            $processor->forcedCaptionPath = $captionPath;
        }

        $videoIndexCache = json_decode($fileRow['video_index_cache'], true);
        $downloadJob = new VideoDownloadJob(
            1,
            'house',
            $fileRow['committee_id'] ? (int) $fileRow['committee_id'] : null,
            $fileRow['date'],
            $fileRow['path'],
            $videoIndexCache ?? [],
            $fileRow['title']
        );
        $processor->process($downloadJob);

        $fileRow = $this->pdo->query('SELECT * FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($fileRow['path'], 'Download should update file path');
        $this->assertSame('00:02:00', $fileRow['length'], 'Download should store video length');
        $this->assertSame(1280, (int) $fileRow['width']);
        $this->assertSame(720, (int) $fileRow['height']);
        $this->assertNotNull($fileRow['webvtt'], 'Download should store WebVTT captions');

        // Verify metadata indexing happened (speakers from scraped metadata)
        $metadataIndexCount = $this->pdo->query('SELECT COUNT(*) FROM video_index WHERE file_id = 1 AND type = \'legislator\'')->fetchColumn();
        // May be 0 if the fixture has no speakers metadata — that's fine

        // ── Stage 5: SCREENSHOTS (real ffmpeg) ──
        $screenshotStorage = new LocalTestStorage($this->tempDir . '/screenshots');
        $screenshotGenerator = new ScreenshotGenerator(
            $this->pdo,
            $screenshotStorage,
            $committees,
            new S3KeyBuilder(),
            $this->logger,
            $this->tempDir . '/screenshot-work'
        );

        // Use the real fixture video via file:// URL
        $screenshotJob = new ScreenshotJob(
            1,
            'house',
            $fileRow['committee_id'] ? (int) $fileRow['committee_id'] : null,
            $fileRow['date'],
            'file://' . $fixtureVideo,
            null,
            $fileRow['title']
        );
        $screenshotGenerator->process($screenshotJob);

        $fileRow = $this->pdo->query('SELECT * FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($fileRow['capture_directory'], 'Screenshots should set capture_directory');
        $this->assertSame(60, (int) $fileRow['capture_rate'], 'Capture rate should be 60');

        // Verify manifest was created — find it via the storage
        $manifestKey = trim($fileRow['capture_directory'], '/') . '/manifest.json';
        $manifestPath = $screenshotStorage->getLocalPath($manifestKey);
        $this->assertNotNull($manifestPath, 'Manifest should be uploaded to storage');
        $this->assertFileExists($manifestPath, 'Manifest file should exist on disk');

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assertNotEmpty($manifest, 'Manifest should contain screenshot entries');
        $manifestUrl = 'file://' . $manifestPath;

        // ── Stage 6: CLASSIFICATION VERIFICATION ──
        $frameClassifier = new FrameClassifier(new TesseractOcrEngine());
        $correctionWriter = new ClassificationCorrectionWriter($this->pdo);
        $classificationProcessor = new ClassificationVerificationProcessor(
            new ScreenshotManifestLoader(),
            new ScreenshotFetcher(null, $this->tempDir),
            $frameClassifier,
            $correctionWriter,
            $committees,
            $this->logger
        );

        $cache = json_decode($fileRow['video_index_cache'], true);
        $classificationJob = new ClassificationVerificationJob(
            1,
            'house',
            $cache['event_type'] ?? 'floor',
            $fileRow['committee_id'] ? (int) $fileRow['committee_id'] : null,
            $fileRow['capture_directory'],
            $manifestUrl,
            $cache,
            $fileRow['title'],
            $fileRow['date']
        );
        $classificationProcessor->process($classificationJob);

        $fileRow = $this->pdo->query('SELECT * FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $updatedCache = json_decode($fileRow['video_index_cache'], true);
        $this->assertTrue(
            $updatedCache['classification_verified'] ?? false,
            'Classification should be marked as verified'
        );

        // ── Stage 7: TRANSCRIPTS ──
        $transcriber = $this->createMock(OpenAITranscriber::class);
        $transcriptProcessor = new TranscriptProcessor(
            new TranscriptWriter($this->pdo),
            $transcriber,
            new CaptionParser(),
            null,
            $this->logger
        );

        $transcriptJob = new TranscriptJob(
            1,
            'house',
            $fileRow['path'],
            $fileRow['webvtt'],
            $fileRow['srt'] ?? null,
            $fileRow['title']
        );
        $transcriptProcessor->process($transcriptJob);

        $fileRow = $this->pdo->query('SELECT * FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($fileRow['transcript'], 'Transcript should be populated');
        $transcriptCount = (int) $this->pdo->query('SELECT COUNT(*) FROM video_transcript WHERE file_id = 1')->fetchColumn();
        $this->assertGreaterThan(0, $transcriptCount, 'video_transcript rows should exist');

        // ── Stage 8: BILL DETECTION (real tesseract on screenshots) ──
        $billDetectionProcessor = new BillDetectionProcessor(
            new ScreenshotManifestLoader(),
            new ScreenshotFetcher(null, $this->tempDir),
            new BillTextExtractor(new TesseractOcrEngine()),
            new BillParser(),
            new BillResultWriter($this->pdo),
            new ChamberConfig(),
            new AgendaExtractor(),
            $this->logger
        );

        $billJob = new BillDetectionJob(
            1,
            'house',
            $fileRow['committee_id'] ? (int) $fileRow['committee_id'] : null,
            $updatedCache['event_type'] ?? 'floor',
            $fileRow['capture_directory'],
            $manifestUrl,
            $cache,
            $fileRow['date']
        );
        $billDetectionProcessor->process($billJob);
        // Bill detection may find zero bills in a short fixture — assert it completes without error

        // ── Stage 9: SPEAKER DETECTION ──
        $speakerMetadataExtractor = new SpeakerMetadataExtractor();
        $diarizer = $this->createMock(DiarizerInterface::class);
        $ocrExtractor = $this->createMock(OcrSpeakerExtractor::class);
        $legislatorDir = new LegislatorDirectory($this->pdo);
        $speakerWriter = new SpeakerResultWriter($this->pdo);

        $speakerProcessor = new SpeakerDetectionProcessor(
            $speakerMetadataExtractor,
            $diarizer,
            $ocrExtractor,
            $legislatorDir,
            $speakerWriter,
            $this->logger
        );

        $speakerJob = new SpeakerJob(
            1,
            'house',
            $fileRow['path'],
            $cache,
            $updatedCache['event_type'] ?? 'floor',
            $fileRow['capture_directory'],
            $manifestUrl,
            $fileRow['date']
        );
        $speakerProcessor->process($speakerJob);
        // Speaker detection may find zero speakers if fixture lacks speaker metadata — that's OK

        // ── Stage 10: METADATA INDEXING ──
        $metadataIndexer = new MetadataIndexer($this->pdo);
        $metadataIndexer->index(1, $cache ?? []);

        // ── Stage 11: ARCHIVE (stubbed uploader) ──
        $fileRow = $this->pdo->query('SELECT * FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

        // Archive queue requires path starting with 'https://video.richmondsunlight.com/'
        // Update the path to match the queue filter
        $this->pdo->prepare('UPDATE files SET path = :path WHERE id = 1')->execute([
            ':path' => 'https://video.richmondsunlight.com/house/floor/20250212.mp4',
        ]);

        $archiveQueue = new ArchiveJobQueue($this->pdo);
        $jobs = $archiveQueue->fetch(1);

        $this->assertCount(1, $jobs, 'Archive queue should find the file ready for upload');
        $archiveJob = $jobs[0];
        $this->assertSame(1, $archiveJob->fileId);
        $this->assertSame('house', $archiveJob->chamber);

        // Stubbed uploader
        $stubUploader = new class extends InternetArchiveUploader {
            public ?ArchiveJob $lastJob = null;
            public function __construct()
            {
                // Skip parent constructor entirely
            }
            public function upload(ArchiveJob $job, array $metadata): ?string
            {
                $this->lastJob = $job;
                return 'https://archive.org/details/virginia-house-' . $job->date;
            }
        };

        $archiveProcessor = new ArchiveJobProcessor(
            $archiveQueue,
            new MetadataBuilder(),
            $stubUploader,
            $this->pdo,
            $this->logger
        );
        $archiveProcessor->run(1);

        $fileRow = $this->pdo->query('SELECT path FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertStringContainsString('archive.org', $fileRow['path'], 'Path should be updated to archive.org URL');
    }

    private function requireBinaries(): void
    {
        exec('ffmpeg -version > /dev/null 2>&1', $output, $ffmpegStatus);
        if ($ffmpegStatus !== 0) {
            $this->markTestSkipped('ffmpeg is required for the end-to-end pipeline test.');
        }

        exec('tesseract --version > /dev/null 2>&1', $output, $tessStatus);
        if ($tessStatus !== 0) {
            $this->markTestSkipped('tesseract is required for the end-to-end pipeline test.');
        }
    }

    private function requireFixtures(): void
    {
        $required = [
            'house-floor.mp4' => 'Run bin/fetch_test_fixtures.php to download.',
            'house-floor-video.html' => 'House floor video detail page fixture.',
            'house-floor-listing.json' => 'House listing API response with a floor session entry. See tests/fixtures/PIPELINE_FIXTURES.md.',
        ];

        foreach ($required as $file => $hint) {
            if (!file_exists($this->fixtureDir . '/' . $file)) {
                $this->markTestSkipped("Missing fixture: {$file}. {$hint}");
            }
        }
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}

/**
 * Storage implementation that persists files to a local directory and returns file:// URLs,
 * so uploaded screenshots/manifests remain accessible to subsequent pipeline stages.
 */
class LocalTestStorage implements StorageInterface
{
    /** @var array<string, string> key => local path */
    private array $uploads = [];

    public function __construct(private string $baseDir)
    {
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0775, true);
        }
    }

    public function upload(string $localPath, string $key): string
    {
        $dest = $this->baseDir . '/' . $key;
        $dir = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        copy($localPath, $dest);
        $this->uploads[$key] = $dest;
        return 'file://' . $dest;
    }

    public function getLocalPath(string $key): ?string
    {
        return $this->uploads[$key] ?? null;
    }
}

/**
 * Extends VideoDownloadProcessor to stub the download methods.
 * Copies a real fixture video instead of downloading from remote URL.
 */
class TestableVideoDownloadProcessor extends VideoDownloadProcessor
{
    public array $httpCalls = [];
    public array $ytCalls = [];
    public ?string $forcedCaptionPath = null;
    private ?string $fixtureVideo = null;

    public function __construct(
        PDO $pdo,
        StorageInterface $storage,
        CommitteeDirectory $committeeDirectory,
        VideoMetadataExtractor $metadataExtractor,
        S3KeyBuilder $keyBuilder,
        ?MetadataIndexer $metadataIndexer = null,
        ?\Log $logger = null,
        ?string $downloadDir = null
    ) {
        parent::__construct($pdo, $storage, $committeeDirectory, $metadataExtractor, $keyBuilder, $metadataIndexer, $logger, $downloadDir);
    }

    public function setFixtureVideo(string $path): void
    {
        $this->fixtureVideo = $path;
    }

    protected function downloadViaHttp(string $url, string $destination): void
    {
        $this->httpCalls[] = $url;
        if ($this->fixtureVideo) {
            copy($this->fixtureVideo, $destination);
        } else {
            file_put_contents($destination, str_repeat('0', 1024));
        }
    }

    protected function downloadViaYtDlp(string $url, string $destination): void
    {
        $this->ytCalls[] = $url;
        if ($this->fixtureVideo) {
            copy($this->fixtureVideo, $destination);
        } else {
            file_put_contents($destination, str_repeat('0', 1024));
        }
    }

    protected function downloadViaFfmpeg(string $url, string $destination): void
    {
        if ($this->fixtureVideo) {
            copy($this->fixtureVideo, $destination);
        } else {
            file_put_contents($destination, str_repeat('0', 1024));
        }
    }

    protected function downloadCaptions(VideoDownloadJob $job): ?string
    {
        return $this->forcedCaptionPath ?? null;
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
