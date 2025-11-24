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
        $writer->record(1, 10, ['HB1234']);
        $count = $pdo->query('SELECT COUNT(*) FROM video_index')->fetchColumn();
        $this->assertSame(1, (int) $count);
    }
}
