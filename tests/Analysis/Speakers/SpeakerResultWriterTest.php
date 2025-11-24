<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Speakers;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerResultWriter;

class SpeakerResultWriterTest extends TestCase
{
    public function testInsertsSpeakerSegments(): void
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
            date_created TEXT,
            new_speaker TEXT
        )');
        $writer = new SpeakerResultWriter($pdo);
        $writer->write(1, [
            ['name' => 'Smith', 'start' => 10, 'legislator_id' => 5],
        ]);
        $count = $pdo->query('SELECT COUNT(*) FROM video_index')->fetchColumn();
        $this->assertSame(1, (int) $count);
    }
}
