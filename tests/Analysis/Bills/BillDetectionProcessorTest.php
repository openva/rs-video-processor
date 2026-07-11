<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Bills;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\AgendaExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJob;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillParser;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillResultWriter;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillTextExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ChamberConfig;
use RichmondSunlight\VideoProcessor\Analysis\Bills\CropConfig;
use RichmondSunlight\VideoProcessor\Analysis\Bills\OcrEngineInterface;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;

class BillDetectionProcessorTest extends TestCase
{
    public function testProcessesManifest(): void
    {
        $fixture = __DIR__ . '/../../fixtures/senate-floor.jpg';
        if (!file_exists($fixture)) {
            $this->markTestSkipped('Fixture file not found: ' . $fixture);
        }

        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->method('load')->willReturn([
            ['timestamp' => 0, 'full' => 'https://video.richmondsunlight.com/senate/floor/20250101/00000000.jpg', 'thumb' => null]
        ]);

        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $fetcher->method('fetch')->willReturnCallback(function () use ($fixture) {
            $temp = tempnam(sys_get_temp_dir(), 'bill_fixture_') . '.jpg';
            if (!copy($fixture, $temp)) {
                throw new \RuntimeException('Unable to copy bill fixture.');
            }
            return $temp;
        });
        $ocr = new class implements OcrEngineInterface {
            public function extractText(string $imagePath): string
            {
                return 'HB 1234';
            }
        };
        $textExtractor = new BillTextExtractor($ocr);

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            time TEXT,
            screenshot TEXT,
            raw_text TEXT,
            type TEXT,
            linked_id INTEGER,
            ignored TEXT,
            date_created TEXT
        )');
        $writer = new BillResultWriter($pdo);

        $processor = new BillDetectionProcessor(
            $loader,
            $fetcher,
            $textExtractor,
            new BillParser(),
            $writer,
            new ChamberConfig(),
            new AgendaExtractor(),
            null
        );

        $job = new BillDetectionJob(
            1,
            'senate',
            null,
            'floor',
            'https://video.richmondsunlight.com/senate/floor/20250101/',
            'https://video.richmondsunlight.com/senate/floor/20250101/manifest.json',
            null
        );
        $processor->process($job);

        $row = $pdo->query('SELECT screenshot, raw_text, type FROM video_index')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('00000000', $row['screenshot']);
        $this->assertSame('HB1234', $row['raw_text']);
        $this->assertSame('bill', $row['type']);
    }

    public function testRecordsNoneFoundSentinelWhenNoBillsDetected(): void
    {
        $fixture = __DIR__ . '/../../fixtures/senate-floor.jpg';
        if (!file_exists($fixture)) {
            $this->markTestSkipped('Fixture file not found: ' . $fixture);
        }

        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->method('load')->willReturn([
            ['timestamp' => 0, 'full' => 'https://video.richmondsunlight.com/senate/floor/20250101/00000000.jpg', 'thumb' => null]
        ]);

        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $fetcher->method('fetch')->willReturnCallback(function () use ($fixture) {
            $temp = tempnam(sys_get_temp_dir(), 'bill_fixture_') . '.jpg';
            if (!copy($fixture, $temp)) {
                throw new \RuntimeException('Unable to copy bill fixture.');
            }
            return $temp;
        });

        // OCR that finds nothing
        $ocr = new class implements OcrEngineInterface {
            public function extractText(string $imagePath): string
            {
                return '';
            }
        };

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            time TEXT,
            screenshot TEXT,
            raw_text TEXT,
            type TEXT,
            linked_id INTEGER,
            ignored TEXT,
            date_created TEXT
        )');
        // Simulate the claim placeholder the job queue inserts at fetch time
        $pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
                    VALUES (1, '00:00:00', '00000000', '/pending', 'bill', NULL, 'y', '2025-01-01 00:00:00')");

        $processor = new BillDetectionProcessor(
            $loader,
            $fetcher,
            new BillTextExtractor($ocr),
            new BillParser(),
            new BillResultWriter($pdo),
            new ChamberConfig(),
            new AgendaExtractor(),
            null
        );

        $job = new BillDetectionJob(
            1,
            'senate',
            null,
            'floor',
            'https://video.richmondsunlight.com/senate/floor/20250101/',
            'https://video.richmondsunlight.com/senate/floor/20250101/manifest.json',
            null
        );
        $processor->process($job);

        $rows = $pdo->query("SELECT raw_text, ignored FROM video_index WHERE file_id = 1")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows, 'Placeholder must be replaced by exactly one sentinel row.');
        $this->assertSame('/none', $rows[0]['raw_text']);
        $this->assertSame('y', $rows[0]['ignored']);
    }

    public function testKeepsPartialResultsWhenSomeScreenshotsFail(): void
    {
        $fixture = __DIR__ . '/../../fixtures/senate-floor.jpg';
        if (!file_exists($fixture)) {
            $this->markTestSkipped('Fixture file not found: ' . $fixture);
        }

        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->method('load')->willReturn([
            ['timestamp' => 0, 'full' => 'https://video.richmondsunlight.com/senate/floor/20250101/00000000.jpg', 'thumb' => null],
            ['timestamp' => 1, 'full' => 'https://video.richmondsunlight.com/senate/floor/20250101/00000001.jpg', 'thumb' => null],
        ]);

        // First screenshot fetches fine; second throws (e.g. transient S3 error).
        $calls = 0;
        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $fetcher->method('fetch')->willReturnCallback(function () use (&$calls, $fixture) {
            $calls++;
            if ($calls === 2) {
                throw new \RuntimeException('S3 fetch failed');
            }
            $temp = tempnam(sys_get_temp_dir(), 'bill_fixture_') . '.jpg';
            if (!copy($fixture, $temp)) {
                throw new \RuntimeException('Unable to copy bill fixture.');
            }
            return $temp;
        });

        $ocr = new class implements OcrEngineInterface {
            public function extractText(string $imagePath): string
            {
                return 'HB 1234';
            }
        };

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            time TEXT,
            screenshot TEXT,
            raw_text TEXT,
            type TEXT,
            linked_id INTEGER,
            ignored TEXT,
            date_created TEXT
        )');
        $pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
                    VALUES (1, '00:00:00', '00000000', '/pending', 'bill', NULL, 'y', '2025-01-01 00:00:00')");

        $processor = new BillDetectionProcessor(
            $loader,
            $fetcher,
            new BillTextExtractor($ocr),
            new BillParser(),
            new BillResultWriter($pdo),
            new ChamberConfig(),
            new AgendaExtractor(),
            null
        );

        $job = new BillDetectionJob(
            1,
            'senate',
            null,
            'floor',
            'https://video.richmondsunlight.com/senate/floor/20250101/',
            'https://video.richmondsunlight.com/senate/floor/20250101/manifest.json',
            null
        );
        $processor->process($job);

        // A real bill was found on the first screenshot, so the pass is committed:
        // the /pending placeholder is cleared and the bill kept. No /none sentinel.
        $rows = $pdo->query("SELECT raw_text, ignored FROM video_index WHERE file_id = 1")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('HB1234', $rows[0]['raw_text']);
        $this->assertSame('n', $rows[0]['ignored']);
    }

    public function testLeavesClaimWhenAllScreenshotsFailWithNoBills(): void
    {
        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->method('load')->willReturn([
            ['timestamp' => 0, 'full' => 'https://video.richmondsunlight.com/senate/floor/20250101/00000000.jpg', 'thumb' => null],
        ]);

        // Every screenshot fetch fails (e.g. S3 outage): we never got to look at
        // the video, so it must NOT be finalized as /none — the claim stays.
        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $fetcher->method('fetch')->willThrowException(new \RuntimeException('S3 down'));

        $ocr = new class implements OcrEngineInterface {
            public function extractText(string $imagePath): string
            {
                return '';
            }
        };

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            time TEXT,
            screenshot TEXT,
            raw_text TEXT,
            type TEXT,
            linked_id INTEGER,
            ignored TEXT,
            date_created TEXT
        )');
        $pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
                    VALUES (1, '00:00:00', '00000000', '/pending', 'bill', NULL, 'y', '2025-01-01 00:00:00')");

        $processor = new BillDetectionProcessor(
            $loader,
            $fetcher,
            new BillTextExtractor($ocr),
            new BillParser(),
            new BillResultWriter($pdo),
            new ChamberConfig(),
            new AgendaExtractor(),
            null
        );

        $job = new BillDetectionJob(
            1,
            'senate',
            null,
            'floor',
            'https://video.richmondsunlight.com/senate/floor/20250101/',
            'https://video.richmondsunlight.com/senate/floor/20250101/manifest.json',
            null
        );
        $processor->process($job);

        // Claim placeholder must survive so StaleClaimCleaner releases it for retry.
        $rows = $pdo->query("SELECT raw_text FROM video_index WHERE file_id = 1")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['/pending'], $rows);
    }

    public function testLeavesClaimPlaceholderWhenManifestMissing(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            time TEXT,
            screenshot TEXT,
            raw_text TEXT,
            type TEXT,
            linked_id INTEGER,
            ignored TEXT,
            date_created TEXT
        )');
        $pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
                    VALUES (1, '00:00:00', '00000000', '/pending', 'bill', NULL, 'y', '2025-01-01 00:00:00')");

        $ocr = new class implements OcrEngineInterface {
            public function extractText(string $imagePath): string
            {
                return '';
            }
        };

        $processor = new BillDetectionProcessor(
            $this->createMock(ScreenshotManifestLoader::class),
            $this->createMock(ScreenshotFetcher::class),
            new BillTextExtractor($ocr),
            new BillParser(),
            new BillResultWriter($pdo),
            new ChamberConfig(),
            new AgendaExtractor(),
            null
        );

        // manifestUrl = null → early return; the claim must survive so the video
        // is retried after StaleClaimCleaner releases it, instead of looping now.
        $job = new BillDetectionJob(1, 'senate', null, 'floor', '', null, null);
        $processor->process($job);

        $row = $pdo->query("SELECT raw_text FROM video_index WHERE file_id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'Claim placeholder must NOT be deleted on early failure.');
        $this->assertSame('/pending', $row['raw_text']);
    }
}
