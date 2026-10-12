<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;
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
