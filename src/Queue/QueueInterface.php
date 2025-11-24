<?php

namespace RichmondSunlight\VideoProcessor\Queue;

interface QueueInterface
{
    public function send(JobPayload $payload): void;

    /**
     * @return ReceivedJob[]
     */
    public function receive(int $maxMessages = 1, int $waitTimeSeconds = 0): array;

    public function delete(ReceivedJob $job): void;
}
