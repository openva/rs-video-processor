<?php

namespace RichmondSunlight\VideoProcessor\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJob;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobPayloadMapper;
use RichmondSunlight\VideoProcessor\Queue\InMemoryQueue;
use RichmondSunlight\VideoProcessor\Queue\JobDispatcher;

class ArchiveQueueIntegrationTest extends TestCase
{
    public function testDispatchAndReceiveArchiveJob(): void
    {
        $job = new ArchiveJob(3, 'senate', 'Floor Session', '2025-03-01', 'https://video.richmondsunlight.com/senate/floor/20250301.mp4', 'WEBVTT', null, 'captures/20250301', '{}');
        $mapper = new ArchiveJobPayloadMapper();
        $dispatcher = new JobDispatcher(new InMemoryQueue());

        $dispatcher->dispatch($mapper->toPayload($job));
        $messages = $dispatcher->receive();
        $this->assertCount(1, $messages);

        $restored = $mapper->fromPayload($messages[0]->payload);
        $this->assertSame($job->fileId, $restored->fileId);
        $this->assertSame($job->title, $restored->title);
        $this->assertSame($job->s3Path, $restored->s3Path);
        $this->assertSame($job->captureDirectory, $restored->captureDirectory);
    }
}
