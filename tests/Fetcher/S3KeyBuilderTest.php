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

    /**
     * The historical bug: committee names reached this builder unsanitised and
     * were passed through urlencode() upstream, so keys like
     * house/committee/militia%2C+police+and+public+safety/ were created. Those
     * contain literal '+' and '%' and 404 over HTTPS unless re-escaped.
     * 17 such prefixes had to be migrated by hand. Slugify so it cannot recur.
     *
     * @dataProvider dirtyShortnameProvider
     */
    public function testSlugifiesDirtyShortnames(string $shortname, string $expectedSegment): void
    {
        $builder = new S3KeyBuilder();
        $key = $builder->build('House', '2019-01-11', $shortname);
        $this->assertSame('house/committee/' . $expectedSegment . '/20190111.mp4', $key);
    }

    public static function dirtyShortnameProvider(): array
    {
        return [
            'spaces'            => ['Militia, Police and Public Safety', 'militia-police-and-public-safety'],
            'already encoded'   => ['militia%2C+police+and+public+safety', 'militia-2c-police-and-public-safety'],
            'ampersand'         => ['Science & Technology', 'science-technology'],
            'parens and commas' => ['Finance (Comm Room B)- January 24, 2018', 'finance-comm-room-b-january-24-2018'],
            'already clean'     => ['general-laws', 'general-laws'],
            'mixed case'        => ['Natural-Resources', 'natural-resources'],
            'leading trailing'  => ['  finance  ', 'finance'],
            'collapses runs'    => ['a---b__c', 'a-b-c'],
            'strips slashes'    => ['courts/of/justice', 'courts-of-justice'],
        ];
    }

    /**
     * A shortname that slugifies to nothing must not silently produce
     * 'committee//<date>.mp4' -- that empty segment happened in production and
     * collided across committees. Fall back to the floor path instead.
     */
    public function testShortnameThatSlugifiesToEmptyFallsBackToFloor(): void
    {
        $builder = new S3KeyBuilder();
        $this->assertSame('house/floor/20190111.mp4', $builder->build('House', '2019-01-11', '###'));
        $this->assertSame('house/floor/20190111.mp4', $builder->build('House', '2019-01-11', '   '));
    }

    /** Numeric shortnames (the getShortnameById id fallback) must survive. */
    public function testNumericShortnamePreserved(): void
    {
        $builder = new S3KeyBuilder();
        $this->assertSame('senate/committee/181/20190111.mp4', $builder->build('Senate', '2019-01-11', '181'));
    }
}
