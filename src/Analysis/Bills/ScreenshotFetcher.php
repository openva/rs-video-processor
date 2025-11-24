<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use GuzzleHttp\Client;
use RuntimeException;

class ScreenshotFetcher
{
    private Client $http;
    private string $workingDir;

    public function __construct(?Client $http = null, ?string $workingDir = null)
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
        $dest = tempnam($this->workingDir, 'shot_') . '.jpg';
        $response = $this->http->get($url, ['sink' => $dest]);
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Unable to download screenshot.');
        }
        return $dest;
    }
}
