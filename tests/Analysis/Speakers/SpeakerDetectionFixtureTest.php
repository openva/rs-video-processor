<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Speakers;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillTextExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\TesseractOcrEngine;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerChamberConfig;

class SpeakerDetectionFixtureTest extends TestCase
{
    private const FIXTURES = [
        'house-floor' => [
            'path' => __DIR__ . '/../../fixtures/house-floor.mp4',
            'expected' => [
                '00:00:45' => 'Del. Marcus B. Simon',
                '00:02:00' => 'Del. Debra D. Gardner',
                '00:03:00' => 'Del. Jackie Hope Glass',
                '00:03:30' => 'Del. C. Todd Gilbert',
                '00:04:00' => 'Del. Joseph P. McNamara',
                '00:04:30' => 'Del. Josh E. Thomas',
                '00:05:15' => 'Del. Candi Mundon King',
                '00:07:15' => 'Del. Israel D. O\'Quinn',
                '00:08:30' => 'Del. Sam Rasoul',
                '00:09:30' => 'Del. Laura Jane Cohen',
                '00:10:30' => 'Del. Candi Mundon King',
            ],
        ],
        'senate-floor' => [
            'path' => __DIR__ . '/../../fixtures/senate-floor.mp4',
            'expected' => [
                '00:00:30' => 'Senator Richard H. Stuart',
                '00:01:40' => 'Senator Danica A. Roem',
                '00:04:02' => 'Senator Stella G. Pekarsky',
                '00:08:01' => 'Senator Adam P. Ebbin',
                '00:09:53' => 'Senator Ghazala F. Hashmi',
            ],
        ],
        'senate-committee' => [
            'path' => __DIR__ . '/../../fixtures/senate-committee.mp4',
            'expected' => [
                '00:00:12' => 'Senator David R. Suetterlein',
                '00:00:50' => 'Senator Ghazala F. Hashmi',
                '00:01:47' => 'Senator Ghazala F. Hashmi',
                '00:02:50' => 'Senator Ghazala F. Hashmi',
                '00:03:45' => 'Senator Ghazala F. Hashmi',
                '00:04:30' => 'Senator Stella G. Pekarsky',
            ],
        ],
    ];

    public function testHouseFloorSpeakers(): void
    {
        $this->assertFixture('house-floor', 'house', 'floor');
    }

    public function testSenateFloorSpeakers(): void
    {
        $this->assertFixture('senate-floor', 'senate', 'floor');
    }

    public function testSenateCommitteeSpeakers(): void
    {
        $this->assertFixture('senate-committee', 'senate', 'committee');
    }

    private function assertFixture(string $key, string $chamber, string $event): void
    {
        $fixture = self::FIXTURES[$key];
        if (!file_exists($fixture['path'])) {
            $this->markTestSkipped(sprintf('Missing fixture video for %s. Run bin/fetch_test_fixtures.php.', $key));
        }
        if (empty($fixture['expected'])) {
            $this->markTestIncomplete(sprintf('Populate expected speaker names for %s.', $key));
        }
        $this->requireBinary('ffmpeg');
        $this->requireBinary('tesseract');

        $config = (new SpeakerChamberConfig())->getCrop($chamber, $event);
        if (!$config) {
            $this->fail(sprintf('No crop config for %s (%s)', $chamber, $event));
        }

        $extractor = new BillTextExtractor(new TesseractOcrEngine());

        foreach ($fixture['expected'] as $timestamp => $name) {
            $frame = $this->captureFrame($fixture['path'], $timestamp);
            try {
                $text = $extractor->extract($chamber, $frame, $config);
            } finally {
                @unlink($frame);
            }
            $this->assertNameDetected($name, $text, $key, $timestamp);
        }
    }

    private function captureFrame(string $video, string $timestamp): string
    {
        $output = tempnam(sys_get_temp_dir(), 'speaker_frame_') . '.jpg';
        $cmd = sprintf(
            'ffmpeg -y -loglevel error -ss %s -i %s -frames:v 1 %s',
            escapeshellarg($timestamp),
            escapeshellarg($video),
            escapeshellarg($output)
        );
        exec($cmd, $out, $status);
        if ($status !== 0 || !file_exists($output)) {
            throw new \RuntimeException(sprintf('Unable to capture frame at %s (%s)', $timestamp, $video));
        }
        return $output;
    }

    private function assertNameDetected(string $expected, string $actualText, string $fixture, string $timestamp): void
    {
        $normalizedExpected = $this->normalizeName($expected);
        $normalizedActual = $this->normalizeName($actualText);
        $this->assertNotEmpty(
            $normalizedActual,
            sprintf('OCR produced no text for %s at %s', $fixture, $timestamp)
        );
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

    private function normalizeName(string $value): string
    {
        $value = strtoupper($value);
        $value = preg_replace('/\b(DEL|SEN|DELEGATE|SENATOR|CHAIR|VICECHAIR)\b/', '', $value);
        $value = preg_replace('/[^A-Z]/', '', $value);
        return $value;
    }

    private function requireBinary(string $binary): void
    {
        exec('command -v ' . escapeshellarg($binary), $output, $status);
        if ($status !== 0) {
            $this->markTestSkipped(sprintf('%s is required for speaker OCR tests.', $binary));
        }
    }
}
