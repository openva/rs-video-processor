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

    /**
     * A meeting whose committee could not be matched has no committee name, but
     * it is still not a floor session. Its own title names the meeting.
     */
    public function testUnmatchedCommitteeUsesTheVideoTitle(): void
    {
        $job = new ArchiveJob(1, 'house', 'House Civil Subcommittee', '2026-01-28', 'https://video.richmondsunlight.com/x.mp4', null, null, null, null);
        $metadata = (new MetadataBuilder())->build($job);

        $this->assertSame('Virginia General Assembly House Civil Subcommittee Meeting, January 28, 2026', $metadata['title']);
        $this->assertSame('Virginia General Assembly House Civil Subcommittee meeting from 2026-01-28', $metadata['description']);
    }

    public function testFloorTitleStillReadsAsASession(): void
    {
        $job = new ArchiveJob(1, 'house', 'House Session', '2026-01-28', 'https://video.richmondsunlight.com/x.mp4', null, null, null, null);
        $this->assertSame('Virginia General Assembly House Session, January 28, 2026', (new MetadataBuilder())->build($job)['title']);
    }
}
