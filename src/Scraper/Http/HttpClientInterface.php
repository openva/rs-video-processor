<?php

namespace RichmondSunlight\VideoProcessor\Scraper\Http;

interface HttpClientInterface
{
    /**
     * Retrieve the body of a GET request.
     *
     * @throws \RuntimeException when the request fails.
     */
    public function get(string $url): string;
}
