<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Bills;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillTextExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ChamberConfig;
use RichmondSunlight\VideoProcessor\Analysis\Bills\TesseractOcrEngine;

class BillDetectionFixtureTest extends TestCase
{
    /**
     * Sample timestamps → bill numbers to verify per video.
     */
    private const FIXTURES = [
        'house-floor' => [
            'path' => __DIR__ . '/../../fixtures/house-floor.mp4',
            'chamber' => 'house',
            'event_type' => 'floor',
            'expected' => [
                '00:00:43' => 'HB 2419',
                '00:00:50' => 'HB 1979',
                '00:01:27' => 'HB 2340',
                '00:02:43' => 'HB 2021',
                '00:06:33' => 'HB 2006',
            ],
        ],
        'house-committee' => [
            'path' => __DIR__ . '/../../fixtures/house-committee.mp4',
            'chamber' => 'house',
            'event_type' => 'committee',
            'expected' => [
                '00:00:18' => 'HB 1829',
                '00:00:51' => 'HB 2601',
                '00:01:30' => 'HB 1641',
                '00:02:08' => 'HB 1723',
            ],
        ],
        'senate-floor' => [
            'path' => __DIR__ . '/../../fixtures/senate-floor.mp4',
            'chamber' => 'senate',
            'event_type' => 'floor',
            'expected' => [
                '00:00:07' => 'S.B. 956',
                '00:01:30' => 'S.B. 1017',
                '00:03:42' => 'S.B. 1032',
                '00:05:56' => 'S.B. 1048',
            ],
        ],
        'senate-committee' => [
            'path' => __DIR__ . '/../../fixtures/senate-committee.mp4',
            'chamber' => 'senate',
            'event_type' => 'committee',
            'expected' => [
                '00:00:01' => 'HB 1806',
                '00:01:00' => 'HB 1899',
                '00:01:45' => 'HB 1905',
                '00:02:15' => 'HB 1930',
            ],
        ],
    ];

    public function testHouseFloorBills(): void
    {
        $this->assertFixture('house-floor');
    }

    public function testHouseCommitteeBills(): void
    {
        $this->assertFixture('house-committee');
    }

    public function testSenateFloorBills(): void
    {
        $this->assertFixture('senate-floor');
    }

    public function testSenateCommitteeBills(): void
    {
        $this->assertFixture('senate-committee');
    }

    private function assertFixture(string $key): void
    {
        $fixture = self::FIXTURES[$key];
        if (empty($fixture['expected'])) {
            $this->markTestIncomplete(sprintf('Populate expected bill numbers for %s', $key));
        }
        if (!file_exists($fixture['path'])) {
            $this->markTestSkipped(sprintf('Missing fixture video for %s. Run bin/fetch_test_fixtures.php.', $key));
        }
        $this->requireBinary('ffmpeg');
        $this->requireBinary('tesseract');

        $crop = (new ChamberConfig())->getCrop($fixture['chamber'], $fixture['event_type']);
        if (!$crop) {
            $this->fail(sprintf('No crop config for %s (%s)', $fixture['chamber'], $fixture['event_type']));
        }

        $extractor = new BillTextExtractor(new TesseractOcrEngine());

        foreach ($fixture['expected'] as $timestamp => $bill) {
            $framePath = $this->captureFrame($fixture['path'], $timestamp);
            try {
                $text = $extractor->extract($fixture['chamber'], $framePath, $crop);
            } finally {
                @unlink($framePath);
            }
            $this->assertBillDetected($bill, $text, $key, $timestamp);
        }
    }

    private function captureFrame(string $video, string $timestamp): string
    {
        $output = tempnam(sys_get_temp_dir(), 'bill_frame_') . '.jpg';
        $cmd = sprintf(
            'ffmpeg -y -loglevel error -ss %s -i %s -frames:v 1 %s',
            escapeshellarg($timestamp),
            escapeshellarg($video),
            escapeshellarg($output)
        );
        exec($cmd, $outputLines, $status);
        if ($status !== 0 || !file_exists($output)) {
            throw new \RuntimeException(sprintf('Unable to capture frame at %s (%s)', $timestamp, $video));
        }
        return $output;
    }

    private function assertBillDetected(string $expected, string $actualText, string $fixture, string $timestamp): void
    {
        $normalizedExpected = strtoupper(preg_replace('/\s+/', '', $expected));
        $normalizedActual = strtoupper(preg_replace('/\s+/', '', $actualText));
        $this->assertStringContainsString(
            $normalizedExpected,
            $normalizedActual,
            sprintf(
                'Expected %s in %s at %s, OCR output: "%s"',
                $expected,
                $fixture,
                $timestamp,
                $actualText
            )
        );
    }

    private function requireBinary(string $binary): void
    {
        exec('command -v ' . escapeshellarg($binary), $out, $status);
        if ($status !== 0) {
            $this->markTestSkipped(sprintf('%s is required for bill detection fixture tests.', $binary));
        }
    }
}
