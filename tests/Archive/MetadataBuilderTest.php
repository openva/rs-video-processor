<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJob;
use RichmondSunlight\VideoProcessor\Archive\MetadataBuilder;

class MetadataBuilderTest extends TestCase
{
    public function testBuildsMetadata(): void
    {
        $job = new ArchiveJob(1, 'senate', 'Floor Session', '2025-01-01', 'https://s3.amazonaws.com/video.richmondsunlight.com/senate/floor/20250101.mp4', 'WEBVTT', null, null, null);
        $builder = new MetadataBuilder();
        $metadata = $builder->build($job);
        $this->assertSame('Floor Session', $metadata['title']);
        $this->assertStringContainsString('Senate', $metadata['subject']);
    }
}
