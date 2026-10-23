<?php

namespace RichmondSunlight\VideoProcessor\Tests\Support;

use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use RichmondSunlight\VideoProcessor\Fetcher\BucketReader;

/**
 * A BucketReader whose S3 client answers from a queue of HTTP responses, so
 * the SDK's own request building and SaveAs streaming are exercised. Each
 * request S3 is sent is recorded in $requests.
 */
class FakeBucket
{
    public MockHandler $responses;

    /** @var RequestInterface[] */
    public array $requests = [];

    public BucketReader $reader;

    public function __construct(array $responses)
    {
        $this->responses = new MockHandler($responses);
        $stack = HandlerStack::create($this->responses);
        $history = [];
        $stack->push(Middleware::history($history));
        $this->requests = &$history;

        $s3 = new S3Client([
            'region' => 'us-east-1',
            'version' => '2006-03-01',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'retries' => 0,
            'http_handler' => new GuzzleHandler(new Client(['handler' => $stack])),
        ]);
        $this->reader = new BucketReader($s3);
    }

    /** The path of each request S3 was sent: the bucket, which has dots in its name, and the key. */
    public function requestedPaths(): array
    {
        return array_map(static fn(array $entry): string => $entry['request']->getUri()->getPath(), $this->requests);
    }
}
