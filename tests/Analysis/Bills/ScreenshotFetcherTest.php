<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Bills;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;

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
        } catch (ClientException $e) {
            $this->assertSame(404, $e->getResponse()->getStatusCode());
        }
        $this->assertSame(1, $mock->count(), 'Only one request was made.');
    }
}
