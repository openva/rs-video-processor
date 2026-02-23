<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Speakers;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\DiarizerInterface;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\LegislatorDirectory;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\OcrSpeakerExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJob;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerMetadataExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerResultWriter;

class SpeakerDetectionProcessorTest extends TestCase
{
    public function testUsesMetadataBeforeDiarizer(): void
    {
        $pdo = $this->createTestDatabase();

        $metadataExtractor = new SpeakerMetadataExtractor();
        $diarizer = $this->createMock(DiarizerInterface::class);
        $diarizer->expects($this->never())->method('diarize');
        $ocrExtractor = $this->createMock(OcrSpeakerExtractor::class);
        $ocrExtractor->expects($this->never())->method('extract');
        $legislators = new LegislatorDirectory($pdo);
        $writer = new SpeakerResultWriter($pdo);
        $processor = new SpeakerDetectionProcessor($metadataExtractor, $diarizer, $ocrExtractor, $legislators, $writer, null);

        $job = new SpeakerJob(1, 'house', 'file://example', ['Speakers' => [['text' => 'Smith', 'startTime' => '00:00:10']]]);
        $processor->process($job);

        $count = $pdo->query('SELECT COUNT(*) FROM video_index')->fetchColumn();
        $this->assertSame(1, (int) $count);
    }

    public function testSkipsCommitteeVideosWhenNoMetadata(): void
    {
        $pdo = $this->createTestDatabase();

        $metadataExtractor = new SpeakerMetadataExtractor();
        $diarizer = $this->createMock(DiarizerInterface::class);
        $diarizer->expects($this->never())->method('diarize');
        $ocrExtractor = $this->createMock(OcrSpeakerExtractor::class);
        $ocrExtractor->expects($this->never())->method('extract');
        $legislators = new LegislatorDirectory($pdo);
        $writer = new SpeakerResultWriter($pdo);
        $processor = new SpeakerDetectionProcessor($metadataExtractor, $diarizer, $ocrExtractor, $legislators, $writer, null);

        $job = new SpeakerJob(
            1,
            'house',
            'file://example',
            ['event_type' => 'committee', 'committee_name' => 'Finance Committee']
        );
        $processor->process($job);

        $count = $pdo->query('SELECT COUNT(*) FROM video_index')->fetchColumn();
        $this->assertSame(0, (int) $count);
    }

    public function testDiarizesFloorVideosWhenNoMetadata(): void
    {
        $pdo = $this->createTestDatabase();

        $metadataExtractor = new SpeakerMetadataExtractor();
        $diarizer = $this->createMock(DiarizerInterface::class);
        $diarizer->expects($this->once())
            ->method('diarize')
            ->with('file://example')
            ->willReturn([
                ['name' => 'Speaker_00', 'start' => 0.0],
                ['name' => 'Speaker_01', 'start' => 10.0],
            ]);
        $ocrExtractor = $this->createMock(OcrSpeakerExtractor::class);
        $ocrExtractor->expects($this->never())->method('extract');
        $legislators = new LegislatorDirectory($pdo);
        $writer = new SpeakerResultWriter($pdo);
        $processor = new SpeakerDetectionProcessor($metadataExtractor, $diarizer, $ocrExtractor, $legislators, $writer, null);

        $job = new SpeakerJob(
            1,
            'house',
            'file://example',
            ['event_type' => 'floor', 'committee_name' => null]
        );
        $processor->process($job);

        $count = $pdo->query('SELECT COUNT(*) FROM video_index')->fetchColumn();
        $this->assertSame(2, (int) $count);
    }

    public function testOverwritesExistingEntries(): void
    {
        $pdo = $this->createTestDatabase();

        // Seed existing entries (as MetadataIndexer would)
        $pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, ignored, date_created)
                     VALUES (1, '00:00:00', '00000001', 'Old Speaker', 'legislator', 'n', datetime('now'))");

        $metadataExtractor = new SpeakerMetadataExtractor();
        $diarizer = $this->createMock(DiarizerInterface::class);
        $diarizer->expects($this->never())->method('diarize');
        $ocrExtractor = $this->createMock(OcrSpeakerExtractor::class);
        $ocrExtractor->expects($this->never())->method('extract');
        $legislators = new LegislatorDirectory($pdo);
        $writer = new SpeakerResultWriter($pdo);
        $processor = new SpeakerDetectionProcessor($metadataExtractor, $diarizer, $ocrExtractor, $legislators, $writer, null);

        $job = new SpeakerJob(1, 'house', 'file://example', ['Speakers' => [['text' => 'Smith', 'startTime' => '00:00:10']]]);
        $processor->process($job);

        $rows = $pdo->query("SELECT raw_text FROM video_index WHERE file_id = 1 AND type = 'legislator'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(1, $rows);
        $this->assertSame('Smith', $rows[0]);
    }

    private function createTestDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE people (
            id INTEGER PRIMARY KEY,
            shortname TEXT NOT NULL,
            name TEXT NOT NULL,
            name_formal TEXT NOT NULL,
            birthday DATE,
            race TEXT,
            sex TEXT,
            bio TEXT,
            date_created DATETIME NOT NULL,
            date_modified TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec("INSERT INTO people (id, shortname, name, name_formal, date_created)
                    VALUES (1, 'smith-j', 'John Smith', 'John A. Smith Jr.', datetime('now'))");
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
        return $pdo;
    }
}
