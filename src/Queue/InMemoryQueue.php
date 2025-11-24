<?php

namespace RichmondSunlight\VideoProcessor\Queue;

class InMemoryQueue implements QueueInterface
{
    /**
     * @var JobPayload[]
     */
    private array $queue = [];

    /**
     * @var array<string,ReceivedJob>
     */
    private array $inFlight = [];

    public function send(JobPayload $payload): void
    {
        $this->queue[] = $payload;
    }

    public function receive(int $maxMessages = 1, int $waitTimeSeconds = 0): array
    {
        $messages = [];
        for ($i = 0; $i < $maxMessages; $i++) {
            if (empty($this->queue)) {
                break;
            }
            $payload = array_shift($this->queue);
            if (!$payload) {
                break;
            }
            $receipt = uniqid('receipt_', true);
            $job = new ReceivedJob($receipt, $payload);
            $this->inFlight[$receipt] = $job;
            $messages[] = $job;
        }

        return $messages;
    }

    public function delete(ReceivedJob $job): void
    {
        unset($this->inFlight[$job->receiptHandle]);
    }
}
