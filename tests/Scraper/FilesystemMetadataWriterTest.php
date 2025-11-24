<?php

namespace RichmondSunlight\VideoProcessor\Tests\Scraper;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Scraper\FilesystemMetadataWriter;

class FilesystemMetadataWriterTest extends TestCase
{
    public function testWritesMetadataToDisk(): void
    {
        $dir = sys_get_temp_dir() . '/rs-video-processor-tests';
        @mkdir($dir, 0777, true);

        $writer = new FilesystemMetadataWriter($dir);
        $path = $writer->write([
            ['source' => 'house', 'title' => 'Appropriations'],
            ['source' => 'senate', 'title' => 'Floor'],
        ]);

        $this->assertFileExists($path);

        $payload = json_decode(file_get_contents($path), true);
        $this->assertSame(2, $payload['record_count']);
        $this->assertCount(2, $payload['records']);
    }
}
