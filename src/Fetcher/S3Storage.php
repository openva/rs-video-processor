<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;

class S3Storage implements StorageInterface
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
