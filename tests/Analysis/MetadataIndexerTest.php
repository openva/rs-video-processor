<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Metadata\MetadataIndexer;

class MetadataIndexerTest extends TestCase
{
    public function testIndexesAgendaAndSpeakers(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE video_index (
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

        $metadata = [
            'agenda' => [
                ['key' => 'A1', 'text' => 'HB 100', 'start_time' => '2025-01-31T13:00:00'],
            ],
            'speakers' => [
                ['name' => 'Delegate Example', 'start_time' => '2025-01-31T13:05:00'],
            ],
        ];

        $indexer = new MetadataIndexer($pdo);
        $indexer->index(42, $metadata);

        // Only speakers are indexed (as 'legislator' type)
        // Agenda items are not indexed because video_index only allows 'bill' and 'legislator' types
        $rows = $pdo->query('SELECT type, raw_text, screenshot FROM video_index ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('legislator', $rows[0]['type']);
        $this->assertSame('Delegate Example', $rows[0]['raw_text']);

        // Verify screenshot field is numeric
        $this->assertMatchesRegularExpression(
            '/^\d+$/',
            $rows[0]['screenshot'],
            'Screenshot field must contain only numeric values'
        );
    }
}
