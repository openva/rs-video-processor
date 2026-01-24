<?php

namespace RichmondSunlight\VideoProcessor\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJob;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJobPayloadMapper;
use RichmondSunlight\VideoProcessor\Queue\InMemoryQueue;
use RichmondSunlight\VideoProcessor\Queue\JobDispatcher;

class SpeakerQueueIntegrationTest extends TestCase
{
    public function testDispatchAndReceiveSpeakerJob(): void
    {
        // Use realistic metadata structure with speakers data
        $metadata = [
            'speakers' => [
                ['name' => 'John Doe', 'start_time' => '00:00:00'],
                ['name' => 'Jane Smith', 'start_time' => '00:05:30'],
            ],
        ];
        $job = new SpeakerJob(9, 'senate', 'https://example.com/video.mp4', $metadata);
        $mapper = new SpeakerJobPayloadMapper();
        $dispatcher = new JobDispatcher(new InMemoryQueue());

        $dispatcher->dispatch($mapper->toPayload($job));
        $messages = $dispatcher->receive();
        $this->assertCount(1, $messages);

        $restored = $mapper->fromPayload($messages[0]->payload);
        $this->assertSame($job->fileId, $restored->fileId);
        $this->assertSame($job->chamber, $restored->chamber);
        $this->assertSame($job->videoUrl, $restored->videoUrl);
        // Metadata should contain speakers data (only field that's preserved in SQS payload)
        $this->assertSame($metadata, $restored->metadata);
    }
}
