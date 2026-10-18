<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Tests\Support\TestLogger;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionAligner;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionAlignment;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAITranscriber;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJob;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptProcessor;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

class TranscriptProcessorTest extends TestCase
{
    public function testUsesCaptionsWhenAvailable(): void
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
        $parser = new CaptionParser();
        $transcriber = $this->createMock(OpenAITranscriber::class);
        $transcriber->expects($this->never())->method('transcribe');

        $processor = new TranscriptProcessor($writer, $transcriber, $parser, null, null);
        $job = new TranscriptJob(1, 'house', 'file://unused', "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHello", null, null);
        $processor->process($job);

        $count = $pdo->query('SELECT COUNT(*) FROM video_transcript')->fetchColumn();
        $this->assertSame(1, (int) $count);
    }

    public function testFallsBackToOpenAi(): void
    {
        $this->requireFfmpeg();

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
        $parser = new CaptionParser();
        $transcriber = $this->createMock(OpenAITranscriber::class);
        $transcriber->expects($this->once())->method('transcribe')->willReturn([
            ['start' => 0.0, 'end' => 1.0, 'text' => 'Fallback']
        ]);

        $processor = new TranscriptProcessor($writer, $transcriber, $parser, new AudioExtractor(), null);
        $video = $this->getVideoFixture('house-floor.mp4');
        $job = new TranscriptJob(1, 'house', 'file://' . $video, '', '', null);
        $processor->process($job);

        $count = $pdo->query('SELECT COUNT(*) FROM video_transcript')->fetchColumn();
        $this->assertSame(1, (int) $count);
    }

    public function testChunkOffsetsAreAddedToSegmentTimes(): void
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

        $first = tempnam(sys_get_temp_dir(), 'chunk_');
        $second = tempnam(sys_get_temp_dir(), 'chunk_');

        $extractor = new class ($first, $second) extends AudioExtractor {
            public function __construct(private string $first, private string $second)
            {
                parent::__construct();
            }

            public function extractChunks(string $videoUrl, int $segmentSeconds = 1200): array
            {
                return [
                    ['path' => $this->first, 'offset' => 0.0],
                    ['path' => $this->second, 'offset' => 1200.0],
                ];
            }
        };

        $transcriber = $this->createMock(OpenAITranscriber::class);
        $transcriber->expects($this->exactly(2))->method('transcribe')->willReturn([
            ['start' => 5.0, 'end' => 9.0, 'text' => 'Hello'],
        ]);

        $processor = new TranscriptProcessor(new TranscriptWriter($pdo), $transcriber, new CaptionParser(), $extractor, null);
        $processor->process(new TranscriptJob(1, 'house', 'file://unused', '', '', null));

        $starts = $pdo->query('SELECT time_start FROM video_transcript ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['00:00:05', '00:20:05'], $starts);
        $this->assertFileDoesNotExist($first, 'Chunk files must be deleted after use.');
        $this->assertFileDoesNotExist($second);
    }

    /**
     * Transcribing a long video takes minutes with no database activity, so the
     * connection must be renewed immediately before the results are written.
     */
    public function testReconnectsBeforeWritingTranscript(): void
    {
        $calls = [];
        $writer = $this->createMock(TranscriptWriter::class);
        $writer->method('reconnect')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'reconnect';
        });
        $writer->method('write')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'write';
        });

        $processor = new TranscriptProcessor(
            $writer,
            $this->createMock(OpenAITranscriber::class),
            new CaptionParser(),
            null,
            null
        );
        $processor->process(new TranscriptJob(1, 'house', 'file://unused', "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHello", null, null));

        $this->assertSame(['reconnect', 'reconnect', 'write'], $calls);
    }

    public function testCaptionsAreShiftedByTheMeasuredOffset(): void
    {
        $pdo = $this->alignmentDatabase();
        $aligner = $this->aligner(new CaptionAlignment(true, 7.0, 40, 1.2, [], false, ''));
        $processor = new TranscriptProcessor(new TranscriptWriter($pdo), $this->createMock(OpenAITranscriber::class), new CaptionParser(), null, null, $aligner);

        $processor->process(new TranscriptJob(1, 'house', 'https://example.com/v.mp4', "WEBVTT\n\n00:00:10.000 --> 00:00:12.000\nHello\n\n00:00:03.000 --> 00:00:05.000\nEarly", null, null));

        $rows = $pdo->query('SELECT text, time_start, time_end FROM video_transcript ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(['text' => 'Hello', 'time_start' => '00:00:03', 'time_end' => '00:00:05'], $rows[0]);
        // Nothing goes below zero.
        $this->assertSame(['text' => 'Early', 'time_start' => '00:00:00', 'time_end' => '00:00:00'], $rows[1]);
        $file = $pdo->query('SELECT webvtt, caption_alignment, caption_offset FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertStringContainsString('00:00:03.000 --> 00:00:05.000', $file['webvtt']);
        $this->assertSame('aligned', $file['caption_alignment']);
        $this->assertEquals(7.0, $file['caption_offset']);
        $this->assertSame(['https://example.com/v.mp4'], $aligner->urls);
    }

    public function testWhisperTranscriptsAreNeverShifted(): void
    {
        $pdo = $this->alignmentDatabase();
        $aligner = $this->aligner(new CaptionAlignment(true, 7.0, 40, 1.2, [], false, ''));
        $extractor = new class extends AudioExtractor {
            public function extractChunks(string $videoUrl, int $segmentSeconds = 1200): array
            {
                return [['path' => tempnam(sys_get_temp_dir(), 'chunk_'), 'offset' => 0.0]];
            }
        };
        $transcriber = $this->createMock(OpenAITranscriber::class);
        $transcriber->method('transcribe')->willReturn([['start' => 10.0, 'end' => 12.0, 'text' => 'Heard']]);
        $processor = new TranscriptProcessor(new TranscriptWriter($pdo), $transcriber, new CaptionParser(), $extractor, null, $aligner);

        $processor->process(new TranscriptJob(1, 'house', 'https://example.com/v.mp4', null, null, null));

        $this->assertSame('00:00:10', $pdo->query('SELECT time_start FROM video_transcript')->fetchColumn());
        $this->assertSame('whisper', $pdo->query('SELECT caption_alignment FROM files WHERE id = 1')->fetchColumn());
        $this->assertSame([], $aligner->urls, 'Whisper output is not measured.');
    }

    public function testUnalignedCaptionsAreStoredAsTheyAre(): void
    {
        $pdo = $this->alignmentDatabase();
        $logger = new TestLogger();
        $aligner = $this->aligner(new CaptionAlignment(false, 3.0, 6, 40.0, [], false, 'only 6 matches'));
        $processor = new TranscriptProcessor(new TranscriptWriter($pdo), $this->createMock(OpenAITranscriber::class), new CaptionParser(), null, $logger, $aligner);

        $processor->process(new TranscriptJob(1, 'house', 'https://example.com/v.mp4', "WEBVTT\n\n00:00:10.000 --> 00:00:12.000\nHello", null, null));

        $this->assertSame('00:00:10', $pdo->query('SELECT time_start FROM video_transcript')->fetchColumn());
        $file = $pdo->query('SELECT caption_alignment, caption_offset FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['caption_alignment' => 'unaligned', 'caption_offset' => null], $file);
        $warnings = array_filter($logger->entries, static fn($e) => $e['level'] === 4 && str_contains($e['message'], 'could not be aligned'));
        $this->assertCount(1, $warnings);
    }

    public function testCaptionsAreStoredWhenTheMeasurementFails(): void
    {
        $pdo = $this->alignmentDatabase();
        $aligner = new class extends CaptionAligner {
            public function __construct()
            {
            }

            public function align(array $segments, string $videoUrl): CaptionAlignment
            {
                throw new \RuntimeException('ffmpeg could not read the video');
            }
        };
        $processor = new TranscriptProcessor(new TranscriptWriter($pdo), $this->createMock(OpenAITranscriber::class), new CaptionParser(), null, null, $aligner);

        $processor->process(new TranscriptJob(1, 'house', 'https://example.com/v.mp4', "WEBVTT\n\n00:00:10.000 --> 00:00:12.000\nHello", null, null));

        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM video_transcript')->fetchColumn());
        $this->assertSame('unaligned', $pdo->query('SELECT caption_alignment FROM files WHERE id = 1')->fetchColumn());
    }

    /** An aligner that reports $result without listening to anything. */
    private function aligner(CaptionAlignment $result): CaptionAligner
    {
        return new class ($result) extends CaptionAligner {
            public array $urls = [];

            public function __construct(private CaptionAlignment $result)
            {
            }

            public function align(array $segments, string $videoUrl): CaptionAlignment
            {
                $this->urls[] = $videoUrl;
                return $this->result;
            }
        };
    }

    private function alignmentDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE video_transcript (
            id INTEGER PRIMARY KEY AUTOINCREMENT, file_id INTEGER, text TEXT, time_start TEXT, time_end TEXT,
            new_speaker TEXT, legislator_id INTEGER, date_created TEXT
        )');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT, transcript TEXT, webvtt TEXT, date_modified TIMESTAMP,
            caption_offset REAL, caption_alignment TEXT
        )');
        $pdo->exec('INSERT INTO files (id) VALUES (1)');

        return $pdo;
    }

    private function getVideoFixture(string $filename): string
    {
        $path = __DIR__ . '/../fixtures/' . $filename;
        if (!file_exists($path)) {
            $this->markTestSkipped('Missing video fixture ' . $filename . '. Run bin/fetch_test_fixtures.php.');
        }
        return $path;
    }

    private function requireFfmpeg(): void
    {
        exec('ffmpeg -version > /dev/null 2>&1', $output, $status);
        if ($status !== 0) {
            $this->markTestSkipped('ffmpeg is required for transcript tests.');
        }
    }
}
