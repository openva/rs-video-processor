<?php

namespace RichmondSunlight\VideoProcessor\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Queue\InMemoryQueue;
use RichmondSunlight\VideoProcessor\Queue\JobDispatcher;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJob;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJobPayloadMapper;

class ScreenshotQueueIntegrationTest extends TestCase
{
    public function testDispatchAndReceiveScreenshotJob(): void
    {
        $job = new ScreenshotJob(1, 'senate', 2, '2025-01-01', 'https://example.com/video.mp4', null, 'Test');
        $mapper = new ScreenshotJobPayloadMapper();
        $dispatcher = new JobDispatcher(new InMemoryQueue());

        $dispatcher->dispatch($mapper->toPayload($job));
        $messages = $dispatcher->receive();
        $this->assertCount(1, $messages);

        $restored = $mapper->fromPayload($messages[0]->payload);
        $this->assertSame($job->id, $restored->id);
        $this->assertSame($job->chamber, $restored->chamber);
        $this->assertSame($job->committeeId, $restored->committeeId);
        $this->assertSame($job->date, $restored->date);
        $this->assertSame($job->videoPath, $restored->videoPath);
    }
}
