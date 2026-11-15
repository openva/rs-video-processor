<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Transcripts\BacklogImporter;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptBacklog;

class TranscriptBacklogTest extends TestCase
{
    /** @var list<array{string,array<string,mixed>}> every S3 call made, name and arguments */
    private array $calls = [];

    private function s3(array $results): S3Client
    {
        $mock = new MockHandler();
        foreach ($results as $result) {
            $mock->append(function (CommandInterface $command) use ($result) {
                $this->calls[] = [$command->getName(), $command->toArray()];
                return $result;
            });
        }

        return new S3Client([
            'region' => 'us-east-1',
            'version' => '2006-03-01',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mock,
        ]);
    }

    private function names(): array
    {
        return array_map(static fn(array $call): string => $call[0] . ' ' . ($call[1]['Key'] ?? $call[1]['Prefix']), $this->calls);
    }

    /**
     * Stored, and already-stored, transcripts are deleted from the bucket; one
     * that could not be stored for an error stays, to be tried next pass.
     */
    public function testImportsWhatTheMacSentAndDeletesWhatIsDone(): void
    {
        $pdo = BacklogImporterTest::database();
        $pdo->exec("INSERT INTO files (id, path) VALUES (8, 'x')");
        $pdo->exec("INSERT INTO video_transcript (file_id, text) VALUES (8, 'Already here')");
        $prefix = TranscriptBacklog::TRANSCRIPTS_PREFIX;
        $backlog = new TranscriptBacklog($this->s3([
            new Result(['Contents' => [['Key' => "{$prefix}7.json"], ['Key' => "{$prefix}8.json"], ['Key' => "{$prefix}9.json"]]]),
            new Result(['Body' => json_encode(BacklogImporterTest::made(7))]),
            new Result([]),
            new Result(['Body' => json_encode(BacklogImporterTest::made(8))]),
            new Result([]),
            new \Aws\S3\Exception\S3Exception('Slow down', new \Aws\Command('GetObject')),
        ]));

        $tally = $backlog->importAll(BacklogImporterTest::importer($pdo), true);

        $this->assertSame([BacklogImporter::STORED => 1, BacklogImporter::HAS_TRANSCRIPT => 1, 'error' => 1], $tally);
        $this->assertSame([
            "ListObjectsV2 {$prefix}",
            "GetObject {$prefix}7.json",
            "DeleteObject {$prefix}7.json",
            "GetObject {$prefix}8.json",
            "DeleteObject {$prefix}8.json",
            "GetObject {$prefix}9.json",
        ], $this->names());
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM video_transcript WHERE file_id = 7')->fetchColumn());
    }

    public function testADryRunDeletesNothing(): void
    {
        $prefix = TranscriptBacklog::TRANSCRIPTS_PREFIX;
        $backlog = new TranscriptBacklog($this->s3([
            new Result(['Contents' => [['Key' => "{$prefix}7.json"]]]),
            new Result(['Body' => json_encode(BacklogImporterTest::made(7))]),
        ]));

        $tally = $backlog->importAll(BacklogImporterTest::importer(BacklogImporterTest::database()), false);

        $this->assertSame([BacklogImporter::WOULD_STORE => 1], $tally);
        $this->assertSame(["ListObjectsV2 {$prefix}", "GetObject {$prefix}7.json"], $this->names());
    }

    public function testTheListRoundTrips(): void
    {
        $videos = [['id' => 7, 'chamber' => 'house', 'date' => '2021-01-17', 'path' => 'https://archive.org/download/x/x.mp4', 'title' => null]];
        $backlog = new TranscriptBacklog($this->s3([new Result([])]));
        $backlog->publishList($videos);
        [$name, $args] = $this->calls[0];
        $this->assertSame(['PutObject', TranscriptBacklog::LIST_KEY], [$name, $args['Key']]);

        $reader = new TranscriptBacklog($this->s3([new Result(['Body' => $args['Body']])]));
        $this->assertSame($videos, $reader->fetchList());
    }
}
