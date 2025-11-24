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
        private float $minIntervalSeconds = 1.0
    ) {
    }

    public function get(string $url): string
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastRequestAt;
        if ($this->lastRequestAt !== 0.0 && $elapsed < $this->minIntervalSeconds) {
            usleep((int) (($this->minIntervalSeconds - $elapsed) * 1_000_000));
        }

        $body = $this->inner->get($url);
        $this->lastRequestAt = microtime(true);

        return $body;
    }
}
