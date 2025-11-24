<?php

namespace RichmondSunlight\VideoProcessor\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadJob;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadJobPayloadMapper;
use RichmondSunlight\VideoProcessor\Queue\InMemoryQueue;
use RichmondSunlight\VideoProcessor\Queue\JobDispatcher;

class VideoDownloadQueueIntegrationTest extends TestCase
{
    public function testDispatchAndReceiveVideoDownloadJob(): void
    {
        $job = new VideoDownloadJob(7, 'house', null, '2025-02-01', 'https://example.com/video.mp4', ['duration' => 3600], 'Test');
        $mapper = new VideoDownloadJobPayloadMapper();
        $dispatcher = new JobDispatcher(new InMemoryQueue());

        $dispatcher->dispatch($mapper->toPayload($job));
        $messages = $dispatcher->receive();
        $this->assertCount(1, $messages);

        $restored = $mapper->fromPayload($messages[0]->payload);
        $this->assertSame($job->id, $restored->id);
        $this->assertSame($job->remoteUrl, $restored->remoteUrl);
        $this->assertSame($job->metadata, $restored->metadata);
    }
}
