<?php

namespace RichmondSunlight\VideoProcessor\Tests\Integration;

use Log;
use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Queue\InMemoryQueue;
use RichmondSunlight\VideoProcessor\Queue\JobDispatcher;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAITranscriber;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJob;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobPayloadMapper;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptProcessor;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

class TranscriptQueueIntegrationTest extends TestCase
{
    public function testDispatchAndProcessTranscriptJobViaInMemoryQueue(): void
    {
        $pdo = $this->createDatabase();
        $writer = new TranscriptWriter($pdo);
        $transcriber = new OpenAITranscriber(new NullHttpClient(), 'test');
        $processor = new TranscriptProcessor(
            $writer,
            $transcriber,
            new CaptionParser(),
            null,
            new NullLogger()
        );

        $webvttContent = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHello world";

        // Store webvtt in database (simulating what happens in production)
        $pdo->prepare('UPDATE files SET webvtt = :webvtt WHERE id = 1')
            ->execute([':webvtt' => $webvttContent]);

        $job = new TranscriptJob(
            1,
            'house',
            'file://unused',
            $webvttContent,
            null,
            'Test'
        );

        $mapper = new TranscriptJobPayloadMapper();
        $dispatcher = new JobDispatcher(new InMemoryQueue());

        $dispatcher->dispatch($mapper->toPayload($job));

        $messages = $dispatcher->receive();
        $this->assertCount(1, $messages);

        $receivedJob = $mapper->fromPayload($messages[0]->payload);

        // Simulate worker fetching webvtt/srt from database (not in payload due to size)
        $stmt = $pdo->prepare('SELECT webvtt, srt FROM files WHERE id = :id');
        $stmt->execute([':id' => $receivedJob->fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $receivedJob = new TranscriptJob(
                $receivedJob->fileId,
                $receivedJob->chamber,
                $receivedJob->videoUrl,
                $row['webvtt'] ?? null,
                $row['srt'] ?? null,
                $receivedJob->title
            );
        }

        $processor->process($receivedJob);
        $dispatcher->acknowledge($messages[0]);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM video_transcript')->fetchColumn();
        $this->assertSame(1, $count);
    }

    private function createDatabase(): PDO
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
        return $pdo;
    }
}
class NullLogger extends Log
{
    public function put($message, $level = 3)
    {
        return true;
    }
}

class NullHttpClient implements \GuzzleHttp\ClientInterface
{
    public function send(\Psr\Http\Message\RequestInterface $request, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function sendAsync(\Psr\Http\Message\RequestInterface $request, array $options = []): \GuzzleHttp\Promise\PromiseInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function request($method, $uri, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function requestAsync($method, $uri, array $options = []): \GuzzleHttp\Promise\PromiseInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function getConfig($option = null)
    {
        return null;
    }
}
