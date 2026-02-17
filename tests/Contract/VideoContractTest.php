<?php

namespace RichmondSunlight\VideoProcessor\Tests\Contract;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Contract\ContractValidator;
use RichmondSunlight\VideoProcessor\Contract\VideoReadContract;

class VideoContractTest extends TestCase
{
    private PDO $pdo;
    private VideoReadContract $contract;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        $this->contract = new VideoReadContract($this->pdo);
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
    }

    /**
     * Insert a file record and return its ID.
     */
    private function insertFile(array $overrides = []): int
    {
        $defaults = [
            'chamber' => 'house',
            'date' => '2025-01-10',
            'capture_directory' => 'house/floor/20250110',
            'transcript' => null,
            'webvtt' => null,
            'srt' => null,
        ];

        $data = array_merge($defaults, $overrides);

        $this->pdo->prepare('INSERT INTO files (chamber, date, capture_directory, transcript, webvtt, srt) VALUES (:chamber, :date, :capture_directory, :transcript, :webvtt, :srt)')
            ->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert a video_index row.
     */
    private function insertVideoIndex(int $fileId, string $time, string $screenshot, string $type, ?int $linkedId = null, ?string $rawText = null): int
    {
        $this->pdo->prepare('INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created) VALUES (:file_id, :time, :screenshot, :raw_text, :type, :linked_id, "n", :created)')
            ->execute([
                ':file_id' => $fileId,
                ':time' => $time,
                ':screenshot' => $screenshot,
                ':raw_text' => $rawText,
                ':type' => $type,
                ':linked_id' => $linkedId,
                ':created' => '2025-01-10 12:00:00',
            ]);

        return (int) $this->pdo->lastInsertId();
    }

    // ----------------------------------------------------------------
    // Test 1: Bill clips are found
    // ----------------------------------------------------------------
    public function testBillClipsAreFound(): void
    {
        $fileId = $this->insertFile();
        $billId = 42;

        // Insert video_index entries for this bill
        $this->insertVideoIndex($fileId, '00:05:00', '00000300', 'bill', $billId, 'HB 1234');
        $this->insertVideoIndex($fileId, '00:05:10', '00000310', 'bill', $billId, 'HB 1234');
        $this->insertVideoIndex($fileId, '00:05:20', '00000320', 'bill', $billId, 'HB 1234');

        $clips = $this->contract->byBill($billId);

        $this->assertCount(1, $clips);
        $clip = $clips[0];
        $this->assertSame($fileId, $clip['file_id']);
        $this->assertSame('house', $clip['chamber']);
        $this->assertSame('2025-01-10', $clip['date']);
        // Start should be 300 - 10 padding = 290
        $this->assertSame(290, $clip['start']);
        // End should be 320 + 10 padding = 330
        $this->assertSame(330, $clip['end']);
        $this->assertSame('00000300', $clip['screenshot']);
    }

    // ----------------------------------------------------------------
    // Test 2: Clip boundary detection (60s gap → two clips)
    // ----------------------------------------------------------------
    public function testClipBoundaryDetection(): void
    {
        $fileId = $this->insertFile();
        $billId = 42;

        // First group at 5:00
        $this->insertVideoIndex($fileId, '00:05:00', '00000300', 'bill', $billId);

        // Second group at 6:00 (60s gap > 30s threshold)
        $this->insertVideoIndex($fileId, '00:06:00', '00000360', 'bill', $billId);

        $clips = $this->contract->byBill($billId);

        $this->assertCount(2, $clips, 'Should produce two separate clips for a 60-second gap');
        $this->assertLessThan($clips[1]['start'], $clips[0]['end'], 'First clip should end before second clip starts');
    }

    // ----------------------------------------------------------------
    // Test 3: Clip merging (entries within 10s)
    // ----------------------------------------------------------------
    public function testClipMerging(): void
    {
        $fileId = $this->insertFile();
        $billId = 42;

        // Entries within 10 seconds of each other (well within 30s threshold)
        $this->insertVideoIndex($fileId, '00:05:00', '00000300', 'bill', $billId);
        $this->insertVideoIndex($fileId, '00:05:10', '00000310', 'bill', $billId);
        $this->insertVideoIndex($fileId, '00:05:20', '00000320', 'bill', $billId);

        $clips = $this->contract->byBill($billId);

        $this->assertCount(1, $clips, 'Entries within 10s should merge into a single clip');
    }

    // ----------------------------------------------------------------
    // Test 4: Screenshot URL format
    // ----------------------------------------------------------------
    public function testScreenshotUrlFormat(): void
    {
        $url = VideoReadContract::normalizeScreenshotUrl('house/floor/20250110', '00000102');

        $this->assertStringStartsWith('https://video.richmondsunlight.com/', $url);
        $this->assertStringNotContainsString('/screenshots/', $url);
        $this->assertStringNotContainsString('/video/video', $url);
        $this->assertMatchesRegularExpression('/\d{8}\.jpg$/', $url);
        $this->assertSame('https://video.richmondsunlight.com/house/floor/20250110/00000102.jpg', $url);
    }

    public function testScreenshotUrlWithExistingExtension(): void
    {
        $url = VideoReadContract::normalizeScreenshotUrl('senate/floor/20250115', '00000050.jpg');

        $this->assertSame('https://video.richmondsunlight.com/senate/floor/20250115/00000050.jpg', $url);
        // Should not double the .jpg
        $this->assertStringNotContainsString('.jpg.jpg', $url);
    }

    public function testScreenshotUrlStripsTrailingSlash(): void
    {
        $url = VideoReadContract::normalizeScreenshotUrl('house/floor/20250110/', '00000300');

        $this->assertSame('https://video.richmondsunlight.com/house/floor/20250110/00000300.jpg', $url);
    }

    // ----------------------------------------------------------------
    // Test 5: Captions present
    // ----------------------------------------------------------------
    public function testCaptionsPresent(): void
    {
        $fileId = $this->insertFile(['webvtt' => 'WEBVTT...', 'transcript' => 'Some transcript']);

        // Insert transcript segments
        $this->pdo->prepare('INSERT INTO video_transcript (file_id, text, time_start, time_end, new_speaker, date_created) VALUES (:fid, :text, :start, :end, :speaker, :created)')
            ->execute([':fid' => $fileId, ':text' => 'Hello world', ':start' => '00:00:01', ':end' => '00:00:03', ':speaker' => 'n', ':created' => '2025-01-10 12:00:00']);
        $this->pdo->prepare('INSERT INTO video_transcript (file_id, text, time_start, time_end, new_speaker, date_created) VALUES (:fid, :text, :start, :end, :speaker, :created)')
            ->execute([':fid' => $fileId, ':text' => 'Good morning', ':start' => '00:00:03', ':end' => '00:00:06', ':speaker' => 'y', ':created' => '2025-01-10 12:00:00']);

        $transcript = $this->contract->getTranscript($fileId);

        $this->assertCount(2, $transcript);
        $this->assertSame('Hello world', $transcript[0]['text']);
        $this->assertSame('00:00:01', $transcript[0]['time_start']);
        $this->assertSame('00:00:03', $transcript[0]['time_end']);
        $this->assertSame('n', $transcript[0]['new_speaker']);

        $this->assertSame('y', $transcript[1]['new_speaker']);
    }

    // ----------------------------------------------------------------
    // Test 6: Missing captions detected
    // ----------------------------------------------------------------
    public function testMissingCaptionsDetected(): void
    {
        $fileId = $this->insertFile([
            'webvtt' => '',
            'transcript' => '',
            'srt' => '',
        ]);

        $validator = new ContractValidator($this->pdo);
        $issues = $validator->validateFile($fileId);

        $codes = array_column($issues, 'code');
        $this->assertContains('MISSING_CAPTIONS', $codes);
        $this->assertContains('NO_TRANSCRIPT_SEGMENTS', $codes);
    }

    // ----------------------------------------------------------------
    // Test 7: Classification correctness
    // ----------------------------------------------------------------
    public function testClassificationCorrectness(): void
    {
        // Valid classification
        $fileId = $this->insertFile(['chamber' => 'house']);
        $validator = new ContractValidator($this->pdo);
        $issues = $validator->validateFile($fileId);
        $codes = array_column($issues, 'code');
        $this->assertNotContains('MISSING_CHAMBER', $codes);
        $this->assertNotContains('INVALID_CHAMBER', $codes);

        // Senate is also valid
        $fileId2 = $this->insertFile(['chamber' => 'senate']);
        $issues2 = $validator->validateFile($fileId2);
        $codes2 = array_column($issues2, 'code');
        $this->assertNotContains('MISSING_CHAMBER', $codes2);
        $this->assertNotContains('INVALID_CHAMBER', $codes2);
    }

    public function testInvalidChamberDetected(): void
    {
        $fileId = $this->insertFile(['chamber' => 'unknown']);
        $validator = new ContractValidator($this->pdo);
        $issues = $validator->validateFile($fileId);
        $codes = array_column($issues, 'code');
        $this->assertContains('INVALID_CHAMBER', $codes);
    }

    // ----------------------------------------------------------------
    // Test 8: Resolution completeness
    // ----------------------------------------------------------------
    public function testResolutionCompleteness(): void
    {
        $fileId = $this->insertFile();

        // Entries with raw_text but no linked_id → unresolved
        $this->insertVideoIndex($fileId, '00:01:00', '00000060', 'bill', null, 'HB 1234');
        $this->insertVideoIndex($fileId, '00:02:00', '00000120', 'legislator', null, 'John Smith');

        $validator = new ContractValidator($this->pdo);
        $issues = $validator->validateFile($fileId);
        $codes = array_column($issues, 'code');
        $this->assertContains('UNRESOLVED_RAW_TEXT', $codes);
    }

    public function testResolvedEntriesPassValidation(): void
    {
        $fileId = $this->insertFile(['webvtt' => 'WEBVTT', 'transcript' => 'text']);

        // Insert transcript segment so no MISSING_CAPTIONS
        $this->pdo->prepare('INSERT INTO video_transcript (file_id, text, time_start, time_end, new_speaker, date_created) VALUES (:fid, :text, :start, :end, :speaker, :created)')
            ->execute([':fid' => $fileId, ':text' => 'Hello', ':start' => '00:00:01', ':end' => '00:00:03', ':speaker' => 'n', ':created' => '2025-01-10 12:00:00']);

        // Entries with linked_id set → resolved
        $this->insertVideoIndex($fileId, '00:01:00', '00000060', 'bill', 42, 'HB 1234');

        $validator = new ContractValidator($this->pdo);
        $issues = $validator->validateFile($fileId);
        $codes = array_column($issues, 'code');
        $this->assertNotContains('UNRESOLVED_RAW_TEXT', $codes);
    }

    // ----------------------------------------------------------------
    // Additional: time_to_seconds
    // ----------------------------------------------------------------
    public function testTimeToSeconds(): void
    {
        $this->assertSame(0, VideoReadContract::timeToSeconds('00:00:00'));
        $this->assertSame(61, VideoReadContract::timeToSeconds('00:01:01'));
        $this->assertSame(3661, VideoReadContract::timeToSeconds('01:01:01'));
        $this->assertSame(300, VideoReadContract::timeToSeconds('00:05:00'));
    }

    public function testTimeToSecondsInvalidFormat(): void
    {
        $this->assertSame(0, VideoReadContract::timeToSeconds('invalid'));
        $this->assertSame(0, VideoReadContract::timeToSeconds('00:00'));
    }

    // ----------------------------------------------------------------
    // Additional: index_clips
    // ----------------------------------------------------------------
    public function testIndexClipsGroupsByLinkedId(): void
    {
        $fileId = $this->insertFile();

        // Bill group
        $this->insertVideoIndex($fileId, '00:01:00', '00000060', 'bill', 42, 'HB 1234');
        $this->insertVideoIndex($fileId, '00:01:10', '00000070', 'bill', 42, 'HB 1234');

        // Legislator group
        $this->insertVideoIndex($fileId, '00:02:00', '00000120', 'legislator', 99, 'Smith');

        $clips = $this->contract->indexClips($fileId);

        $this->assertArrayHasKey(42, $clips);
        $this->assertArrayHasKey(99, $clips);
        $this->assertCount(1, $clips[42], 'Bill entries should form one clip');
        $this->assertCount(1, $clips[99], 'Legislator entry should form one clip');
        $this->assertSame('bill', $clips[42][0]['type']);
        $this->assertSame('legislator', $clips[99][0]['type']);
    }

    // ----------------------------------------------------------------
    // Additional: Capture directory validation
    // ----------------------------------------------------------------
    public function testBadCaptureDirectoryDetected(): void
    {
        $fileId = $this->insertFile(['capture_directory' => 'https://example.com/video/house']);
        $validator = new ContractValidator($this->pdo);
        $issues = $validator->validateFile($fileId);
        $codes = array_column($issues, 'code');
        $this->assertContains('ABSOLUTE_CAPTURE_DIRECTORY', $codes);
    }

    public function testIgnoredEntriesExcluded(): void
    {
        $fileId = $this->insertFile();
        $billId = 42;

        $this->insertVideoIndex($fileId, '00:05:00', '00000300', 'bill', $billId);

        // Insert an ignored entry — manually set ignored='y'
        $this->pdo->prepare('INSERT INTO video_index (file_id, time, screenshot, type, linked_id, ignored, date_created) VALUES (:fid, :time, :shot, :type, :linked, "y", :created)')
            ->execute([':fid' => $fileId, ':time' => '00:10:00', ':shot' => '00000600', ':type' => 'bill', ':linked' => $billId, ':created' => '2025-01-10 12:00:00']);

        $clips = $this->contract->byBill($billId);

        // Should only find the non-ignored entry, producing one clip
        $this->assertCount(1, $clips);
    }
}
