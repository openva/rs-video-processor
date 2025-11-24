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
        $fixture = realpath(__DIR__ . '/../../fixtures/screenshot.jpg');
        $manifestPath = tempnam(sys_get_temp_dir(), 'manifest');
        file_put_contents($manifestPath, json_encode([
            ['timestamp' => 0, 'full' => 'file://' . $fixture, 'thumb' => null]
        ]));

        $loader = $this->createMock(ScreenshotManifestLoader::class);
        $loader->method('load')->willReturn([
            ['timestamp' => 0, 'full' => 'file://' . $fixture, 'thumb' => null]
        ]);

        $fetcher = new ScreenshotFetcher();
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
            'https://s3.amazonaws.com/video.richmondsunlight.com/senate/floor/20250101/screenshots/full/',
            'file://' . $manifestPath,
            null
        );
        $processor->process($job);

        $count = $pdo->query('SELECT COUNT(*) FROM video_index')->fetchColumn();
        $this->assertSame(1, (int) $count);
    }
}
