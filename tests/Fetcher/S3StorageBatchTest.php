<?php

namespace RichmondSunlight\VideoProcessor\Tests\Fetcher;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\S3Storage;

class S3StorageBatchTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'frame_');
        file_put_contents($path, 'jpeg');
        return $this->files[] = $path;
    }

    private function storage(MockHandler $mock): S3Storage
    {
        $client = new S3Client([
            'region' => 'us-east-1',
            'version' => '2006-03-01',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'retries' => 0,
            'handler' => $mock,
        ]);

        return new S3Storage($client, 'video.richmondsunlight.com');
    }

    public function testUploadsEveryFileAndReturnsItsUrl(): void
    {
        $seen = [];
        $mock = new MockHandler();
        for ($i = 0; $i < 5; $i++) {
            $mock->append(function (CommandInterface $command) use (&$seen) {
                $seen[$command['Key']] = [$command->getName(), $command['Bucket'], $command['ACL']];
                return new Result([]);
            });
        }

        $batch = [];
        for ($i = 1; $i <= 5; $i++) {
            $batch[sprintf('house/floor/20260310-7/%08d.jpg', $i)] = $this->tempFile();
        }

        $urls = $this->storage($mock)->uploadBatch($batch, 3);

        $this->assertSame(array_keys($batch), array_keys($urls), 'URLs come back in the order the files were given.');
        $this->assertSame(
            'https://video.richmondsunlight.com/house/floor/20260310-7/00000001.jpg',
            $urls['house/floor/20260310-7/00000001.jpg']
        );
        $this->assertCount(5, $seen);
        foreach ($seen as $call) {
            $this->assertSame(['PutObject', 'video.richmondsunlight.com', 'public-read'], $call);
        }
    }

    public function testAFailedUploadFailsTheWholeBatch(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([]));
        $mock->append(fn(CommandInterface $command) => new S3Exception('Access Denied', $command));
        $mock->append(new Result([]));

        $batch = ['a/1.jpg' => $this->tempFile(), 'a/2.jpg' => $this->tempFile(), 'a/3.jpg' => $this->tempFile()];

        try {
            $this->storage($mock)->uploadBatch($batch, 1);
            $this->fail('A batch with a failed upload must throw, or the manifest would list a missing frame.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 of 3', $e->getMessage());
            $this->assertStringContainsString('Access Denied', $e->getMessage());
        }
    }

    public function testEmptyBatchIsANoOp(): void
    {
        $this->assertSame([], $this->storage(new MockHandler())->uploadBatch([]));
    }
}
