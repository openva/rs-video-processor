<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use Aws\CommandPool;
use Aws\ResultInterface;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use RuntimeException;

class ScreenshotFetcher
{
    /** How many frames prefetch() downloads at once. */
    public const PREFETCH_CONCURRENCY = 25;

    private const PUBLIC_HOST = 'video.richmondsunlight.com';

    private Client $http;
    private string $workingDir;

    /** @var array<string,string> URL => local file, downloaded by prefetch() and not yet handed out */
    private array $prefetched = [];

    /**
     * @param int[] $retryDelays seconds to wait before each retry of a transient
     *        HTTP failure; the number of entries is the number of retries
     * @param S3Client|null $s3 read frames straight from the bucket instead of
     *        over HTTPS through Cloudflare, which is slower and occasionally
     *        answers 500 or 502. The SDK retries S3's own transient errors.
     */
    public function __construct(
        ?Client $http = null,
        ?string $workingDir = null,
        private array $retryDelays = [2, 5],
        private ?S3Client $s3 = null,
        private string $bucket = self::PUBLIC_HOST
    ) {
        $this->http = $http ?? new Client(['timeout' => 120]);
        $this->workingDir = $workingDir ?? sys_get_temp_dir();
    }

    public function __destruct()
    {
        $this->discardPrefetched();
    }

    /**
     * Download a batch of frames at once, so fetch() can hand them out without
     * waiting. A frame that fails here is simply not cached: fetch() then
     * downloads it on its own and reports any error as it always has.
     *
     * @param string[] $urls
     */
    public function prefetch(array $urls): void
    {
        $wanted = [];
        foreach (array_unique($urls) as $url) {
            if ($url !== '' && !isset($this->prefetched[$url]) && !str_starts_with($url, 'file://')) {
                $wanted[] = $url;
            }
        }
        if (empty($wanted)) {
            return;
        }

        if ($this->s3 !== null) {
            $this->prefetchFromS3($wanted);
        } else {
            $this->prefetchOverHttp($wanted);
        }
    }

    /**
     * Delete prefetched frames nobody fetched, e.g. after a job stops early.
     */
    public function discardPrefetched(): void
    {
        foreach ($this->prefetched as $path) {
            @unlink($path);
        }
        $this->prefetched = [];
    }

    public function fetch(string $url): string
    {
        if (isset($this->prefetched[$url])) {
            $path = $this->prefetched[$url];
            unset($this->prefetched[$url]);
            return $path;
        }

        if (str_starts_with($url, 'file://')) {
            $source = substr($url, 7);
            $dest = $this->tempPath();
            if (!copy($source, $dest)) {
                throw new RuntimeException('Unable to copy local screenshot fixture.');
            }
            return $dest;
        }

        $key = $this->s3 !== null ? $this->keyFor($url) : null;
        if ($key !== null) {
            $dest = $this->tempPath();
            try {
                $this->save($this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key]), $dest);
                return $dest;
            } catch (\Throwable) {
                // Fall through to HTTPS, which reports a missing frame plainly.
                @unlink($dest);
            }
        }

        return $this->fetchOverHttp($this->normalizeUrl($url));
    }

    /**
     * @param string[] $urls
     */
    private function prefetchFromS3(array $urls): void
    {
        $keys = [];
        foreach ($urls as $url) {
            $key = $this->keyFor($url);
            if ($key !== null) {
                $keys[$url] = $key;
            }
        }
        // CommandPool calls back with each command's position, not its key.
        $byPosition = array_keys($keys);
        $commands = (function () use ($keys) {
            foreach ($keys as $key) {
                yield $this->s3->getCommand('GetObject', ['Bucket' => $this->bucket, 'Key' => $key]);
            }
        })();

        $pool = new CommandPool($this->s3, $commands, [
            'concurrency' => self::PREFETCH_CONCURRENCY,
            'fulfilled' => function (ResultInterface $result, int $position) use ($byPosition): void {
                $url = $byPosition[$position];
                $dest = $this->tempPath();
                try {
                    $this->save($result, $dest);
                    $this->prefetched[$url] = $dest;
                } catch (\Throwable) {
                    @unlink($dest);
                }
            },
            'rejected' => function (): void {
                // Left for fetch() to try again on its own.
            },
        ]);
        $pool->promise()->wait();
    }

    /**
     * @param string[] $urls
     */
    private function prefetchOverHttp(array $urls): void
    {
        $destinations = [];
        $requests = function () use ($urls, &$destinations) {
            foreach ($urls as $url) {
                $destinations[$url] = $this->tempPath();
                yield $url => fn() => $this->http->getAsync($this->normalizeUrl($url), ['sink' => $destinations[$url]]);
            }
        };

        $pool = new Pool($this->http, $requests(), [
            'concurrency' => self::PREFETCH_CONCURRENCY,
            'fulfilled' => function ($response, string $url) use (&$destinations): void {
                $this->prefetched[$url] = $destinations[$url];
            },
            'rejected' => function ($reason, string $url) use (&$destinations): void {
                @unlink($destinations[$url]);
            },
        ]);
        $pool->promise()->wait();
    }

    private function fetchOverHttp(string $normalizedUrl): string
    {
        $dest = $this->tempPath();

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
                    if ($e instanceof ClientException && $e->getResponse()->getStatusCode() === 404) {
                        throw new ScreenshotMissingException('Screenshot not found: ' . $normalizedUrl, 404, $e);
                    }
                    throw $e;
                }
                sleep((int) $this->retryDelays[$attempt]);
                $attempt++;
            }
        }
    }

    private function save(ResultInterface $result, string $dest): void
    {
        if (file_put_contents($dest, (string) $result['Body']) === false) {
            throw new RuntimeException('Unable to write screenshot to ' . $dest);
        }
    }

    /**
     * The bucket key a public screenshot URL points at, or null if the URL is
     * not in the bucket. Doubled slashes are part of some real keys.
     */
    private function keyFor(string $url): ?string
    {
        $normalized = $this->normalizeUrl($url);
        $prefix = 'https://' . self::PUBLIC_HOST . '/';
        if (!str_starts_with($normalized, $prefix)) {
            return null;
        }
        $key = rawurldecode(substr($normalized, strlen($prefix)));
        return $key === '' ? null : $key;
    }

    /**
     * A new, unused path ending in .jpg. (Appending .jpg to tempnam()'s file
     * used to leave tempnam()'s own empty file behind for every frame.)
     */
    private function tempPath(): string
    {
        $base = tempnam($this->workingDir, 'shot_');
        if ($base === false) {
            throw new RuntimeException('Unable to create a temporary file in ' . $this->workingDir);
        }
        @unlink($base);
        return $base . '.jpg';
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
        return 'https://' . self::PUBLIC_HOST . $path;
    }
}
