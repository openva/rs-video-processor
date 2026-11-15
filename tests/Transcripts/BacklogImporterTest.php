<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;
use RichmondSunlight\VideoProcessor\Transcripts\BacklogImporter;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

class BacklogImporterTest extends TestCase
{
    public static function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE files (id INTEGER PRIMARY KEY, path TEXT, transcript TEXT, webvtt TEXT,
            caption_alignment TEXT, caption_offset REAL, date_modified TIMESTAMP)');
        $pdo->exec('CREATE TABLE video_transcript (id INTEGER PRIMARY KEY AUTOINCREMENT, file_id INTEGER, text TEXT,
            time_start TEXT, time_end TEXT, new_speaker TEXT, legislator_id INTEGER, date_created TEXT)');
        TestDatabaseFactory::addPipelineTables($pdo);
        $pdo->exec("INSERT INTO files (id, path) VALUES (7, 'https://archive.org/download/va-house-20210117-7/va-house-20210117-7.mp4')");

        return $pdo;
    }

    public static function importer(PDO $pdo): BacklogImporter
    {
        return new BacklogImporter($pdo, new TranscriptWriter($pdo), new JobLedger($pdo));
    }

    public static function made(int $fileId = 7): array
    {
        return ['file_id' => $fileId, 'model' => 'whisper-large-v3-turbo', 'segments' => [
            ['start' => 1.0, 'end' => 3.5, 'text' => 'The House will come to order.'],
        ]];
    }

    /** As the transcript stage would: recorded as Whisper's, the job done, the captions sent on. */
    public function testStoresATranscriptAsTheStageWould(): void
    {
        $pdo = self::database();

        $this->assertSame(BacklogImporter::STORED, self::importer($pdo)->import(self::made(), true));

        $this->assertSame('The House will come to order.', $pdo->query('SELECT text FROM video_transcript WHERE file_id = 7')->fetchColumn());
        $this->assertSame('whisper', $pdo->query('SELECT caption_alignment FROM files WHERE id = 7')->fetchColumn());
        $jobs = $pdo->query('SELECT stage, status FROM video_jobs WHERE file_id = 7 ORDER BY stage')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['archive_captions' => 'pending', 'transcript' => 'done'], $jobs);
    }

    public function testADryRunStoresNothing(): void
    {
        $pdo = self::database();

        $this->assertSame(BacklogImporter::WOULD_STORE, self::importer($pdo)->import(self::made(), false));
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM video_transcript')->fetchColumn());
    }

    /** A transcript is never replaced: the stage, or an earlier import, got there first. */
    public function testLeavesAnExistingTranscriptAlone(): void
    {
        $pdo = self::database();
        $pdo->exec("INSERT INTO video_transcript (file_id, text) VALUES (7, 'Already here')");

        $this->assertSame(BacklogImporter::HAS_TRANSCRIPT, self::importer($pdo)->import(self::made(), true));
        $this->assertSame(['Already here'], $pdo->query('SELECT text FROM video_transcript')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testRejectsWhatIsNotATranscriptOfAKnownVideo(): void
    {
        $importer = self::importer(self::database());

        $this->assertSame(BacklogImporter::INVALID, $importer->import(null, true));
        $this->assertSame(BacklogImporter::INVALID, $importer->import(['file_id' => 7, 'segments' => []], true));
        $this->assertSame(BacklogImporter::INVALID, $importer->import(['file_id' => 7, 'segments' => [['start' => 1]]], true));
        $this->assertSame(BacklogImporter::INVALID, $importer->import(self::made(999), true));
    }
}
