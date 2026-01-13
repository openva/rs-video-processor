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
            ['timestamp' => 0, 'full' => 'https://video.richmondsunlight.com/senate/floor/20250101/screenshots/full/00000.jpg', 'thumb' => null]
        ]);

        $fetcher = $this->createMock(ScreenshotFetcher::class);
        $fetcher->method('fetch')->willReturn($fixture);
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
            'https://video.richmondsunlight.com/senate/floor/20250101/screenshots/full/',
            'https://video.richmondsunlight.com/senate/floor/20250101/screenshots/manifest.json',
            null
        );
        $processor->process($job);

        $row = $pdo->query('SELECT screenshot, raw_text, type FROM video_index')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('00000.jpg', $row['screenshot']);
        $this->assertSame('HB1234', $row['raw_text']);
        $this->assertSame('bill', $row['type']);
    }
}
