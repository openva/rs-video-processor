<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Reads a reply from the OpenAI API. Send requests with 'http_errors' =>
 * false, so an error reply arrives here and a spent cap is recognised.
 */
final class OpenAIResponse
{
    /**
     * @return array<string,mixed> the decoded JSON
     * @throws OpenAIQuotaExceeded when the spending cap has been reached
     * @throws RuntimeException for any other error reply
     */
    public static function json(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $payload = json_decode((string) $response->getBody(), true);
        $error = is_array($payload) && is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $message = (string) ($error['message'] ?? '');

        if (
            $status === 429 && (in_array($error['code'] ?? null, OpenAIQuotaExceeded::CODES, true)
            || in_array($error['type'] ?? null, OpenAIQuotaExceeded::CODES, true))
        ) {
            throw new OpenAIQuotaExceeded($message !== '' ? $message : 'insufficient_quota');
        }
        if ($status >= 400) {
            throw new RuntimeException(sprintf('OpenAI returned HTTP %d: %s', $status, $message !== '' ? $message : substr((string) $response->getBody(), 0, 200)));
        }
        if (!is_array($payload)) {
            throw new RuntimeException('Unexpected response from OpenAI: not JSON.');
        }

        return $payload;
    }
}
