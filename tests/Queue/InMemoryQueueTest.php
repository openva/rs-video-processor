<?php

namespace RichmondSunlight\VideoProcessor\Tests\Queue;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Queue\InMemoryQueue;
use RichmondSunlight\VideoProcessor\Queue\JobPayload;

class InMemoryQueueTest extends TestCase
{
    public function testStoresAndRetrievesJobs(): void
    {
        $queue = new InMemoryQueue();
        $queue->send(new JobPayload('screenshots', 1, ['rate' => 1]));
        $queue->send(new JobPayload('transcript', 2, []));

        $batch = $queue->receive(2);
        $this->assertCount(2, $batch);
        $this->assertSame('screenshots', $batch[0]->payload->type);
        $this->assertSame(1, $batch[0]->payload->fileId);

        foreach ($batch as $job) {
            $queue->delete($job);
        }

        $this->assertCount(0, $queue->receive(1));
    }
}
