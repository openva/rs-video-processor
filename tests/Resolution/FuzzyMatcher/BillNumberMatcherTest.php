<?php

namespace RichmondSunlight\VideoProcessor\Tests\Resolution\FuzzyMatcher;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Resolution\FuzzyMatcher\BillNumberMatcher;

class BillNumberMatcherTest extends TestCase
{
    private BillNumberMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new BillNumberMatcher();
    }

    public function testParsesHouseBillStandardFormat(): void
    {
        $result = $this->matcher->parseBillNumber('HB1234');

        $this->assertSame('house', $result['chamber']);
        $this->assertSame('bill', $result['type']);
        $this->assertSame('1234', $result['number']);
    }

    public function testParsesSenateBillStandardFormat(): void
    {
        $result = $this->matcher->parseBillNumber('SB567');

        $this->assertSame('senate', $result['chamber']);
        $this->assertSame('bill', $result['type']);
        $this->assertSame('567', $result['number']);
    }

    public function testParsesHouseBillWithDots(): void
    {
        $result = $this->matcher->parseBillNumber('H.B. 1234');

        $this->assertSame('house', $result['chamber']);
        $this->assertSame('1234', $result['number']);
    }

    public function testParsesFullName(): void
    {
        $result = $this->matcher->parseBillNumber('House Bill 1234');

        $this->assertSame('house', $result['chamber']);
        $this->assertSame('bill', $result['type']);
        $this->assertSame('1234', $result['number']);
    }

    public function testParsesHouseJointResolution(): void
    {
        $result = $this->matcher->parseBillNumber('HJR42');

        $this->assertSame('house', $result['chamber']);
        $this->assertSame('joint_resolution', $result['type']);
        $this->assertSame('42', $result['number']);
    }

    public function testParsesSenateResolution(): void
    {
        $result = $this->matcher->parseBillNumber('SR99');

        $this->assertSame('senate', $result['chamber']);
        $this->assertSame('resolution', $result['type']);
        $this->assertSame('99', $result['number']);
    }

    public function testStripsLeadingZeros(): void
    {
        $result = $this->matcher->parseBillNumber('HB0123');

        $this->assertSame('123', $result['number']);
    }

    public function testHandlesZero(): void
    {
        $result = $this->matcher->parseBillNumber('HB0');

        $this->assertSame('0', $result['number']);
    }

    public function testReturnsNullForInvalidFormat(): void
    {
        $result = $this->matcher->parseBillNumber('XYZ123');

        $this->assertNull($result);
    }

    public function testReturnsNullForEmptyString(): void
    {
        $result = $this->matcher->parseBillNumber('');

        $this->assertNull($result);
    }

    public function testGeneratesNumberVariations(): void
    {
        $variations = $this->matcher->generateNumberVariations('101');

        // Should include variations like 181 (1→7), 10l (1→l), etc.
        $this->assertGreaterThan(0, count($variations));
        $this->assertContains('181', $variations); // 1→8 in first position
    }

    public function testVariationsAreConservative(): void
    {
        // Should only generate single-character substitutions
        $variations = $this->matcher->generateNumberVariations('123');

        // Check that we don't have multi-character substitutions
        foreach ($variations as $variant) {
            $this->assertSame(3, strlen($variant));
        }
    }

    public function testFormatsBillNumber(): void
    {
        $formatted = $this->matcher->formatBillNumber('house', 'bill', '1234');

        $this->assertSame('HB1234', $formatted);
    }

    public function testFormatsSenateBill(): void
    {
        $formatted = $this->matcher->formatBillNumber('senate', 'bill', '567');

        $this->assertSame('SB567', $formatted);
    }

    public function testFormatsJointResolution(): void
    {
        $formatted = $this->matcher->formatBillNumber('house', 'joint_resolution', '42');

        $this->assertSame('HJR42', $formatted);
    }

    public function testFormatsResolution(): void
    {
        $formatted = $this->matcher->formatBillNumber('senate', 'resolution', '99');

        $this->assertSame('SR99', $formatted);
    }

    public function testExtractsBillFromSentence(): void
    {
        $result = $this->matcher->parseBillNumber('The committee discussed HB1234 today.');

        $this->assertSame('1234', $result['number']);
        $this->assertSame('house', $result['chamber']);
    }

    public function testHandlesMultipleBillsInText(): void
    {
        // Should match first occurrence
        $result = $this->matcher->parseBillNumber('HB123 and SB456');

        $this->assertSame('house', $result['chamber']);
        $this->assertSame('123', $result['number']);
    }
}
