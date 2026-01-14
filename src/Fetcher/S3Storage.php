<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use Aws\S3\S3Client;

class S3Storage implements StorageInterface
{
    public function __construct(
        private S3Client $client,
        private string $bucket,
        private string $publicBase = 'https://video.richmondsunlight.com'
    ) {
    }

    public function upload(string $localPath, string $key): string
    {
        $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'SourceFile' => $localPath,
            'ACL' => 'public-read',
        ]);

        return rtrim($this->publicBase, '/') . '/' . $key;
    }
}
