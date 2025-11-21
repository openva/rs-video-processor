<?php

namespace RichmondSunlight\VideoProcessor\Tests\Support;

use RichmondSunlight\VideoProcessor\Scraper\Http\HttpClientInterface;
use RuntimeException;

class FakeHttpClient implements HttpClientInterface
{
    /**
     * @param array<string,string> $responses keyed by substring to match within the URL.
     */
    public function __construct(
        private array $responses
    ) {
    }

    public function get(string $url): string
    {
        foreach ($this->responses as $needle => $response) {
            if (str_contains($url, $needle)) {
                return $response;
            }
        }

        throw new RuntimeException(sprintf('No fake response registered for %s', $url));
    }
}
