<?php

namespace RichmondSunlight\VideoProcessor\Queue;

use Aws\Sqs\SqsClient;
use Log;
use RuntimeException;

class SqsQueue implements QueueInterface
{
    public function __construct(
        private SqsClient $client,
        private string $queueUrl,
        private ?Log $logger = null
    ) {
    }

    public function send(JobPayload $payload): void
    {
        $body = json_encode($payload->toArray(), JSON_THROW_ON_ERROR);
        $params = [
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => $body,
        ];
        if ($this->isFifoQueue()) {
            $params['MessageGroupId'] = $payload->type;
            $params['MessageDeduplicationId'] = md5($body);
        }
        $this->client->sendMessage($params);
    }

    public function receive(int $maxMessages = 1, int $waitTimeSeconds = 0): array
    {
        $result = $this->client->receiveMessage([
            'QueueUrl' => $this->queueUrl,
            'MaxNumberOfMessages' => max(1, min(10, $maxMessages)),
            'WaitTimeSeconds' => max(0, min(20, $waitTimeSeconds)),
        ]);

        $messages = [];
        $batch = $result->get('Messages') ?? [];
        foreach ($batch as $item) {
            try {
                $payload = JobPayload::fromArray(json_decode($item['Body'] ?? '', true, 512, JSON_THROW_ON_ERROR));
                $messages[] = new ReceivedJob($item['ReceiptHandle'], $payload);
            } catch (\Throwable $e) {
                $this->logger?->put('Dropping malformed SQS job: ' . $e->getMessage(), 5);
                $this->deleteByHandle($item['ReceiptHandle'] ?? '');
            }
        }

        return $messages;
    }

    public function delete(ReceivedJob $job): void
    {
        $this->deleteByHandle($job->receiptHandle);
    }

    private function deleteByHandle(string $receiptHandle): void
    {
        if ($receiptHandle === '') {
            throw new RuntimeException('Missing receipt handle for delete.');
        }
        $this->client->deleteMessage([
            'QueueUrl' => $this->queueUrl,
            'ReceiptHandle' => $receiptHandle,
        ]);
    }

    private function isFifoQueue(): bool
    {
        return str_ends_with($this->queueUrl, '.fifo');
    }
}
