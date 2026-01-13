<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJob;
use RichmondSunlight\VideoProcessor\Archive\MetadataBuilder;

class MetadataBuilderTest extends TestCase
{
    public function testBuildsMetadataForFloorSession(): void
    {
        $job = new ArchiveJob(1, 'senate', 'Floor Session', '2025-01-01', 'https://video.richmondsunlight.com/senate/floor/20250101.mp4', 'WEBVTT', null, null, null);
        $builder = new MetadataBuilder();
        $metadata = $builder->build($job);
        $this->assertSame('Virginia General Assembly Senate Session, January 1, 2025', $metadata['title']);
        $this->assertStringContainsString('Senate', $metadata['subject']);
        $this->assertStringContainsString('Virginia General Assembly', $metadata['subject']);
        $this->assertStringContainsString('Senate session', $metadata['description']);
    }

    public function testBuildsMetadataForCommitteeMeeting(): void
    {
        $job = new ArchiveJob(1, 'house', 'Appropriations', '2023-01-27', 'https://video.richmondsunlight.com/house/committee/20230127.mp4', 'WEBVTT', null, null, null, 42, 'Courts of Justice');
        $builder = new MetadataBuilder();
        $metadata = $builder->build($job);
        $this->assertSame('Virginia General Assembly House Courts of Justice Meeting, January 27, 2023', $metadata['title']);
        $this->assertStringContainsString('House', $metadata['subject']);
        $this->assertStringContainsString('Courts of Justice', $metadata['subject']);
        $this->assertStringContainsString('Courts of Justice meeting', $metadata['description']);
    }
}
