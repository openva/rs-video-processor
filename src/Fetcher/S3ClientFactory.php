<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use Aws\S3\S3Client;

class S3ClientFactory
{
    public static function create(
        string $key,
        string $secret,
        string $region = 'us-east-1',
        string $version = '2006-03-01'
    ): S3Client {
        // aws-sdk-php v3 only honours 'key'/'secret' when nested under
        // 'credentials'. Passing them at the top level (legacy v2 syntax) is
        // silently ignored, dropping the client to the default provider chain
        // (env -> ~/.aws/credentials -> IMDS). Keep them nested.
        return new S3Client([
            'region' => $region,
            'version' => $version,
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
        ]);
    }
}
