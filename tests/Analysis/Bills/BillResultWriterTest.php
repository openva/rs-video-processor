<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Bills;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillResultWriter;

class BillResultWriterTest extends TestCase
{
    public function testInsertsRows(): void
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

        $writer = new BillResultWriter($pdo);
        $writer->record(1, 10, ['HB1234'], '00010.jpg');

        $row = $pdo->query('SELECT screenshot, raw_text, type FROM video_index')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('00010.jpg', $row['screenshot']);
        $this->assertSame('HB1234', $row['raw_text']);
        $this->assertSame('bill', $row['type']);
    }

    public function testInsertsMultipleBillsWithSameScreenshot(): void
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

        $writer = new BillResultWriter($pdo);
        $writer->record(1, 10, ['HB1234', 'SB567'], '00010.jpg');

        $rows = $pdo->query('SELECT screenshot, raw_text FROM video_index ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $this->assertSame('00010.jpg', $rows[0]['screenshot']);
        $this->assertSame('HB1234', $rows[0]['raw_text']);
        $this->assertSame('00010.jpg', $rows[1]['screenshot']);
        $this->assertSame('SB567', $rows[1]['raw_text']);
    }
}
