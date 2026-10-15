<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use RuntimeException;

class ScreenshotFetcher
{
    private Client $http;
    private string $workingDir;

    /**
     * @param int[] $retryDelays seconds to wait before each retry of a transient
     *        failure; the number of entries is the number of retries
     */
    public function __construct(?Client $http = null, ?string $workingDir = null, private array $retryDelays = [2, 5])
    {
        $this->http = $http ?? new Client(['timeout' => 120]);
        $this->workingDir = $workingDir ?? sys_get_temp_dir();
    }

    public function fetch(string $url): string
    {
        if (str_starts_with($url, 'file://')) {
            $source = substr($url, 7);
            $dest = tempnam($this->workingDir, 'shot_') . '.jpg';
            if (!copy($source, $dest)) {
                throw new RuntimeException('Unable to copy local screenshot fixture.');
            }
            return $dest;
        }
        $normalizedUrl = $this->normalizeUrl($url);
        $dest = tempnam($this->workingDir, 'shot_') . '.jpg';

        // S3 (InternalError) and Cloudflare (502) occasionally fail a request for
        // a frame that exists. Retry those; a 404 or 403 is final.
        $attempt = 0;
        while (true) {
            try {
                $this->http->get($normalizedUrl, ['sink' => $dest]);
                return $dest;
            } catch (ServerException | ConnectException | ClientException $e) {
                $retryable = !$e instanceof ClientException || $e->getResponse()->getStatusCode() === 429;
                if (!$retryable || $attempt >= count($this->retryDelays)) {
                    @unlink($dest);
                    throw $e;
                }
                sleep((int) $this->retryDelays[$attempt]);
                $attempt++;
            }
        }
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
