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

    public function testCorrectsPrefixOcrError_H8_to_HB(): void
    {
        // Real-world OCR error: "H8 2067" should match as "HB 2067"
        $result = $this->matcher->parseBillNumber('H8 2067');

        $this->assertNotNull($result);
        $this->assertSame('house', $result['chamber']);
        $this->assertSame('bill', $result['type']);
        $this->assertSame('2067', $result['number']);
    }

    public function testCorrectsPrefixOcrError_LH8_to_HB(): void
    {
        // Real-world OCR error: "L H8 2067" should match as "HB 2067"
        $result = $this->matcher->parseBillNumber('L H8 2067');

        $this->assertNotNull($result);
        $this->assertSame('house', $result['chamber']);
        $this->assertSame('bill', $result['type']);
        $this->assertSame('2067', $result['number']);
    }

    public function testCorrectsPrefixOcrError_1H8_to_HB(): void
    {
        // Real-world OCR error: "1 H8 2067" should match as "HB 2067"
        $result = $this->matcher->parseBillNumber('1 H8 2067');

        $this->assertNotNull($result);
        $this->assertSame('house', $result['chamber']);
        $this->assertSame('bill', $result['type']);
        $this->assertSame('2067', $result['number']);
    }

    public function testCorrectsPrefixOcrError_S8_to_SB(): void
    {
        // OCR error: "S8 456" should match as "SB 456"
        $result = $this->matcher->parseBillNumber('S8 456');

        $this->assertNotNull($result);
        $this->assertSame('senate', $result['chamber']);
        $this->assertSame('bill', $result['type']);
        $this->assertSame('456', $result['number']);
    }

    public function testCorrectsPrefixOcrError_1HB_to_HB(): void
    {
        // OCR error: "1HB 1234" should match as "HB 1234"
        $result = $this->matcher->parseBillNumber('1HB 1234');

        $this->assertNotNull($result);
        $this->assertSame('house', $result['chamber']);
        $this->assertSame('bill', $result['type']);
        $this->assertSame('1234', $result['number']);
    }

    public function testCorrectsPrefixOcrError_HJ8_to_HJR(): void
    {
        // OCR error: "HJ8 42" should match as "HJR 42"
        $result = $this->matcher->parseBillNumber('HJ8 42');

        $this->assertNotNull($result);
        $this->assertSame('house', $result['chamber']);
        $this->assertSame('joint_resolution', $result['type']);
        $this->assertSame('42', $result['number']);
    }
}
