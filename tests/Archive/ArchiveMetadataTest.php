<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\ArchiveMetadata;
use RichmondSunlight\VideoProcessor\Jobs\NotReadyYet;

class ArchiveMetadataTest extends TestCase
{
    /** As the archive answered on 2026-11-05. */
    private const OFFLINE_PAGE = '<html><head><meta charset="UTF-8"><title>Internet Archive: Temporarily Offline</title><style>p{font-size:24px;}</style></head><body><p>Back soon.</p></body></html>';

    /**
     * A client that would throw on a 5xx, as InternetArchiveUploader's did
     * ("Server error: GET … resulted in a 503"), to show the reply is read anyway.
     */
    private static function client(Response|\Throwable $reply): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler([$reply])), 'http_errors' => true]);
    }

    private static function assertArchiveUnavailable(Response|\Throwable $reply, string $inMessage): void
    {
        try {
            ArchiveMetadata::fetch(self::client($reply), 'va-senate-20180309-18408');
            self::fail('Expected NotReadyYet.');
        } catch (NotReadyYet $e) {
            self::assertTrue($e->everyJobWaits, 'Every other job would get the same answer.');
            self::assertSame(ArchiveMetadata::OFFLINE_RECHECK_SECONDS, $e->retrySeconds);
            self::assertStringContainsString('va-senate-20180309-18408', $e->getMessage());
            self::assertStringContainsString($inMessage, $e->getMessage());
        }
    }

    public function testTheTemporarilyOfflinePageMakesEveryJobWait(): void
    {
        self::assertArchiveUnavailable(new Response(503, ['Content-Type' => 'text/html'], self::OFFLINE_PAGE), 'HTTP 503: Internet Archive: Temporarily Offline');
    }

    public function testTooManyRequestsMakesEveryJobWait(): void
    {
        self::assertArchiveUnavailable(new Response(429), 'HTTP 429');
    }

    public function testNoConnectionMakesEveryJobWait(): void
    {
        self::assertArchiveUnavailable(
            new ConnectException('cURL error 7: Failed to connect', new Request('GET', 'https://archive.org/metadata/x')),
            'no reply'
        );
    }

    public function testTheOfflinePageServedAsSuccessMakesEveryJobWait(): void
    {
        self::assertArchiveUnavailable(new Response(200, [], self::OFFLINE_PAGE), 'not JSON: Internet Archive: Temporarily Offline');
    }

    public function testReturnsTheItem(): void
    {
        $item = ArchiveMetadata::fetch(self::client(new Response(200, [], '{"files":[{"name":"a.mp4"}]}')), 'x');

        $this->assertSame([['name' => 'a.mp4']], $item['files']);
    }

    /** The API answers an unknown identifier with an empty object. */
    public function testAnUnknownItemIsEmpty(): void
    {
        $this->assertSame([], ArchiveMetadata::fetch(self::client(new Response(200, [], '{}')), 'x'));
    }

    public function testAClientErrorIsNull(): void
    {
        $this->assertNull(ArchiveMetadata::fetch(self::client(new Response(404)), 'x'));
    }
}
