<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use PDO;
use PHPUnit\Framework\TestCase;
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

        $writer = new TranscriptWriter($pdo);
        $parser = new CaptionParser();
        $transcriber = $this->createMock(OpenAITranscriber::class);
        $transcriber->expects($this->once())->method('transcribe')->willReturn([
            ['start' => 0.0, 'end' => 1.0, 'text' => 'Fallback']
        ]);

        $processor = new TranscriptProcessor($writer, $transcriber, $parser, new DummyAudioExtractor(), null);
        $video = $this->createSampleVideo();
        $job = new TranscriptJob(1, 'house', 'file://' . $video, '', '', null);
        $processor->process($job);
        @unlink($video);

        $count = $pdo->query('SELECT COUNT(*) FROM video_transcript')->fetchColumn();
        $this->assertSame(1, (int) $count);
    }

    private function createSampleVideo(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'video_') . '.mp4';
        $cmd = sprintf('ffmpeg -y -loglevel error -f lavfi -i testsrc=size=320x240:duration=2 -f lavfi -i sine=frequency=440:duration=2 -shortest -c:v mpeg4 -c:a aac %s', escapeshellarg($path));
        exec($cmd, $output, $status);
        if ($status !== 0) {
            $this->markTestSkipped('ffmpeg is required for transcript tests.');
        }
        return $path;
    }
}

class DummyAudioExtractor extends \RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor
{
    public function __construct()
    {
    }

    public function extract(string $videoUrl): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'audio_') . '.mp3';
        file_put_contents($temp, 'dummy');
        return $temp;
    }
}
