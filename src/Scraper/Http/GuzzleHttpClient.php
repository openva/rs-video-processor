<?php

namespace RichmondSunlight\VideoProcessor\Scraper\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Thin wrapper around Guzzle so we can swap implementations during tests.
 */
class GuzzleHttpClient implements HttpClientInterface
{
    public function __construct(
        private ClientInterface $client
    ) {
    }

    public function get(string $url): string
    {
        try {
            $response = $this->client->request('GET', $url);
        } catch (GuzzleException $e) {
            throw new \RuntimeException(sprintf('Failed to GET %s: %s', $url, $e->getMessage()), 0, $e);
        }

        return (string) $response->getBody();
    }
}
