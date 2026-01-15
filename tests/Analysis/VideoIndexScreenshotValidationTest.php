<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillResultWriter;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerResultWriter;
use RichmondSunlight\VideoProcessor\Analysis\Metadata\MetadataIndexer;

/**
 * Test that video_index.screenshot only contains numeric values.
 *
 * The screenshot column should only contain screenshot numbers like "00102",
 * never text like "bill-HB1537" or "speaker-jones".
 */
class VideoIndexScreenshotValidationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY,
            file_id INTEGER,
            time TEXT,
            screenshot TEXT,
            raw_text TEXT,
            type TEXT,
            linked_id INTEGER,
            ignored TEXT,
            date_created TEXT
        )');
    }

    public function testBillResultWriterUsesNumericScreenshot(): void
    {
        $writer = new BillResultWriter($this->pdo);

        // Test with standard screenshot filename
        $writer->record(
            fileId: 1,
            timestamp: 102,
            bills: ['HB 1234', 'SB 5678'],
            screenshotFilename: '00102.jpg'
        );

        $results = $this->pdo->query('SELECT screenshot FROM video_index')->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(2, $results);
        foreach ($results as $screenshot) {
            $this->assertMatchesRegularExpression(
                '/^\d+$/',
                $screenshot,
                "Screenshot value '$screenshot' should be purely numeric"
            );
            $this->assertSame('00102', $screenshot);
        }
    }

    public function testSpeakerResultWriterUsesNumericScreenshot(): void
    {
        $writer = new SpeakerResultWriter($this->pdo);

        $segments = [
            ['name' => 'John Smith', 'start' => 45.2, 'legislator_id' => 123],
            ['name' => 'Jane Doe', 'start' => 102.8, 'legislator_id' => 456],
        ];

        $writer->write(fileId: 1, segments: $segments);

        $results = $this->pdo->query('SELECT screenshot FROM video_index')->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(2, $results);
        foreach ($results as $screenshot) {
            $this->assertMatchesRegularExpression(
                '/^\d+$/',
                $screenshot,
                "Screenshot value '$screenshot' should be purely numeric"
            );
        }

        // Verify specific values (screenshots are 1 FPS, starting at 00001)
        // 45.2 seconds -> screenshot 00046
        // 102.8 seconds -> screenshot 00103
        $this->assertSame('00046', $results[0]);
        $this->assertSame('00104', $results[1]);
    }

    public function testMetadataIndexerUsesNumericScreenshot(): void
    {
        $indexer = new MetadataIndexer($this->pdo);

        $metadata = [
            'speakers' => [
                ['name' => 'Delegate Smith', 'start_time' => '00:01:42'],
                ['name' => 'Senator Jones', 'start_time' => '00:05:30'],
            ],
        ];

        $indexer->index(fileId: 1, metadata: $metadata);

        $results = $this->pdo->query('SELECT screenshot FROM video_index')->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(2, $results);
        foreach ($results as $screenshot) {
            $this->assertMatchesRegularExpression(
                '/^\d+$/',
                $screenshot,
                "Screenshot value '$screenshot' should be purely numeric"
            );
        }

        // Verify specific values
        // 00:01:42 = 102 seconds -> screenshot 00103
        // 00:05:30 = 330 seconds -> screenshot 00331
        $this->assertSame('00103', $results[0]);
        $this->assertSame('00331', $results[1]);
    }

    public function testNoTextualScreenshotValues(): void
    {
        // This test ensures that no writer creates textual screenshot values
        $billWriter = new BillResultWriter($this->pdo);
        $speakerWriter = new SpeakerResultWriter($this->pdo);
        $metadataIndexer = new MetadataIndexer($this->pdo);

        // Add various records
        $billWriter->record(1, 50, ['HB 100'], '00050.jpg');

        $speakerWriter->write(1, [
            ['name' => 'Test Speaker', 'start' => 75.0, 'legislator_id' => null],
        ]);

        $metadataIndexer->index(1, [
            'speakers' => [
                ['name' => 'Another Speaker', 'start_time' => '00:02:00'],
            ],
        ]);

        // Get all screenshot values
        $screenshots = $this->pdo->query('SELECT screenshot FROM video_index')->fetchAll(PDO::FETCH_COLUMN);

        $this->assertNotEmpty($screenshots, 'Should have inserted records');

        // Verify NONE contain text patterns that were previously used
        foreach ($screenshots as $screenshot) {
            $this->assertDoesNotMatchRegularExpression(
                '/speaker-/i',
                $screenshot,
                "Screenshot '$screenshot' should not contain 'speaker-' prefix"
            );

            $this->assertDoesNotMatchRegularExpression(
                '/bill-/i',
                $screenshot,
                "Screenshot '$screenshot' should not contain 'bill-' prefix"
            );

            $this->assertMatchesRegularExpression(
                '/^\d+$/',
                $screenshot,
                "Screenshot '$screenshot' must be purely numeric"
            );
        }
    }
}
