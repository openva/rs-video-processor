<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAIQuotaExceeded;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAITranscriber;

class OpenAITranscriberTest extends TestCase
{
    public function testAsksForWordTimestamps(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['text' => 'Good morning', 'words' => [
                ['word' => 'Good', 'start' => 0.5, 'end' => 0.8],
                ['word' => 'morning', 'start' => 0.8, 'end' => 1.3],
            ]])),
        ]));
        $stack->push(Middleware::history($history));
        $audio = tempnam(sys_get_temp_dir(), 'audio_');

        $words = (new OpenAITranscriber(new Client(['handler' => $stack]), 'key'))->transcribeWords($audio);
        @unlink($audio);

        $this->assertSame([
            ['word' => 'Good', 'start' => 0.5, 'end' => 0.8],
            ['word' => 'morning', 'start' => 0.8, 'end' => 1.3],
        ], $words);
        $body = (string) $history[0]['request']->getBody();
        $this->assertMatchesRegularExpression('/name="timestamp_granularities\[\]"\s+word/', $body);
        $this->assertMatchesRegularExpression('/name="response_format"\s+verbose_json/', $body);
    }

    /** Recognised even by a client that throws on error replies, as the pipeline's does. */
    public function testASpentCapIsRecognised(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(429, [], OpenAIResponseTest::QUOTA_REPLY),
            new Response(429, [], OpenAIResponseTest::QUOTA_REPLY),
        ])), 'http_errors' => true]);
        $transcriber = new OpenAITranscriber($client, 'key');
        $audio = tempnam(sys_get_temp_dir(), 'audio_');

        try {
            foreach (['transcribe', 'transcribeWords'] as $method) {
                try {
                    $transcriber->$method($audio);
                    $this->fail($method . ' should have thrown OpenAIQuotaExceeded.');
                } catch (OpenAIQuotaExceeded $e) {
                    $this->assertTrue($e->everyJobWaits);
                }
            }
        } finally {
            @unlink($audio);
        }
    }
}
