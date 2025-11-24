<?php

namespace RichmondSunlight\VideoProcessor\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJob;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJobPayloadMapper;
use RichmondSunlight\VideoProcessor\Queue\InMemoryQueue;
use RichmondSunlight\VideoProcessor\Queue\JobDispatcher;

class BillQueueIntegrationTest extends TestCase
{
    public function testDispatchAndReceiveBillDetectionJob(): void
    {
        $job = new BillDetectionJob(5, 'house', 3, 'floor', 'house/floor/20250101', 'https://example.com/manifest.json', ['agenda' => []]);
        $mapper = new BillDetectionJobPayloadMapper();
        $dispatcher = new JobDispatcher(new InMemoryQueue());

        $dispatcher->dispatch($mapper->toPayload($job));
        $messages = $dispatcher->receive();
        $this->assertCount(1, $messages);

        $restored = $mapper->fromPayload($messages[0]->payload);
        $this->assertSame($job->fileId, $restored->fileId);
        $this->assertSame($job->chamber, $restored->chamber);
        $this->assertSame($job->committeeId, $restored->committeeId);
        $this->assertSame($job->eventType, $restored->eventType);
        $this->assertSame($job->captureDirectory, $restored->captureDirectory);
    }
}
