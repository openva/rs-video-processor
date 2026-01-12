<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use GuzzleHttp\Client;
use RuntimeException;

class ScreenshotManifestLoader
{
    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 120]);
    }

    /**
     * @return array<int,array{timestamp:int,full:string,thumb:?string}>
     */
    public function load(string $manifestUrl): array
    {
        if (str_starts_with($manifestUrl, 'file://')) {
            $contents = file_get_contents(substr($manifestUrl, 7));
        } else {
            $url = $this->normalizeUrl($manifestUrl);
            $response = $this->http->get($url);
            if ($response->getStatusCode() >= 400) {
                throw new RuntimeException('Unable to download screenshot manifest.');
            }
            $contents = (string) $response->getBody();
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            throw new RuntimeException('Screenshot manifest is malformed.');
        }
        $manifest = [];
        foreach ($data as $item) {
            $manifest[] = [
                'timestamp' => (int) ($item['timestamp'] ?? 0),
                'full' => (string) ($item['full'] ?? ''),
                'thumb' => $item['thumb'] ?? null,
            ];
        }
        return $manifest;
    }

    private function normalizeUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        // Strip leading /video from legacy paths
        $path = preg_replace('#^/video/#', '/', $url);
        // Ensure leading slash
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        return 'https://video.richmondsunlight.com' . $path;
    }
}
