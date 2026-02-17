<?php

namespace RichmondSunlight\VideoProcessor\Tests\Contract;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Contract\VideoReadContract;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAITranscriber;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJob;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptProcessor;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;
use RichmondSunlight\VideoProcessor\Resolution\BillResolver;
use RichmondSunlight\VideoProcessor\Resolution\FuzzyMatcher\BillNumberMatcher;
use RichmondSunlight\VideoProcessor\Resolution\RawTextResolver;

/**
 * Pipeline-stage tests that run actual processor stages against fixtures
 * and then validate the database contract.
 */
class PipelineContractTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            committee_id INTEGER,
            title TEXT,
            description TEXT,
            type TEXT,
            length TEXT,
            date TEXT,
            sponsor TEXT,
            width INTEGER,
            height INTEGER,
            fps REAL,
            capture_rate REAL,
            capture_directory TEXT,
            path TEXT,
            html TEXT,
            author_name TEXT,
            license TEXT,
            date_created TEXT,
            date_modified TIMESTAMP,
            video_index_cache TEXT,
            raw_metadata TEXT,
            transcript TEXT,
            webvtt TEXT,
            srt TEXT
        )');

        $this->pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            time TEXT,
            screenshot TEXT,
            raw_text TEXT,
            type TEXT,
            linked_id INTEGER,
            ignored TEXT DEFAULT "n",
            date_created TEXT
        )');

        $this->pdo->exec('CREATE TABLE video_transcript (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            text TEXT,
            time_start TEXT,
            time_end TEXT,
            new_speaker TEXT,
            legislator_id INTEGER,
            date_created TEXT
        )');

        $this->pdo->exec('CREATE TABLE sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date_started TEXT,
            date_ended TEXT
        )');

        $this->pdo->exec('CREATE TABLE bills (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER,
            number TEXT,
            chamber TEXT
        )');
    }

    // ----------------------------------------------------------------
    // Test 1: Transcript processing → contract
    // ----------------------------------------------------------------
    public function testTranscriptProcessingContract(): void
    {
        // Create a file record
        $this->pdo->exec("INSERT INTO files (id, chamber, date, capture_directory) VALUES (1, 'house', '2025-01-10', 'house/floor/20250110')");

        $webvtt = "WEBVTT\n\n00:00:01.000 --> 00:00:05.000\nThe House will come to order.\n\n00:00:06.000 --> 00:00:12.000\nHouse Bill 1234 is now before the body.\n\n00:00:13.000 --> 00:00:18.000\nThe clerk will read the bill.";

        // Process through TranscriptProcessor
        $writer = new TranscriptWriter($this->pdo);
        $transcriber = $this->createMock(OpenAITranscriber::class);
        $transcriber->expects($this->never())->method('transcribe');

        $processor = new TranscriptProcessor($writer, $transcriber, new CaptionParser(), null, null);
        $job = new TranscriptJob(1, 'house', 'file://unused', $webvtt, null, null);
        $processor->process($job);

        // Now verify the contract: transcript segments are queryable
        $contract = new VideoReadContract($this->pdo);
        $transcript = $contract->getTranscript(1);

        $this->assertCount(3, $transcript);
        $this->assertSame('The House will come to order.', $transcript[0]['text']);
        $this->assertSame('00:00:01', $transcript[0]['time_start']);
        $this->assertSame('00:00:05', $transcript[0]['time_end']);

        $this->assertSame('House Bill 1234 is now before the body.', $transcript[1]['text']);
        $this->assertSame('The clerk will read the bill.', $transcript[2]['text']);

        // Verify files table was also updated
        $file = $this->pdo->query('SELECT transcript, webvtt FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($file['transcript']);
        $this->assertStringContainsString('WEBVTT', $file['webvtt']);
    }

    // ----------------------------------------------------------------
    // Test 2: Bill detection → resolution → contract
    // ----------------------------------------------------------------
    public function testBillDetectionResolutionContract(): void
    {
        // Set up session and bills
        $this->pdo->exec("INSERT INTO sessions (id, date_started, date_ended) VALUES (1, '2025-01-08', '2025-03-08')");
        $this->pdo->exec("INSERT INTO bills (id, session_id, number, chamber) VALUES (42, 1, 'HB1234', 'house')");

        // Create a file
        $this->pdo->exec("INSERT INTO files (id, chamber, date, capture_directory, video_index_cache) VALUES (1, 'house', '2025-01-10', 'house/floor/20250110', '{}')");

        // Pre-populate video_index rows as if bill detection has run
        $this->pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
            VALUES (1, '00:05:00', '00000300', 'HB 1234', 'bill', NULL, 'n', '2025-01-10 12:00:00')");
        $this->pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
            VALUES (1, '00:05:10', '00000310', 'HB 1234', 'bill', NULL, 'n', '2025-01-10 12:00:00')");

        // Run RawTextResolver
        $resolver = new RawTextResolver($this->pdo);
        $stats = $resolver->resolveFile(1);

        $this->assertSame(2, $stats['total']);
        $this->assertSame(2, $stats['resolved']);

        // Now verify the contract: by_bill should return clips
        $contract = new VideoReadContract($this->pdo);
        $clips = $contract->byBill(42);

        $this->assertCount(1, $clips, 'Resolved bill entries should produce a clip');
        $this->assertSame(1, $clips[0]['file_id']);
        $this->assertSame('house', $clips[0]['chamber']);
        $this->assertSame('2025-01-10', $clips[0]['date']);
        // Verify the clip has reasonable start/end times
        $this->assertGreaterThan(0, $clips[0]['start']);
        $this->assertGreaterThan($clips[0]['start'], $clips[0]['end']);
    }

    // ----------------------------------------------------------------
    // Test: Multiple bills in same file produce separate clip groups
    // ----------------------------------------------------------------
    public function testMultipleBillsProduceSeparateClipGroups(): void
    {
        $this->pdo->exec("INSERT INTO sessions (id, date_started, date_ended) VALUES (1, '2025-01-08', '2025-03-08')");
        $this->pdo->exec("INSERT INTO bills (id, session_id, number, chamber) VALUES (42, 1, 'HB1234', 'house')");
        $this->pdo->exec("INSERT INTO bills (id, session_id, number, chamber) VALUES (43, 1, 'HB5678', 'house')");

        $this->pdo->exec("INSERT INTO files (id, chamber, date, capture_directory) VALUES (1, 'house', '2025-01-10', 'house/floor/20250110')");

        // Bill 42 entries
        $this->pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
            VALUES (1, '00:05:00', '00000300', 'HB 1234', 'bill', 42, 'n', '2025-01-10 12:00:00')");

        // Bill 43 entries (later in video)
        $this->pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
            VALUES (1, '00:15:00', '00000900', 'HB 5678', 'bill', 43, 'n', '2025-01-10 12:00:00')");

        $contract = new VideoReadContract($this->pdo);

        // index_clips should group them separately
        $allClips = $contract->indexClips(1);

        $this->assertArrayHasKey(42, $allClips);
        $this->assertArrayHasKey(43, $allClips);

        // by_bill should only return the specific bill's clips
        $bill42Clips = $contract->byBill(42);
        $this->assertCount(1, $bill42Clips);

        $bill43Clips = $contract->byBill(43);
        $this->assertCount(1, $bill43Clips);
    }
}
