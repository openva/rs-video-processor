<?php

namespace RichmondSunlight\VideoProcessor\Tests\Queue;

use Log;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Queue\InMemoryQueue;
use RichmondSunlight\VideoProcessor\Queue\JobDispatcher;
use RichmondSunlight\VideoProcessor\Queue\JobPayload;

class JobDispatcherTest extends TestCase
{
    public function testDispatchesThroughUnderlyingQueue(): void
    {
        $queue = new InMemoryQueue();
        $logger = new class extends Log {
            public array $messages = [];
            public function put($message, $level)
            {
                $this->messages[] = [$message, $level];
                return true;
            }
        };

        $dispatcher = new JobDispatcher($queue, $logger);
        $dispatcher->dispatch(new JobPayload('screenshots', 5));

        $received = $dispatcher->receive();
        $this->assertSame('screenshots', $received[0]->payload->type);
        $dispatcher->acknowledge($received[0]);
    }

    public function testFactoryUsesFallbackWhenEnvMissing(): void
    {
        $dispatcher = JobDispatcher::fromEnvironment();
        $this->assertNotNull($dispatcher->getQueue());
    }
}
