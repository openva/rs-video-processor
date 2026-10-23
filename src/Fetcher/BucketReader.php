<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use Aws\ResultInterface;
use Aws\S3\S3Client;
use Log;

/**
 * Reads our own videos straight from the S3 bucket, rather than through
 * https://video.richmondsunlight.com/. The workers run in AWS, so reading
 * from the bucket is free, while the public URL passes through Cloudflare and
 * CloudFront, which bills for every byte. Downloading whole videos that way
 * cost about 700 GB of CloudFront transfer in October 2026.
 *
 * Anything this can't read is left to the caller to fetch over HTTPS, as
 * before: a URL that isn't in the bucket returns null, as does a failed read.
 */
class BucketReader
{
    public const PUBLIC_HOST = 'video.richmondsunlight.com';

    public function __construct(
        private S3Client $s3,
        private string $bucket = self::PUBLIC_HOST,
        private ?Log $logger = null
    ) {
    }

    /**
     * The bucket key a public URL points at, or null if it isn't one of ours.
     * files.path holds the key itself, including any literal '+' and '%' in
     * it (see ScreenshotGenerator::encodeUrlPath()), so nothing is decoded.
     */
    public function keyFor(string $url): ?string
    {
        $prefix = 'https://' . self::PUBLIC_HOST . '/';
        if (!str_starts_with($url, $prefix)) {
            return null;
        }
        $key = substr($url, strlen($prefix));
        return $key === '' ? null : $key;
    }

    /**
     * Save the object a public URL points at to $destination, streaming it to
     * disk, since videos run to several GB.
     *
     * @return ResultInterface|null the S3 result (its ContentType is the one
     *         HTTPS would have given), or null if the caller must use HTTPS
     */
    public function download(string $url, string $destination): ?ResultInterface
    {
        $key = $this->keyFor($url);
        if ($key === null) {
            return null;
        }
        try {
            return $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $destination]);
        } catch (\Throwable $e) {
            $this->logger?->put(sprintf('Unable to read %s from S3, so downloading it over HTTPS: %s', $key, $e->getMessage()), 4);
            return null;
        }
    }

    /**
     * A presigned S3 URL for the object a public URL points at, for ffmpeg to
     * read parts of the video from without downloading it all. Null if the
     * URL isn't one of ours.
     */
    public function presignedUrl(string $url, string $expires = '+1 hour'): ?string
    {
        $key = $this->keyFor($url);
        if ($key === null) {
            return null;
        }
        $command = $this->s3->getCommand('GetObject', ['Bucket' => $this->bucket, 'Key' => $key]);
        return (string) $this->s3->createPresignedRequest($command, $expires)->getUri();
    }
}
