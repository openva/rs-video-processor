<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use Aws\CommandPool;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;

class S3Storage implements BatchStorageInterface
{
    /** Use multipart upload for files larger than 100 MB */
    private const MULTIPART_THRESHOLD = 100 * 1024 * 1024;

    public function __construct(
        private S3Client $client,
        private string $bucket,
        private string $publicBase = 'https://video.richmondsunlight.com'
    ) {
    }

    public function upload(string $localPath, string $key): string
    {
        $fileSize = filesize($localPath);

        if ($fileSize !== false && $fileSize >= self::MULTIPART_THRESHOLD) {
            $this->multipartUpload($localPath, $key);
        } else {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SourceFile' => $localPath,
                'ACL' => 'public-read',
            ]);
        }

        return rtrim($this->publicBase, '/') . '/' . $key;
    }

    /**
     * Upload many small files at once. Each upload is mostly time spent waiting
     * on the network, so running a number of them together is far faster than
     * sending them in turn. The SDK retries each one as usual.
     */
    public function uploadBatch(array $files, int $concurrency = 20): array
    {
        if (empty($files)) {
            return [];
        }

        $commands = (function () use ($files) {
            foreach ($files as $key => $path) {
                yield $key => $this->client->getCommand('PutObject', [
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'SourceFile' => $path,
                    'ACL' => 'public-read',
                ]);
            }
        })();

        $failures = [];
        // CommandPool calls back with each command's position, not its key.
        $keys = array_keys($files);
        $pool = new CommandPool($this->client, $commands, [
            'concurrency' => max(1, $concurrency),
            'rejected' => function ($reason, $position) use (&$failures, $keys): void {
                $failures[$keys[$position]] = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
            },
        ]);
        $pool->promise()->wait();

        if (!empty($failures)) {
            throw new \RuntimeException(sprintf(
                '%d of %d uploads failed. First failure (%s): %s',
                count($failures),
                count($files),
                array_key_first($failures),
                reset($failures)
            ));
        }

        $urls = [];
        foreach (array_keys($files) as $key) {
            $urls[$key] = rtrim($this->publicBase, '/') . '/' . $key;
        }

        return $urls;
    }

    /**
     * Upload large files using multipart upload.
     *
     * putObject reads the file twice (once for SHA256 hash, once for upload body),
     * which can cause XAmzContentSHA256Mismatch errors on large files under I/O
     * pressure. Multipart upload hashes and sends each chunk in a single pass.
     */
    private function multipartUpload(string $localPath, string $key): void
    {
        $uploader = new MultipartUploader($this->client, $localPath, [
            'bucket' => $this->bucket,
            'key' => $key,
            'acl' => 'public-read',
        ]);

        $uploader->upload();
    }
}
