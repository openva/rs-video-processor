<?php

namespace RichmondSunlight\VideoProcessor\Tests\Fetcher;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Tests\Support\FakeBucket;

class BucketReaderTest extends TestCase
{
    public function testReadsTheKeyFromOurOwnUrls(): void
    {
        $reader = (new FakeBucket([]))->reader;

        $this->assertSame('house/floor/20260216-18637.mp4', $reader->keyFor('https://video.richmondsunlight.com/house/floor/20260216-18637.mp4'));
        // files.path holds the key itself, with its literal '+' and '%'.
        $this->assertSame(
            'senate/committee/courts+of+justice+%28sr+a%29-+january+29%2C+2018/video.mp4',
            $reader->keyFor('https://video.richmondsunlight.com/senate/committee/courts+of+justice+%28sr+a%29-+january+29%2C+2018/video.mp4')
        );
        $this->assertNull($reader->keyFor('https://archive.org/download/rs-123/video.mp4'));
        $this->assertNull($reader->keyFor('https://video.richmondsunlight.com/'));
        $this->assertNull($reader->keyFor('file:///tmp/video.mp4'));
    }

    public function testDownloadsAnObjectToAFile(): void
    {
        $bucket = new FakeBucket([new Response(200, ['Content-Type' => 'video/mp4'], 'MP4DATA')]);
        $dest = tempnam(sys_get_temp_dir(), 'bucket_test_');

        try {
            $result = $bucket->reader->download('https://video.richmondsunlight.com/senate/floor/20240221-17514.mp4', $dest);

            $this->assertNotNull($result);
            $this->assertSame('video/mp4', $result['ContentType']);
            $this->assertSame('MP4DATA', file_get_contents($dest));
            $this->assertSame(['/video.richmondsunlight.com/senate/floor/20240221-17514.mp4'], $bucket->requestedPaths());
        } finally {
            @unlink($dest);
        }
    }

    public function testAsksForTheLiteralKey(): void
    {
        $bucket = new FakeBucket([new Response(200, [], 'MP4DATA')]);
        $dest = tempnam(sys_get_temp_dir(), 'bucket_test_');

        try {
            $bucket->reader->download('https://video.richmondsunlight.com/senate/committee/courts+of+justice+%28sr+a%29/video.mp4', $dest);

            // '+' and '%' are part of the key, so they're escaped in the request, not read as escapes.
            $this->assertSame(['/video.richmondsunlight.com/senate/committee/courts%2Bof%2Bjustice%2B%2528sr%2Ba%2529/video.mp4'], $bucket->requestedPaths());
        } finally {
            @unlink($dest);
        }
    }

    public function testLeavesOtherUrlsToTheCaller(): void
    {
        $bucket = new FakeBucket([]);

        $this->assertNull($bucket->reader->download('https://archive.org/download/rs-123/video.mp4', '/nonexistent'));
        $this->assertNull($bucket->reader->presignedUrl('https://archive.org/download/rs-123/video.mp4'));
        $this->assertSame([], $bucket->requests);
    }

    public function testLeavesAFailedReadToTheCaller(): void
    {
        $bucket = new FakeBucket([new Response(404, [], '<Error><Code>NoSuchKey</Code></Error>')]);
        $dest = tempnam(sys_get_temp_dir(), 'bucket_test_');

        try {
            $this->assertNull($bucket->reader->download('https://video.richmondsunlight.com/missing.mp4', $dest));
        } finally {
            @unlink($dest);
        }
    }

    public function testPresignsAUrlForFfmpeg(): void
    {
        $url = (new FakeBucket([]))->reader->presignedUrl('https://video.richmondsunlight.com/house/floor/20260216-18637.mp4');

        $this->assertStringContainsString('/house/floor/20260216-18637.mp4?', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
        $this->assertStringNotContainsString('://video.richmondsunlight.com/', $url);
    }
}
