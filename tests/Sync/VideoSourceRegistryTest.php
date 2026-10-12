<?php

namespace RichmondSunlight\VideoProcessor\Tests\Sync;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Sync\VideoSourceRegistry;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class VideoSourceRegistryTest extends TestCase
{
    public function testIdentityOfEachScraperRecordShape(): void
    {
        $this->assertSame(
            ['source' => 'house', 'source_id' => '12345'],
            VideoSourceRegistry::identityOf(['source' => 'house', 'content_id' => 12345])
        );
        $this->assertSame(
            ['source' => 'senate', 'source_id' => '6789'],
            VideoSourceRegistry::identityOf(['source' => 'senate', 'clip_id' => '6789', 'video_id' => '6789'])
        );
        $this->assertSame(
            ['source' => 'senate-youtube', 'source_id' => 'dQw4w9WgXcQ'],
            VideoSourceRegistry::identityOf([
                'source' => 'senate-youtube',
                'video_id' => 'dQw4w9WgXcQ',
                'youtube_id' => 'dQw4w9WgXcQ',
            ])
        );
    }

    public function testIdentityIsNullWithoutSourceOrId(): void
    {
        $this->assertNull(VideoSourceRegistry::identityOf(['content_id' => 1]));
        $this->assertNull(VideoSourceRegistry::identityOf(['source' => 'house']));
        $this->assertNull(VideoSourceRegistry::identityOf(['source' => 'house', 'content_id' => '']));
    }

    public function testRegisterAndFind(): void
    {
        $pdo = new PDO('sqlite::memory:');
        TestDatabaseFactory::addPipelineTables($pdo);
        $registry = new VideoSourceRegistry($pdo);

        $this->assertNull($registry->findFileId('house', '1'));
        $this->assertTrue($registry->register(10, 'house', '1'));
        $this->assertSame(10, $registry->findFileId('house', '1'));

        // Registering the same pair again for the same file is fine.
        $this->assertTrue($registry->register(10, 'house', '1'));
        // A different file cannot take an ID that is already owned.
        $this->assertFalse($registry->register(11, 'house', '1'));
        $this->assertSame(10, $registry->findFileId('house', '1'));
    }
}
