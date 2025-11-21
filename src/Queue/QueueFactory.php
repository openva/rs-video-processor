<?php

namespace RichmondSunlight\VideoProcessor\Queue;

use Aws\Sqs\SqsClient;
use Log;

class QueueFactory
{
    public function __construct(private ?Log $logger = null)
    {
    }

    public function build(?string $queueUrl, array $config = []): QueueInterface
    {
        if ($queueUrl && $this->awsCredentialsAvailable($config)) {
            $client = new SqsClient($this->normalizeConfig($config));
            return new SqsQueue($client, $queueUrl, $this->logger);
        }

        $this->logger?->put('Falling back to in-memory queue (SQS unavailable).', 4);
        return new InMemoryQueue();
    }

    private function awsCredentialsAvailable(array $config): bool
    {
        $creds = $config['credentials'] ?? null;
        if (is_array($creds) && isset($creds['key'], $creds['secret'])) {
            return true;
        }
        return getenv('AWS_ACCESS_KEY_ID') && getenv('AWS_SECRET_ACCESS_KEY');
    }

    private function normalizeConfig(array $config): array
    {
        if (!isset($config['region'])) {
            $config['region'] = getenv('AWS_REGION') ?: 'us-east-1';
        }
        if (!isset($config['version'])) {
            $config['version'] = '2012-11-05';
        }
        if (!isset($config['credentials']) && getenv('AWS_ACCESS_KEY_ID')) {
            $config['credentials'] = [
                'key' => getenv('AWS_ACCESS_KEY_ID'),
                'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
                'token' => getenv('AWS_SESSION_TOKEN') ?: null,
            ];
        }
        return $config;
    }
}
