<?php

namespace RichmondSunlight\VideoProcessor\Tests\Fetcher;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;

class S3KeyBuilderTest extends TestCase
{
    public function testBuildsFloorPath(): void
    {
        $builder = new S3KeyBuilder();
        $key = $builder->build('Senate', '2025-11-19');
        $this->assertSame('senate/floor/20251119.mp4', $key);
    }

    public function testBuildsCommitteePath(): void
    {
        $builder = new S3KeyBuilder();
        $key = $builder->build('House', '2025-02-01', 'finance');
        $this->assertSame('house/committee/finance/20250201.mp4', $key);
    }
}
