<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Bills;

use Aws\CommandInterface;
use Aws\MockHandler as AwsMockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotMissingException;

class ScreenshotFetcherTest extends TestCase
{
    private function fetcher(MockHandler $mock): ScreenshotFetcher
    {
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        // No real waiting between attempts in tests.
        return new ScreenshotFetcher($client, null, [0, 0]);
    }

    /**
     * S3 and Cloudflare occasionally answer 500 or 502 for a frame that exists.
     * One such answer must not lose the frame, because a single lost frame can
     * send a whole video back for another OCR pass.
     */
    public function testRetriesTransientServerErrors(): void
    {
        $mock = new MockHandler([
            new Response(500, [], '<Error><Code>InternalError</Code></Error>'),
            new Response(502, [], '502 Bad Gateway'),
            new Response(200, [], 'JPEGDATA'),
        ]);

        $path = $this->fetcher($mock)->fetch('https://video.richmondsunlight.com/house/floor/20260204-18591/00001657.jpg');

        $this->assertSame('JPEGDATA', file_get_contents($path));
        $this->assertSame(0, $mock->count(), 'All three responses were used.');
        @unlink($path);
    }

    public function testRetriesTooManyRequests(): void
    {
        $mock = new MockHandler([new Response(429), new Response(200, [], 'JPEGDATA')]);

        $path = $this->fetcher($mock)->fetch('https://video.richmondsunlight.com/x/00000001.jpg');

        $this->assertSame('JPEGDATA', file_get_contents($path));
        @unlink($path);
    }

    public function testGivesUpAfterTheLastRetry(): void
    {
        $mock = new MockHandler([new Response(500), new Response(500), new Response(500)]);

        $this->expectException(ServerException::class);
        $this->fetcher($mock)->fetch('https://video.richmondsunlight.com/x/00000001.jpg');
    }

    /** A missing frame will not appear by asking again. */
    public function testDoesNotRetryNotFound(): void
    {
        $mock = new MockHandler([new Response(404), new Response(200, [], 'never used')]);

        try {
            $this->fetcher($mock)->fetch('https://video.richmondsunlight.com/x/00000001.jpg');
            $this->fail('A 404 must fail at once.');
        } catch (ScreenshotMissingException $e) {
            // Its own kind, so a stage can tell it from an error worth retrying.
            $this->assertInstanceOf(ClientException::class, $e->getPrevious());
            $this->assertSame(404, $e->getPrevious()->getResponse()->getStatusCode());
        }
        $this->assertSame(1, $mock->count(), 'Only one request was made.');
    }

    /**
     * An S3 client whose answers come from $answers, keyed by object key: a
     * string is the object's contents, anything else fails the request.
     *
     * @param string[] $requested filled with each key asked for
     */
    private function s3(array $answers, array &$requested): S3Client
    {
        $mock = new AwsMockHandler();
        $handler = function (CommandInterface $command) use ($answers, &$requested) {
            $requested[] = $command['Bucket'] . ':' . $command['Key'];
            $body = $answers[$command['Key']] ?? null;
            if (!is_string($body)) {
                return new S3Exception('NoSuchKey', $command);
            }
            return new Result(['Body' => Utils::streamFor($body)]);
        };
        // One answer per possible request; the handler decides what it is.
        for ($i = 0; $i < 50; $i++) {
            $mock->append($handler);
        }

        return new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mock,
        ]);
    }

    /**
     * Frames come straight from the bucket rather than through Cloudflare,
     * which is where the 500s and 502s came from. Keys keep doubled slashes.
     */
    public function testFetchesFromS3WhenGivenAClient(): void
    {
        $requested = [];
        $s3 = $this->s3(['house/floor/20260223//00000001.jpg' => 'JPEGDATA'], $requested);
        $http = new MockHandler([]);

        $fetcher = new ScreenshotFetcher(new Client(['handler' => HandlerStack::create($http)]), null, [0, 0], $s3);
        $path = $fetcher->fetch('https://video.richmondsunlight.com/house/floor/20260223//00000001.jpg');

        $this->assertSame('JPEGDATA', file_get_contents($path));
        $this->assertSame(['video.richmondsunlight.com:house/floor/20260223//00000001.jpg'], $requested);
        @unlink($path);
    }

    public function testFallsBackToHttpWhenS3Fails(): void
    {
        $requested = [];
        $s3 = $this->s3([], $requested);
        $http = new MockHandler([new Response(200, [], 'FROMHTTP')]);

        $fetcher = new ScreenshotFetcher(new Client(['handler' => HandlerStack::create($http)]), null, [0, 0], $s3);
        $path = $fetcher->fetch('https://video.richmondsunlight.com/x/00000001.jpg');

        $this->assertSame('FROMHTTP', file_get_contents($path));
        @unlink($path);
    }

    public function testPrefetchedFramesNeedNoFurtherRequests(): void
    {
        $requested = [];
        $s3 = $this->s3(['x/00000001.jpg' => 'ONE', 'x/00000002.jpg' => 'TWO', 'x/00000003.jpg' => 'THREE'], $requested);
        $fetcher = new ScreenshotFetcher(new Client(['handler' => HandlerStack::create(new MockHandler([]))]), null, [0, 0], $s3);

        $urls = [
            'https://video.richmondsunlight.com/x/00000001.jpg',
            'https://video.richmondsunlight.com/x/00000002.jpg',
            'https://video.richmondsunlight.com/x/00000003.jpg',
        ];
        $fetcher->prefetch($urls);
        $this->assertCount(3, $requested);

        $contents = [];
        foreach ($urls as $url) {
            $path = $fetcher->fetch($url);
            $contents[] = file_get_contents($path);
            @unlink($path);
        }
        $this->assertSame(['ONE', 'TWO', 'THREE'], $contents);
        $this->assertCount(3, $requested, 'fetch() used the prefetched files.');
    }

    /**
     * A frame that could not be prefetched is fetched again on its own, so a
     * real error is reported exactly as it was before prefetching.
     */
    public function testAFailedPrefetchIsFetchedAgainOnItsOwn(): void
    {
        $requested = [];
        $s3 = $this->s3(['x/00000001.jpg' => 'ONE'], $requested);
        $http = new MockHandler([new Response(200, [], 'TWO')]);
        $fetcher = new ScreenshotFetcher(new Client(['handler' => HandlerStack::create($http)]), null, [0, 0], $s3);

        $fetcher->prefetch(['https://video.richmondsunlight.com/x/00000001.jpg', 'https://video.richmondsunlight.com/x/00000002.jpg']);
        $path = $fetcher->fetch('https://video.richmondsunlight.com/x/00000002.jpg');

        $this->assertSame('TWO', file_get_contents($path));
        @unlink($path);
    }

    public function testPrefetchesOverHttpWithoutAnS3Client(): void
    {
        $http = new MockHandler([new Response(200, [], 'ONE'), new Response(200, [], 'TWO')]);
        $fetcher = $this->fetcher($http);

        $fetcher->prefetch(['https://video.richmondsunlight.com/x/00000001.jpg', 'https://video.richmondsunlight.com/x/00000002.jpg']);
        $this->assertSame(0, $http->count(), 'Both frames were downloaded by prefetch().');

        $first = $fetcher->fetch('https://video.richmondsunlight.com/x/00000001.jpg');
        $second = $fetcher->fetch('https://video.richmondsunlight.com/x/00000002.jpg');
        $this->assertSame(['ONE', 'TWO'], [file_get_contents($first), file_get_contents($second)]);
        @unlink($first);
        @unlink($second);
    }

    public function testUnusedPrefetchedFramesAreDeleted(): void
    {
        $requested = [];
        $s3 = $this->s3(['x/00000001.jpg' => 'ONE'], $requested);
        $dir = sys_get_temp_dir() . '/prefetch-' . uniqid();
        mkdir($dir);
        $fetcher = new ScreenshotFetcher(null, $dir, [0, 0], $s3);

        $fetcher->prefetch(['https://video.richmondsunlight.com/x/00000001.jpg']);
        $this->assertNotEmpty(glob($dir . '/*.jpg'));

        $fetcher->discardPrefetched();
        $this->assertEmpty(glob($dir . '/*'));
        rmdir($dir);
    }
}
