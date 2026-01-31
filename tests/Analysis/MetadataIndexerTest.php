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

    public function testConvertsAbsoluteTimestampsToRelativeTimes(): void
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

        // Simulate real scraped data with absolute timestamps
        $metadata = [
            'speakers' => [
                ['name' => 'Speaker 1', 'start_time' => '2025-01-31T13:00:00'], // Video start
                ['name' => 'Speaker 2', 'start_time' => '2025-01-31T13:05:00'], // 5 minutes in
                ['name' => 'Speaker 3', 'start_time' => '2025-01-31T13:10:30'], // 10 minutes 30 seconds in
            ],
        ];

        $indexer = new MetadataIndexer($pdo);
        $indexer->index(42, $metadata);

        $rows = $pdo->query('SELECT raw_text, time, screenshot FROM video_index ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $rows);

        // First speaker should be at 00:00:00 (video start)
        $this->assertSame('Speaker 1', $rows[0]['raw_text']);
        $this->assertSame('00:00:00', $rows[0]['time']);
        $this->assertSame('00000001', $rows[0]['screenshot']);

        // Second speaker should be at 00:05:00 (5 minutes = 300 seconds)
        $this->assertSame('Speaker 2', $rows[1]['raw_text']);
        $this->assertSame('00:05:00', $rows[1]['time']);
        $this->assertSame('00000301', $rows[1]['screenshot']); // 300 + 1

        // Third speaker should be at 00:10:30 (10 minutes 30 seconds = 630 seconds)
        $this->assertSame('Speaker 3', $rows[2]['raw_text']);
        $this->assertSame('00:10:30', $rows[2]['time']);
        $this->assertSame('00000631', $rows[2]['screenshot']); // 630 + 1
    }
}
