<?php

namespace RichmondSunlight\VideoProcessor\Tests\Fetcher;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;

class S3ClientFactoryTest extends TestCase
{
    /**
     * Regression guard for the SDK-v2-vs-v3 credential-shape bug: passing
     * 'key'/'secret' as top-level client options (legacy v2 syntax) is silently
     * ignored by aws-sdk-php v3, which then falls through to the default provider
     * chain (env vars -> ~/.aws/credentials -> IMDS). The factory must nest them
     * under 'credentials' so the configured keys actually win.
     *
     * We seed *different* credentials into the environment; if the factory built
     * the client with the broken top-level shape, the SDK would resolve these env
     * values instead of the ones we passed.
     */
    public function testUsesSuppliedStaticCredentialsOverAmbientEnvironment(): void
    {
        $priorKey = getenv('AWS_ACCESS_KEY_ID');
        $priorSecret = getenv('AWS_SECRET_ACCESS_KEY');
        putenv('AWS_ACCESS_KEY_ID=ENV_SHOULD_NOT_WIN');
        putenv('AWS_SECRET_ACCESS_KEY=env-secret-should-not-win');

        try {
            $client = S3ClientFactory::create('AKIASTATICTEST', 'static-secret-value', 'us-east-1');
            $credentials = $client->getCredentials()->wait();

            $this->assertSame('AKIASTATICTEST', $credentials->getAccessKeyId());
            $this->assertSame('static-secret-value', $credentials->getSecretKey());
        } finally {
            putenv($priorKey === false ? 'AWS_ACCESS_KEY_ID' : "AWS_ACCESS_KEY_ID={$priorKey}");
            putenv($priorSecret === false ? 'AWS_SECRET_ACCESS_KEY' : "AWS_SECRET_ACCESS_KEY={$priorSecret}");
        }
    }
}
