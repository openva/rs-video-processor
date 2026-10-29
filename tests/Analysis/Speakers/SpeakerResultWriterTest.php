<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Speakers;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerResultWriter;

class SpeakerResultWriterTest extends TestCase
{
    /** video_index.raw_text is varchar(100), which MariaDB enforces and SQLite does not. */
    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            time TEXT,
            screenshot TEXT,
            raw_text TEXT CHECK (length(raw_text) <= 100 AND raw_text <> 'unstorable'),
            type TEXT,
            linked_id INTEGER,
            ignored TEXT,
            date_created TEXT
        )");
        return $pdo;
    }

    public function testInsertsSpeakerSegments(): void
    {
        $pdo = $this->database();
        $writer = new SpeakerResultWriter($pdo);
        $writer->write(1, [
            ['name' => 'Smith', 'start' => 10, 'legislator_id' => 5],
        ]);
        $count = $pdo->query('SELECT COUNT(*) FROM video_index')->fetchColumn();
        $this->assertSame(1, (int) $count);
    }

    public function testANameTooLongForTheColumnIsShortened(): void
    {
        $pdo = $this->database();
        (new SpeakerResultWriter($pdo))->write(1, [
            ['name' => str_repeat('é', 150), 'start' => 10, 'legislator_id' => null],
        ]);

        $this->assertSame(100, mb_strlen($pdo->query('SELECT raw_text FROM video_index')->fetchColumn()));
    }

    /**
     * Any legislator row marks the video as done for the speaker queue, so a
     * write that fails partway must leave the earlier rows as they were.
     */
    public function testAFailedWriteChangesNothing(): void
    {
        $pdo = $this->database();
        $writer = new SpeakerResultWriter($pdo);
        $writer->write(1, [['name' => 'Smith', 'start' => 10, 'legislator_id' => 5]]);

        try {
            $writer->write(1, [
                ['name' => 'Jones', 'start' => 10, 'legislator_id' => 6],
                ['name' => 'unstorable', 'start' => 20, 'legislator_id' => null],
            ]);
            $this->fail('Expected the insert to fail.');
        } catch (\PDOException) {
        }

        $this->assertSame(['Smith'], $pdo->query('SELECT raw_text FROM video_index')->fetchAll(PDO::FETCH_COLUMN));
    }
}
