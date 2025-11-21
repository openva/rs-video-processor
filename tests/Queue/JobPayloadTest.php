<?php

namespace RichmondSunlight\VideoProcessor\Tests\Queue;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Queue\JobPayload;

class JobPayloadTest extends TestCase
{
    public function testSerializesAndDeserializes(): void
    {
        $payload = new JobPayload('transcript', 42, ['priority' => 'high']);
        $array = $payload->toArray();

        $this->assertSame('transcript', $array['type']);
        $this->assertSame(42, $array['file_id']);
        $this->assertSame('high', $array['context']['priority']);

        $restored = JobPayload::fromArray($array);
        $this->assertSame($payload->type, $restored->type);
        $this->assertSame($payload->fileId, $restored->fileId);
        $this->assertSame($payload->context, $restored->context);
    }

    public function testThrowsOnMalformedData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JobPayload::fromArray(['type' => 'x']);
    }
}
