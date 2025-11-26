<?php

namespace RichmondSunlight\VideoProcessor\Queue;

use Log;

class JobDispatcher
{
    public function __construct(private QueueInterface $queue, private ?Log $logger = null)
    {
    }

    public static function fromEnvironment(?Log $logger = null): self
    {
        $queueUrl = getenv('VIDEO_SQS_URL') ?: (defined('VIDEO_SQS_URL') ? VIDEO_SQS_URL : null);
        $factory = new QueueFactory($logger);
        $config = [];
        if (defined('AWS_REGION') && AWS_REGION) {
            $config['region'] = AWS_REGION;
        }
        $queue = $factory->build($queueUrl, $config);
        return new self($queue, $logger);
    }

    public function dispatch(JobPayload $payload): void
    {
        $this->queue->send($payload);
        $this->logger?->put('Queued job ' . $payload->type . ' for file #' . $payload->fileId, 4);
    }

    /**
     * @return ReceivedJob[]
     */
    public function receive(int $limit = 1, int $waitTimeSeconds = 0): array
    {
        return $this->queue->receive($limit, $waitTimeSeconds);
    }

    public function acknowledge(ReceivedJob $job): void
    {
        $this->queue->delete($job);
    }

    public function getQueue(): QueueInterface
    {
        return $this->queue;
    }

    public function usesInMemoryQueue(): bool
    {
        return $this->queue instanceof InMemoryQueue;
    }
}
