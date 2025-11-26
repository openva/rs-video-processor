<?php

namespace RichmondSunlight\VideoProcessor\Scraper\Http;

/**
 * Minimal rate limiter shared between scraping sources.
 */
class RateLimitedHttpClient implements HttpClientInterface
{
    private float $lastRequestAt = 0.0;

    public function __construct(
        private HttpClientInterface $inner,
        private float $minIntervalSeconds = 1.0,
        private int $maxRetries = 3,
        private float $retryDelaySeconds = 2.0
    ) {
    }

    public function get(string $url): string
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            $attempt++;
            $now = microtime(true);
            $elapsed = $now - $this->lastRequestAt;
            if ($this->lastRequestAt !== 0.0 && $elapsed < $this->minIntervalSeconds) {
                usleep((int) (($this->minIntervalSeconds - $elapsed) * 1_000_000));
            }

            try {
                $body = $this->inner->get($url);
                $this->lastRequestAt = microtime(true);
                return $body;
            } catch (\RuntimeException $e) {
                $lastException = $e;
                if ($attempt >= $this->maxRetries) {
                    break;
                }
                usleep((int) ($this->retryDelaySeconds * 1_000_000));
            }
        }

        throw $lastException ?? new \RuntimeException('Unknown HTTP failure while fetching ' . $url);
    }
}
