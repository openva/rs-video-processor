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

        $this->logger?->put('Falling back to in-memory queue (SQS unavailable).', 2);
        return new InMemoryQueue();
    }

    private function awsCredentialsAvailable(array $config): bool
    {
        $creds = $config['credentials'] ?? null;
        if (is_array($creds) && isset($creds['key'], $creds['secret'])) {
            return true;
        }
        return ($this->getKey() !== null) && ($this->getSecret() !== null);
    }

    private function normalizeConfig(array $config): array
    {
        if (!isset($config['region'])) {
            $config['region'] = getenv('AWS_REGION') ?: (defined('AWS_REGION') ? AWS_REGION : 'us-east-1');
        }
        if (!isset($config['version'])) {
            $config['version'] = '2012-11-05';
        }
        if (!isset($config['credentials'])) {
            $key = $this->getKey();
            $secret = $this->getSecret();
            if ($key !== null && $secret !== null) {
                $config['credentials'] = [
                    'key' => $key,
                    'secret' => $secret,
                    'token' => getenv('AWS_SESSION_TOKEN') ?: null,
                ];
            }
        }
        return $config;
    }

    private function getKey(): ?string
    {
        return getenv('AWS_ACCESS_KEY')
            ?: (defined('AWS_ACCESS_KEY') ? AWS_ACCESS_KEY : null);
    }

    private function getSecret(): ?string
    {
        return getenv('AWS_SECRET_KEY')
            ?: (defined('AWS_SECRET_KEY') ? AWS_SECRET_KEY : null);
    }
}
