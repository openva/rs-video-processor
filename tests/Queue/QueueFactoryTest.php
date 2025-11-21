<?php

namespace RichmondSunlight\VideoProcessor\Tests\Queue;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Queue\InMemoryQueue;
use RichmondSunlight\VideoProcessor\Queue\QueueFactory;
use RichmondSunlight\VideoProcessor\Queue\QueueInterface;

class QueueFactoryTest extends TestCase
{
    public function testFallbacksToInMemoryQueueWhenUrlMissing(): void
    {
        $factory = new QueueFactory();
        $queue = $factory->build(null);
        $this->assertInstanceOf(InMemoryQueue::class, $queue);
    }

    public function testReturnsQueueInterface(): void
    {
        $factory = new QueueFactory();
        $queue = $factory->build(null);
        $this->assertInstanceOf(QueueInterface::class, $queue);
    }
}
