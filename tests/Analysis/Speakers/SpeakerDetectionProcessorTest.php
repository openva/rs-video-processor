<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Speakers;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\DiarizerInterface;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\LegislatorDirectory;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJob;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerMetadataExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerResultWriter;

class SpeakerDetectionProcessorTest extends TestCase
{
    public function testUsesMetadataBeforeDiarizer(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, nickname TEXT, suffix TEXT)');
        $pdo->exec("INSERT INTO people (id, first_name, last_name) VALUES (1, 'John', 'Smith')");
        $pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            time TEXT,
            screenshot TEXT,
            raw_text TEXT,
            type TEXT,
            linked_id INTEGER,
            ignored TEXT,
            date_created TEXT,
            new_speaker TEXT
        )');

        $metadataExtractor = new SpeakerMetadataExtractor();
        $diarizer = $this->createMock(DiarizerInterface::class);
        $diarizer->expects($this->never())->method('diarize');
        $legislators = new LegislatorDirectory($pdo);
        $writer = new SpeakerResultWriter($pdo);
        $processor = new SpeakerDetectionProcessor($metadataExtractor, $diarizer, $legislators, $writer, null);

        $job = new SpeakerJob(1, 'house', 'file://example', ['Speakers' => [['text' => 'Smith', 'startTime' => '00:00:10']]]);
        $processor->process($job);

        $count = $pdo->query('SELECT COUNT(*) FROM video_index')->fetchColumn();
        $this->assertSame(1, (int) $count);
    }
}
