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
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transcript TEXT,
            webvtt TEXT,
            date_modified TIMESTAMP
        )');
        $pdo->exec('INSERT INTO files (id) VALUES (1)');

        $writer = new TranscriptWriter($pdo);
        $writer->write(1, [
            ['start' => 1.0, 'end' => 2.5, 'text' => 'Hello'],
            ['start' => 2.5, 'end' => 4.0, 'text' => 'World'],
        ]);

        $count = $pdo->query('SELECT COUNT(*) FROM video_transcript')->fetchColumn();
        $this->assertSame(2, (int) $count);

        // Verify files table is updated
        $file = $pdo->query('SELECT transcript, webvtt FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Hello World', $file['transcript']);
        $this->assertStringContainsString('WEBVTT', $file['webvtt']);
        $this->assertStringContainsString('00:00:01.000 --> 00:00:02.500', $file['webvtt']);
        $this->assertStringContainsString('Hello', $file['webvtt']);
    }

    public function testWriteTwiceDoesNotProduceDuplicates(): void
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
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transcript TEXT,
            webvtt TEXT,
            date_modified TIMESTAMP
        )');
        $pdo->exec('INSERT INTO files (id) VALUES (1)');

        $writer = new TranscriptWriter($pdo);
        $segments = [
            ['start' => 1.0, 'end' => 2.5, 'text' => 'Hello'],
            ['start' => 2.5, 'end' => 4.0, 'text' => 'World'],
        ];
        $writer->write(1, $segments);
        $writer->write(1, $segments);

        $count = $pdo->query('SELECT COUNT(*) FROM video_transcript WHERE file_id = 1')->fetchColumn();
        $this->assertSame(2, (int) $count);
    }
}
