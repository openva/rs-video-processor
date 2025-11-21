<?php

namespace RichmondSunlight\VideoProcessor\Tests\Queue;

use Aws\CommandInterface;
use Aws\Result;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Promise\Create;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Queue\JobPayload;
use RichmondSunlight\VideoProcessor\Queue\SqsQueue;

class SqsQueueTest extends TestCase
{
    public function testSendsFifoMetadata(): void
    {
        $captured = null;
        $client = $this->buildClient(function (CommandInterface $command) use (&$captured): Result {
            $captured = $command->toArray();
            return new Result();
        });

        $queue = new SqsQueue($client, 'https://example.com/queue.fifo');
        $queue->send(new JobPayload('transcript', 1, ['foo' => 'bar']));

        $this->assertNotNull($captured);
        $this->assertSame('https://example.com/queue.fifo', $captured['QueueUrl']);
        $this->assertSame('transcript', $captured['MessageGroupId']);
        $this->assertSame(md5($captured['MessageBody']), $captured['MessageDeduplicationId']);
    }

    public function testSkipsFifoMetadataForStandardQueues(): void
    {
        $captured = null;
        $client = $this->buildClient(function (CommandInterface $command) use (&$captured): Result {
            $captured = $command->toArray();
            return new Result();
        });

        $queue = new SqsQueue($client, 'https://example.com/standard');
        $queue->send(new JobPayload('transcript', 1, []));

        $this->assertNotNull($captured);
        $this->assertArrayNotHasKey('MessageGroupId', $captured);
        $this->assertArrayNotHasKey('MessageDeduplicationId', $captured);
    }

    private function buildClient(callable $callback): SqsClient
    {
        $handler = function (CommandInterface $command) use ($callback) {
            $result = $callback($command);
            return Create::promiseFor($result);
        };

        return new SqsClient([
            'version' => '2012-11-05',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
        ]);
    }
}
