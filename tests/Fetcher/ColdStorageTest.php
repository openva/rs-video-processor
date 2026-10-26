<?php

namespace RichmondSunlight\VideoProcessor\Tests\Fetcher;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\ColdStorage;

class ColdStorageTest extends TestCase
{
    /** @var list<array{0:string,1:array}> */
    private array $sent = [];

    private function client(array $results): S3Client
    {
        $mock = new MockHandler();
        foreach ($results as $result) {
            $mock->append(function (CommandInterface $command) use ($result) {
                $this->sent[] = [$command->getName(), $command->toArray()];
                return $result;
            });
        }

        return new S3Client([
            'region' => 'us-east-1',
            'version' => '2006-03-01',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $mock,
        ]);
    }

    public function testMovesAStandardObjectAndKeepsItPublic(): void
    {
        $cold = new ColdStorage($this->client([
            new Result([]),                                        // HeadObject: Standard (no StorageClass)
            new Result(['ContentLength' => 1_200_000_000]),         // ObjectCopier's HeadObject
            new Result([]),                                        // CopyObject
        ]));

        $this->assertSame('moved', $cold->move('senate/floor/20200226-6304.mp4'));

        [$name, $copy] = end($this->sent);
        $this->assertSame('CopyObject', $name);
        $this->assertSame('GLACIER_IR', $copy['StorageClass']);
        $this->assertSame('public-read', $copy['ACL']);
        $this->assertSame('COPY', $copy['MetadataDirective']);
        $this->assertSame('senate/floor/20200226-6304.mp4', $copy['Key']);
    }

    /** Copying it again would start a new 90-day minimum. */
    public function testLeavesAnObjectAlreadyInColdStorage(): void
    {
        $cold = new ColdStorage($this->client([new Result(['StorageClass' => 'GLACIER_IR'])]));

        $this->assertSame('already', $cold->move('senate/floor/20200226-6304.mp4'));
        $this->assertSame(['HeadObject'], array_column($this->sent, 0));
    }

    public function testListsVideosWithoutOpeningScreenshotDirectories(): void
    {
        $object = static fn(string $key, string $class = 'STANDARD') => [
            'Key' => $key, 'Size' => 1000, 'StorageClass' => $class, 'LastModified' => '2026-10-01T00:00:00Z',
        ];
        $cold = new ColdStorage($this->client([
            // house/floor/: a video and a screenshot directory beside it
            new Result(['Contents' => [$object('house/floor/20260116.mp4')], 'CommonPrefixes' => [['Prefix' => 'house/floor/20260116-15576/']]]),
            // house/committee/: one committee
            new Result(['CommonPrefixes' => [['Prefix' => 'house/committee/public-safety/']]]),
            new Result(['Contents' => [$object('house/committee/public-safety/20260116.mp4'), $object('house/committee/public-safety/notes.vtt')]]),
            // senate/floor/ and senate/committee/
            new Result(['Contents' => [$object('senate/floor/20200226-6304.mp4', 'GLACIER_IR')]]),
            new Result([]),
        ]));

        $videos = $cold->videos();

        $this->assertSame(
            ['house/floor/20260116.mp4', 'house/committee/public-safety/20260116.mp4', 'senate/floor/20200226-6304.mp4'],
            array_column($videos, 'key')
        );
        $this->assertSame('GLACIER_IR', $videos[2]['storageClass']);
        foreach ($this->sent as [, $params]) {
            $this->assertSame('/', $params['Delimiter'], 'Every listing is delimited.');
        }
    }

    public function testMovesOnlyVideosNobodyPlaysFromS3(): void
    {
        $now = new DateTimeImmutable('2026-10-25 12:00:00');
        $video = static fn(string $key, string $class, string $modified) => [
            'key' => $key, 'size' => 1, 'storageClass' => $class, 'lastModified' => new DateTimeImmutable($modified),
        ];
        $videos = [
            $video('senate/floor/archived.mp4', 'STANDARD', '2026-10-01'),
            $video('house/floor/old-rrs.mp4', 'REDUCED_REDUNDANCY', '2015-01-01'),
            $video('house/floor/played.mp4', 'STANDARD', '2026-10-01'),
            $video('house/floor/cold.mp4', 'GLACIER_IR', '2026-10-01'),
            // Uploaded an hour ago: its download may not be recorded yet.
            $video('house/floor/just-downloaded.mp4', 'STANDARD', '2026-10-25 11:00:00'),
        ];

        $this->assertSame(
            ['senate/floor/archived.mp4', 'house/floor/old-rrs.mp4'],
            array_column(ColdStorage::toMove($videos, ['house/floor/played.mp4'], $now), 'key')
        );
    }
}
