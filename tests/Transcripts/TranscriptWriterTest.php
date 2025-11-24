<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

class TranscriptWriterTest extends TestCase
{
    public function testInsertsSegments(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE video_transcript (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            text TEXT,
            time_start TEXT,
            time_end TEXT,
            new_speaker TEXT,
            legislator_id INTEGER,
            date_created TEXT
        )');
        $writer = new TranscriptWriter($pdo);
        $writer->write(1, [
            ['start' => 1.0, 'end' => 2.5, 'text' => 'Hello'],
            ['start' => 2.5, 'end' => 4.0, 'text' => 'World'],
        ]);

        $count = $pdo->query('SELECT COUNT(*) FROM video_transcript')->fetchColumn();
        $this->assertSame(2, (int) $count);
    }
}
