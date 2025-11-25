<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;

/**
 * Verify real caption content for each chamber/source.
 */
class CaptionContentTest extends TestCase
{
    private const SENATE_CAPTIONS = __DIR__ . '/../fixtures/senate.vtt';
    private const HOUSE_CAPTIONS = __DIR__ . '/../fixtures/house.json';

    private string $expectedSenateSnippet = 'have all gotten vaccinations';
    private string $expectedHouseSnippet = 'Supplemental Nutrition';

    public function testSenateCaptionsContainExpectedText(): void
    {
        $this->assertFixtureExists(self::SENATE_CAPTIONS, 'senate');
        $this->assertSnippetDefined($this->expectedSenateSnippet, 'senate');

        $segments = $this->parse(self::SENATE_CAPTIONS);
        $this->assertCaptionContains($segments, $this->expectedSenateSnippet, 'senate');
    }

    public function testHouseCaptionsContainExpectedText(): void
    {
        $this->assertFixtureExists(self::HOUSE_CAPTIONS, 'house floor');
        $this->assertSnippetDefined($this->expectedHouseSnippet, 'house floor');

        $segments = $this->parse(self::HOUSE_CAPTIONS);
        $this->assertCaptionContains($segments, $this->expectedHouseSnippet, 'house floor');
    }

    /**
     * @return array<int,array{start:float,end:float,text:string}>
     */
    private function parse(string $path): array
    {
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Failed to read caption file at {$path}");

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'json') {
            return $this->parseJsonCaptions($contents, $path);
        }

        $parser = new CaptionParser();
        return $parser->parseWebVtt($contents);
    }

    /**
     * @param array<int,array{start:float,end:float,text:string}> $segments
     */
    private function assertCaptionContains(array $segments, string $expectedSnippet, string $label): void
    {
        $this->assertNotEmpty($segments, "No caption segments parsed for {$label}.");
        $fullText = implode(' ', array_map(static fn ($seg) => $seg['text'], $segments));
        $this->assertStringContainsString($expectedSnippet, $fullText, "Caption text did not contain expected snippet for {$label}.");
    }

    private function assertFixtureExists(string $path, string $label): void
    {
        if (!file_exists($path)) {
            $this->markTestSkipped("Provide a caption file for {$label} at {$path} (VTT or House JSON).");
        }
    }

    private function assertSnippetDefined(string $snippet, string $label): void
    {
        if (str_starts_with($snippet, '<<<FILL ')) {
            $this->markTestIncomplete("Set the expected {$label} caption snippet before running this test.");
        }
    }

    /**
     * Parse House ccItems-style JSON into segments.
     *
     * @return array<int,array{start:float,end:float,text:string}>
     */
    private function parseJsonCaptions(string $json, string $path): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $this->fail("Caption JSON at {$path} did not decode to an array.");
        }

        // Unwrap language key if present.
        if ($this->isAssoc($decoded)) {
            $decoded = $decoded['en'] ?? array_values($decoded)[0] ?? [];
        }

        if (!is_array($decoded)) {
            $this->fail("Caption JSON at {$path} was not an array of entries.");
        }

        $segments = [];
        $firstStart = null;
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !isset($entry['Begin'], $entry['End'], $entry['Content'])) {
                continue;
            }
            $begin = strtotime($entry['Begin']);
            $end = strtotime($entry['End']);
            if ($begin === false || $end === false) {
                continue;
            }
            $firstStart ??= $begin;
            $segments[] = [
                'start' => $begin - $firstStart,
                'end' => $end - $firstStart,
                'text' => trim((string) $entry['Content']),
            ];
        }

        return $segments;
    }

    private function isAssoc(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
