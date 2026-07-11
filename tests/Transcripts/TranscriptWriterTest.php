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

    public function testStripsFourByteUnicodeAndNullBytes(): void
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
            ['start' => 0.0, 'end' => 2.0, 'text' => "Hello \u{1F600} world\0!"],
        ]);

        $text = $pdo->query('SELECT text FROM video_transcript WHERE file_id = 1')->fetchColumn();
        $this->assertStringNotContainsString("\u{1F600}", $text);
        $this->assertStringNotContainsString("\0", $text);
        $this->assertStringContainsString('Hello', $text);
        $this->assertStringContainsString('world', $text);

        $file = $pdo->query('SELECT transcript, webvtt FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertStringNotContainsString("\u{1F600}", $file['transcript']);
        $this->assertStringNotContainsString("\u{1F600}", $file['webvtt']);
    }

    public function testStripsEmojiEvenWhenTextContainsMalformedUtf8(): void
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
        // \xC3\x28 is an invalid UTF-8 sequence; without repairing it first, the
        // /u regex returns null and the emoji would pass through unstripped.
        $writer->write(1, [
            ['start' => 0.0, 'end' => 2.0, 'text' => "Hello \xC3\x28 \u{1F600} world"],
        ]);

        $text = $pdo->query('SELECT text FROM video_transcript WHERE file_id = 1')->fetchColumn();
        $this->assertStringNotContainsString("\u{1F600}", $text);
        // Stored value must be valid UTF-8 (malformed bytes repaired).
        $this->assertSame($text, mb_convert_encoding($text, 'UTF-8', 'UTF-8'));
        $this->assertStringContainsString('Hello', $text);
        $this->assertStringContainsString('world', $text);
    }

    public function testPreservesMultibyteCharactersUpToThreeBytes(): void
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
            ['start' => 0.0, 'end' => 2.0, 'text' => "Café 你好世界"],
        ]);

        $text = $pdo->query('SELECT text FROM video_transcript WHERE file_id = 1')->fetchColumn();
        $this->assertStringContainsString('Café', $text);
        $this->assertStringContainsString('你好世界', $text);
    }
}
