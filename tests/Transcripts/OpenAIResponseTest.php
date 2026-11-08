<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAIQuotaExceeded;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAIResponse;

class OpenAIResponseTest extends TestCase
{
    /** What OpenAI answers once the account's spending cap is reached. */
    public const QUOTA_REPLY = '{"error":{"message":"You exceeded your current quota, please check your plan and billing details.","type":"insufficient_quota","param":null,"code":"insufficient_quota"}}';

    public function testASpentCapMakesEveryJobWait(): void
    {
        try {
            OpenAIResponse::json(new Response(429, [], self::QUOTA_REPLY));
            $this->fail('Expected OpenAIQuotaExceeded.');
        } catch (OpenAIQuotaExceeded $e) {
            $this->assertTrue($e->everyJobWaits);
            $this->assertStringContainsString('You exceeded your current quota', $e->getMessage());
            $this->assertSame($e->resumesAt - time(), $e->retrySeconds, '', 2);
        }
    }

    public function testTheOlderHardLimitCodeCountsToo(): void
    {
        $this->expectException(OpenAIQuotaExceeded::class);
        OpenAIResponse::json(new Response(429, [], '{"error":{"message":"Billing hard limit has been reached","type":"invalid_request_error","code":"billing_hard_limit_reached"}}'));
    }

    /** A per-minute rate limit is not the spending cap. */
    public function testARateLimitIsAnOrdinaryFailure(): void
    {
        try {
            OpenAIResponse::json(new Response(429, [], '{"error":{"message":"Rate limit reached","type":"requests","code":"rate_limit_exceeded"}}'));
            $this->fail('Expected RuntimeException.');
        } catch (OpenAIQuotaExceeded $e) {
            $this->fail('A rate limit is not the spending cap.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 429: Rate limit reached', $e->getMessage());
        }
    }

    public function testAServerErrorIsAnOrdinaryFailure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI returned HTTP 500');
        OpenAIResponse::json(new Response(500, [], 'oops'));
    }

    public function testReturnsTheReply(): void
    {
        $this->assertSame(['segments' => []], OpenAIResponse::json(new Response(200, [], '{"segments":[]}')));
    }

    public function testResumesAtTheStartOfNextMonth(): void
    {
        $this->assertSame('2026-12-01 00:00', gmdate('Y-m-d H:i', OpenAIQuotaExceeded::resumesAt(gmmktime(15, 0, 0, 11, 8, 2026))));
        $this->assertSame('2027-01-01 00:00', gmdate('Y-m-d H:i', OpenAIQuotaExceeded::resumesAt(gmmktime(9, 0, 0, 12, 20, 2026))));
    }

    /** Close to the turn of the month, a late reset must not cost a whole month. */
    public function testNearTheResetItAsksAgainInHours(): void
    {
        $now = gmmktime(22, 0, 0, 11, 30, 2026);
        $this->assertSame($now + OpenAIQuotaExceeded::RETRY_NEAR_RESET_SECONDS, OpenAIQuotaExceeded::resumesAt($now));
        $now = gmmktime(1, 0, 0, 12, 1, 2026);
        $this->assertSame($now + OpenAIQuotaExceeded::RETRY_NEAR_RESET_SECONDS, OpenAIQuotaExceeded::resumesAt($now), 'Still refused just after the turn of the month.');
    }
}
