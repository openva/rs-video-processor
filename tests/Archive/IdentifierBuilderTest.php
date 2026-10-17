<?php

namespace RichmondSunlight\VideoProcessor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJob;
use RichmondSunlight\VideoProcessor\Archive\IdentifierBuilder;

class IdentifierBuilderTest extends TestCase
{
    private function job(int $fileId, string $chamber, string $title, string $date): ArchiveJob
    {
        return new ArchiveJob($fileId, $chamber, $title, $date, 'https://video.richmondsunlight.com/x.mp4', null, null, null, null);
    }

    public function testIsChamberDateAndFileId(): void
    {
        $this->assertSame(
            'va-senate-20260227-18867',
            (new IdentifierBuilder())->build($this->job(18867, 'Senate', 'Senate Session', '2026-02-27'))
        );
    }

    /**
     * Two recordings with the same chamber, date and title once got the same
     * identifier, so the second upload was added to the first video's item.
     */
    public function testVideosWithTheSameTitleAndDateGetDifferentIdentifiers(): void
    {
        $builder = new IdentifierBuilder();
        $this->assertNotSame(
            $builder->build($this->job(18867, 'house', 'House Session', '2026-02-27')),
            $builder->build($this->job(18868, 'house', 'House Session', '2026-02-27'))
        );
    }
}
