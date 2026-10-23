<?php

namespace RichmondSunlight\VideoProcessor\Tests\Screenshots;

use PDO;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Tests\Support\FakeBucket;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\StorageInterface;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotGenerator;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJob;

class ScreenshotGeneratorTest extends TestCase
{
    public function testGeneratesScreenshotsAndUpdatesDatabase(): void
    {
        $this->requireFfmpeg();

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE committees (id INTEGER PRIMARY KEY, name TEXT, shortname TEXT, chamber TEXT, parent_id INTEGER)');
        $pdo->exec("INSERT INTO committees (id, name, shortname, chamber, parent_id) VALUES (1, 'Finance Committee', 'finance', 'senate', NULL)");
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            committee_id INTEGER,
            title TEXT,
            date TEXT,
            path TEXT,
            html TEXT,
            fps REAL,
            capture_directory TEXT,
            capture_rate INTEGER,
            date_created TEXT,
            date_modified TEXT
        )');
        $pdo->prepare('INSERT INTO files (chamber, committee_id, title, date, path, fps, capture_directory, capture_rate, date_created, date_modified) VALUES ("senate", NULL, "Test", "2025-11-19", :path, 29.97, "", 0, "2025-11-19 12:00:00", "2025-11-19 12:00:00")')
            ->execute([':path' => 'file://FAKE']);

        $fixture = $this->getVideoFixture('senate-floor.mp4');
        $job = new ScreenshotJob(
            1,
            'senate',
            null,
            '2025-11-19',
            'file://' . $fixture,
            null,
            'Test Video'
        );

        $storage = new class implements StorageInterface {
            public array $uploads = [];
            public function upload(string $localPath, string $key): string
            {
                $this->uploads[$key] = $localPath;
                return 'https://example.test/' . $key;
            }
        };

        $directory = new CommitteeDirectory($pdo);
        $keyBuilder = new S3KeyBuilder();

        $generator = new ScreenshotGenerator($pdo, $storage, $directory, $keyBuilder, null, sys_get_temp_dir());
        $generator->process($job);

        $file = $pdo->query('SELECT capture_directory, capture_rate FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($file['capture_directory']);
        $this->assertStringNotContainsString('/screenshots/', $file['capture_directory']);
        $this->assertStringStartsWith('/', $file['capture_directory']);
        $this->assertStringEndsWith('/', $file['capture_directory']);
        // One screenshot a second of 29.97 fps video: 30 source frames apart.
        $this->assertSame(30, (int) $file['capture_rate']);
    }


    /**
     * Regression: video.richmondsunlight.com holds IIS 502 error pages saved as
     * .mp4 (18 of them, all exactly 1477 bytes, from a January 2020 outage).
     * They are served with HTTP 200 and Content-Type: video/mp4, and at 1477
     * bytes they clear the old 1024-byte size floor, so they reached ffmpeg and
     * failed there with "moov atom not found".
     */
    public function testRejectsHtmlErrorPageSavedAsMp4(): void
    {
        $this->requireFfmpeg();

        $fixture = $this->getVideoFixture('gateway-502-error.mp4');
        $generator = $this->makeGenerator();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a valid video/i');

        $this->invokeValidate($generator, $fixture);
    }

    /**
     * ffprobe -select_streams v:0 exits 0 on a file with no video stream at all,
     * so the old check passed WebVTT and JSON payloads as valid videos.
     */
    public function testRejectsNonVideoPayloadThatFfprobeParsesCleanly(): void
    {
        $this->requireFfmpeg();

        $path = tempnam(sys_get_temp_dir(), 'novideo') . '.mp4';
        file_put_contents($path, "WEBVTT\n\n00:00:20.960 --> 00:00:25.020\n" . str_repeat("caption text\n", 500));

        $generator = $this->makeGenerator();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/not a valid video/i');
            $this->invokeValidate($generator, $path);
        } finally {
            @unlink($path);
        }
    }

    public function testAcceptsRealVideo(): void
    {
        $this->requireFfmpeg();

        $fixture = $this->getVideoFixture('house-floor.mp4');
        $generator = $this->makeGenerator();

        $this->invokeValidate($generator, $fixture);
        $this->addToAssertionCount(1);
    }


    /**
     * Some S3 keys contain literal '+' and '%' characters, because committee
     * names were passed through urlencode() at upload time and the encoded form
     * became the actual key. Fetching those over HTTPS requires escaping again:
     * '+' -> %2B and '%' -> %25, i.e. rawurlencode() per path segment.
     *
     * Real example (file #899): the stored path 404s as-is but returns the
     * 1.36 GB video once escaped.
     */
    public function testEscapesPlusAndPercentInS3Keys(): void
    {
        $stored = 'https://video.richmondsunlight.com/senate/committee/'
            . 'courts+of+justice+%28sr+a%29-+january+29%2C+2018/20180129.mp4';
        $expected = 'https://video.richmondsunlight.com/senate/committee/'
            . 'courts%2Bof%2Bjustice%2B%2528sr%2Ba%2529-%2Bjanuary%2B29%252C%2B2018/20180129.mp4';

        $generator = $this->makeGenerator();
        $method = new \ReflectionMethod($generator, 'encodeUrlPath');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($generator, $stored));
    }

    /** A path with no special characters must pass through untouched. */
    public function testLeavesOrdinaryS3KeysUnchanged(): void
    {
        $url = 'https://video.richmondsunlight.com/house/floor/20260313.mp4';

        $generator = $this->makeGenerator();
        $method = new \ReflectionMethod($generator, 'encodeUrlPath');
        $method->setAccessible(true);

        $this->assertSame($url, $method->invoke($generator, $url));
    }

    /** Query strings and file:// fixtures must not be mangled. */
    public function testLeavesNonHttpAndQueryStringsIntact(): void
    {
        $generator = $this->makeGenerator();
        $method = new \ReflectionMethod($generator, 'encodeUrlPath');
        $method->setAccessible(true);

        $this->assertSame('file:///tmp/x.mp4', $method->invoke($generator, 'file:///tmp/x.mp4'));
        // The path's '+' is escaped (it is a literal key character); the query
        // string is left byte-for-byte intact so signatures stay valid.
        $this->assertSame(
            'https://example.test/a%2Bb/c.mp4?sig=x%2By&t=1',
            $method->invoke($generator, 'https://example.test/a+b/c.mp4?sig=x%2By&t=1')
        );
    }

    private function makeGenerator(?FakeBucket $bucket = null, ?MockHandler $http = null): ScreenshotGenerator
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE committees (id INTEGER PRIMARY KEY, name TEXT, shortname TEXT, chamber TEXT, parent_id INTEGER)');
        $storage = new class implements StorageInterface {
            public function upload(string $localPath, string $key): string
            {
                return 'https://example.test/' . $key;
            }
        };

        return new ScreenshotGenerator(
            $pdo,
            $storage,
            new CommitteeDirectory($pdo),
            new S3KeyBuilder(),
            null,
            sys_get_temp_dir(),
            $http ? new Client(['handler' => HandlerStack::create($http)]) : null,
            null,
            $bucket?->reader
        );
    }

    private function invokeValidate(ScreenshotGenerator $generator, string $path): void
    {
        $method = new \ReflectionMethod($generator, 'validateVideo');
        $method->setAccessible(true);
        $method->invoke($generator, $path);
    }

    private function getVideoFixture(string $filename): string
    {
        $path = __DIR__ . '/../fixtures/' . $filename;
        if (!file_exists($path)) {
            $this->markTestSkipped('Missing video fixture ' . $filename . '. Run bin/fetch_test_fixtures.php.');
        }
        return $path;
    }

    private function requireFfmpeg(): void
    {
        exec('ffmpeg -version > /dev/null 2>&1', $output, $status);
        if ($status !== 0) {
            $this->markTestSkipped('ffmpeg is required for screenshot tests.');
        }
    }

    /**
     * Several file rows can point at one legacy S3 object. Each must still get
     * its own screenshot directory, or one video's frames overwrite another's.
     */
    public function testScreenshotPrefixIsUniquePerFile(): void
    {
        $generator = $this->makeGenerator();
        $method = new \ReflectionMethod($generator, 'buildScreenshotPrefix');
        $method->setAccessible(true);

        $shared = 'https://video.richmondsunlight.com/house/floor/20260817.mp4';
        $first = new ScreenshotJob(18797, 'house', null, '2026-08-17', $shared, null, 'A');
        $second = new ScreenshotJob(18798, 'house', null, '2026-08-17', $shared, null, 'B');
        $this->assertSame('house/floor/20260817-18797', $method->invoke($generator, $first));
        $this->assertSame('house/floor/20260817-18798', $method->invoke($generator, $second));

        // A key that already carries the ID is not suffixed twice.
        $unique = new ScreenshotJob(
            18797,
            'house',
            null,
            '2026-08-17',
            'https://video.richmondsunlight.com/house/floor/20260817-18797.mp4',
            null,
            'A'
        );
        $this->assertSame('house/floor/20260817-18797', $method->invoke($generator, $unique));

        // A stale capture_directory value is ignored.
        $stale = new ScreenshotJob(18797, 'house', null, '2026-08-17', $shared, '/house/floor/20260817//', 'A');
        $this->assertSame('house/floor/20260817-18797', $method->invoke($generator, $stale));

        // A video that is not on S3 falls back to the key builder.
        $archived = new ScreenshotJob(5, 'senate', null, '2021-02-03', 'https://archive.org/download/x/y.mp4', null, 'C');
        $this->assertSame('senate/floor/20210203-5', $method->invoke($generator, $archived));
    }

    /**
     * Frames are uploaded as one concurrent batch when the storage supports it.
     * Uploading them one at a time took most of each job's running time.
     */
    public function testUploadsFramesAsOneBatch(): void
    {
        $dir = sys_get_temp_dir() . '/frames_' . uniqid();
        mkdir($dir . '/full', 0775, true);
        mkdir($dir . '/thumb', 0775, true);
        $frames = [];
        foreach (['00000001.jpg', '00000002.jpg', '00000003.jpg'] as $name) {
            file_put_contents($dir . '/full/' . $name, 'f');
            $frames[] = $dir . '/full/' . $name;
        }
        // The third frame has no thumbnail.
        file_put_contents($dir . '/thumb/00000001.jpg', 't');
        file_put_contents($dir . '/thumb/00000002.jpg', 't');

        $storage = new class implements \RichmondSunlight\VideoProcessor\Fetcher\BatchStorageInterface {
            public array $batches = [];
            public int $singles = 0;

            public function upload(string $localPath, string $key): string
            {
                $this->singles++;
                return 'https://example.test/' . $key;
            }

            public function uploadBatch(array $files, int $concurrency = 20): array
            {
                $this->batches[] = $files;
                $urls = [];
                foreach ($files as $key => $path) {
                    $urls[$key] = 'https://example.test/' . $key;
                }
                return $urls;
            }
        };

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE committees (id INTEGER PRIMARY KEY, name TEXT, shortname TEXT, chamber TEXT, parent_id INTEGER)');
        $generator = new ScreenshotGenerator($pdo, $storage, new CommitteeDirectory($pdo), new S3KeyBuilder(), null, sys_get_temp_dir());

        $method = new \ReflectionMethod($generator, 'uploadFramesParallel');
        $method->setAccessible(true);
        $manifest = $method->invoke($generator, $frames, $dir . '/thumb', 'house/floor/20260310-7');

        $this->assertCount(1, $storage->batches, 'All frames go up in a single batch.');
        $this->assertSame(0, $storage->singles);
        $this->assertCount(5, $storage->batches[0], 'Three frames and two thumbnails.');
        $this->assertSame([
            ['timestamp' => 0, 'full' => 'https://example.test/house/floor/20260310-7/00000001.jpg', 'thumb' => 'https://example.test/house/floor/20260310-7/00000001-thumbnail.jpg'],
            ['timestamp' => 1, 'full' => 'https://example.test/house/floor/20260310-7/00000002.jpg', 'thumb' => 'https://example.test/house/floor/20260310-7/00000002-thumbnail.jpg'],
            ['timestamp' => 2, 'full' => 'https://example.test/house/floor/20260310-7/00000003.jpg', 'thumb' => null],
        ], $manifest);

        array_map('unlink', glob($dir . '/*/*.jpg'));
        rmdir($dir . '/full');
        rmdir($dir . '/thumb');
        rmdir($dir);
    }

    /** Our own videos come from the bucket, never over HTTPS through CloudFront. */
    public function testDownloadsOurOwnVideosFromTheBucket(): void
    {
        $this->requireFfmpeg();
        // The smallest fixture: Guzzle's mock handler holds the whole body in memory.
        $fixture = $this->getVideoFixture('senate-committee.mp4');
        $bucket = new FakeBucket([new Response(200, ['Content-Type' => 'video/mp4'], fopen($fixture, 'rb'))]);
        // No responses: any HTTPS request fails the test.
        $http = new MockHandler([]);
        $dest = tempnam(sys_get_temp_dir(), 'shot_video_');

        try {
            $this->invokeDownload($this->makeGenerator($bucket, $http), 'https://video.richmondsunlight.com/senate/floor/20251119-1.mp4', $dest);

            $this->assertSame(filesize($fixture), filesize($dest));
            $this->assertSame(['/video.richmondsunlight.com/senate/floor/20251119-1.mp4'], $bucket->requestedPaths());
        } finally {
            @unlink($dest);
        }
    }

    public function testRejectsAnObjectFromTheBucketThatIsNotAVideo(): void
    {
        $bucket = new FakeBucket([new Response(200, ['Content-Type' => 'text/vtt'], "WEBVTT\n")]);
        $dest = tempnam(sys_get_temp_dir(), 'shot_video_');

        try {
            $this->expectExceptionMessage('Content-Type "text/vtt"');
            $this->invokeDownload($this->makeGenerator($bucket, new MockHandler([])), 'https://video.richmondsunlight.com/senate/floor/20251119-1.mp4', $dest);
        } finally {
            @unlink($dest);
        }
    }

    /** If S3 can't supply a video, it's fetched over HTTPS, as before. */
    public function testFallsBackToHttpsWhenTheBucketFails(): void
    {
        $this->requireFfmpeg();
        // The smallest fixture: Guzzle's mock handler holds the whole body in memory.
        $fixture = $this->getVideoFixture('senate-committee.mp4');
        $bucket = new FakeBucket([new Response(403, [], '<Error><Code>AccessDenied</Code></Error>')]);
        $http = new MockHandler([new Response(200, ['Content-Type' => 'video/mp4'], fopen($fixture, 'rb'))]);
        $dest = tempnam(sys_get_temp_dir(), 'shot_video_');

        try {
            $this->invokeDownload($this->makeGenerator($bucket, $http), 'https://video.richmondsunlight.com/senate/floor/20251119-1.mp4', $dest);

            $this->assertSame(filesize($fixture), filesize($dest));
            $this->assertSame(0, $http->count(), 'The video was fetched over HTTPS.');
        } finally {
            @unlink($dest);
        }
    }

    private function invokeDownload(ScreenshotGenerator $generator, string $url, string $destination): void
    {
        $method = new \ReflectionMethod($generator, 'downloadVideo');
        $method->invoke($generator, $url, $destination);
    }
}
