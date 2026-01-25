<?php

namespace RichmondSunlight\VideoProcessor\Tests\Resolution\FuzzyMatcher;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Resolution\FuzzyMatcher\NameMatcher;

class NameMatcherTest extends TestCase
{
    private NameMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new NameMatcher();
    }

    public function testExtractsCleanNameFromRawText(): void
    {
        $result = $this->matcher->extractLegislatorName('Sen. Robert T. Smith (R-6)');

        $this->assertSame('Robert T. Smith', $result['cleaned']);
        $this->assertSame(['Robert', 'T.', 'Smith'], $result['tokens']);
        $this->assertSame('Sen.', $result['prefix']);
        $this->assertSame('R', $result['party']);
        $this->assertSame('6', $result['district']);
    }

    public function testExtractsNameWithDelegatePrefix(): void
    {
        $result = $this->matcher->extractLegislatorName('Del. John Doe (D)');

        $this->assertSame('John Doe', $result['cleaned']);
        $this->assertSame('Del.', $result['prefix']);
        $this->assertSame('D', $result['party']);
    }

    public function testRemovesLocationIndicators(): void
    {
        $result = $this->matcher->extractLegislatorName('Bob Smith of Richmond');

        $this->assertSame('Bob Smith', $result['cleaned']);
    }

    public function testHandlesComplexFormatting(): void
    {
        $result = $this->matcher->extractLegislatorName('Senator Jane Doe-Smith (D-42)');

        $this->assertSame('Jane Doe-Smith', $result['cleaned']);
        $this->assertSame('D', $result['party']);
        $this->assertSame('42', $result['district']);
    }

    public function testPivotsCommaName(): void
    {
        $this->assertSame('Bob Smith', $this->matcher->pivotCommaName('Smith, Bob'));
        $this->assertSame('John Doe', $this->matcher->pivotCommaName('Doe, John'));
    }

    public function testHandlesNonCommaSeparatedName(): void
    {
        $this->assertSame('Bob Smith', $this->matcher->pivotCommaName('Bob Smith'));
    }

    public function testGeneratesOcrVariations(): void
    {
        $variations = $this->matcher->generateOcrVariations('Bob Smith');

        $this->assertContains('Bob Smith', $variations); // Original
        $this->assertContains('B0b Smith', $variations); // o→0
        $this->assertContains('Bob 5mith', $variations); // S→5
    }

    public function testLimitsOcrVariations(): void
    {
        $variations = $this->matcher->generateOcrVariations('Bob Smith', 5);

        $this->assertLessThan(6, count($variations));
        $this->assertGreaterThan(0, count($variations));
    }

    public function testCalculatesExactMatchScore(): void
    {
        $score = $this->matcher->calculateNameScore(
            'Bob Smith',
            'Smith, Bob',
            ['Bob', 'Smith']
        );

        $this->assertSame(100.0, $score);
    }

    public function testCalculatesHighScoreForOcrError(): void
    {
        $score = $this->matcher->calculateNameScore(
            'B0b Smith',  // 0 instead of o
            'Smith, Bob',
            ['B0b', 'Smith']
        );

        $this->assertGreaterThan(90.0, $score);
    }

    public function testCalculatesLowScoreForDifferentName(): void
    {
        $score = $this->matcher->calculateNameScore(
            'John Doe',
            'Smith, Bob',
            ['John', 'Doe']
        );

        $this->assertLessThan(50.0, $score);
    }

    public function testHandlesEmptyInput(): void
    {
        $result = $this->matcher->extractLegislatorName('');

        $this->assertSame('', $result['cleaned']);
        $this->assertEmpty($result['tokens']);
    }

    public function testHandlesNoisyOcr(): void
    {
        // Simulates noisy OCR: "....Bob Smith..."
        $result = $this->matcher->extractLegislatorName('....Bob Smith...');

        $this->assertStringContainsString('Bob', $result['cleaned']);
        $this->assertStringContainsString('Smith', $result['cleaned']);
    }

    public function testMatchesLastNameOnly(): void
    {
        // Extract "Delegate Watts" → "Watts"
        $extracted = $this->matcher->extractLegislatorName('Delegate Watts');

        // Should match against "Vivian Watts"
        $score = $this->matcher->calculateNameScore(
            $extracted['cleaned'],
            'Vivian Watts',
            $extracted['tokens']
        );

        $this->assertGreaterThanOrEqual(90.0, $score);
    }

    public function testMatchesLastNameOnlyWithSenatorPrefix(): void
    {
        // Extract "Senator Smith" → "Smith"
        $extracted = $this->matcher->extractLegislatorName('Senator Smith');

        // Should match against "Robert T. Smith"
        $score = $this->matcher->calculateNameScore(
            $extracted['cleaned'],
            'Robert T. Smith',
            $extracted['tokens']
        );

        $this->assertGreaterThanOrEqual(90.0, $score);
    }

    public function testMatchesLastNameWithOcrError(): void
    {
        // "Watts" with OCR error (5 instead of S)
        $score = $this->matcher->calculateNameScore(
            'Watt5',
            'Vivian Watts',
            ['Watt5']
        );

        $this->assertGreaterThanOrEqual(85.0, $score);
    }

    public function testLastNameOnlyDoesNotMatchDifferentLastName(): void
    {
        $extracted = $this->matcher->extractLegislatorName('Delegate Smith');

        // Should NOT match "John Doe"
        $score = $this->matcher->calculateNameScore(
            $extracted['cleaned'],
            'John Doe',
            $extracted['tokens']
        );

        $this->assertLessThan(50.0, $score);
    }
}
