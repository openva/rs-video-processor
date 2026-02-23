<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Classification;

use GdImage;
use RichmondSunlight\VideoProcessor\Analysis\Bills\OcrEngineInterface;

class FrameClassifier
{
    private const BRIGHTNESS_THRESHOLD = 50;
    private const DARK_PIXEL_RATIO = 0.5;
    private const SAMPLE_GRID_SIZE = 10;

    public function __construct(private OcrEngineInterface $ocr)
    {
    }

    /**
     * @return array{event_type: string, committee_name: ?string}
     */
    public function classify(string $imagePath, string $chamber): array
    {
        if (!$this->isTitleCard($imagePath)) {
            return ['event_type' => 'floor', 'committee_name' => null];
        }

        $text = $this->ocrFrame($imagePath);
        return $this->parseCommitteeText($text);
    }

    public function isTitleCard(string $imagePath): bool
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return false;
        }

        $src = @imagecreatefromjpeg($imagePath);
        if (!$src) {
            return false;
        }

        $width = imagesx($src);
        $height = imagesy($src);
        $darkPixels = 0;
        $totalSampled = 0;
        $stepX = max(1, (int) floor($width / self::SAMPLE_GRID_SIZE));
        $stepY = max(1, (int) floor($height / self::SAMPLE_GRID_SIZE));

        for ($x = 0; $x < $width; $x += $stepX) {
            for ($y = 0; $y < $height; $y += $stepY) {
                $rgb = imagecolorat($src, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $brightness = ($r + $g + $b) / 3;
                $totalSampled++;
                if ($brightness < self::BRIGHTNESS_THRESHOLD) {
                    $darkPixels++;
                }
            }
        }

        imagedestroy($src);

        if ($totalSampled === 0) {
            return false;
        }

        return ($darkPixels / $totalSampled) >= self::DARK_PIXEL_RATIO;
    }

    private function ocrFrame(string $imagePath): string
    {
        $preprocessed = $this->preprocessForOcr($imagePath);
        if ($preprocessed === null) {
            return $this->ocr->extractText($imagePath);
        }

        try {
            return $this->ocr->extractText($preprocessed);
        } finally {
            @unlink($preprocessed);
        }
    }

    private function preprocessForOcr(string $imagePath): ?string
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return null;
        }

        $src = @imagecreatefromjpeg($imagePath);
        if (!$src) {
            return null;
        }

        $width = imagesx($src);
        $height = imagesy($src);
        $scale = 3;
        $scaled = imagescale(
            $src,
            max(1, (int) ($width * $scale)),
            max(1, (int) ($height * $scale)),
            IMG_BICUBIC
        );
        if ($scaled !== false) {
            imagedestroy($src);
            $src = $scaled;
        }
        imagefilter($src, IMG_FILTER_GRAYSCALE);
        imagefilter($src, IMG_FILTER_CONTRAST, -35);
        imagefilter($src, IMG_FILTER_BRIGHTNESS, 15);

        $temp = tempnam(sys_get_temp_dir(), 'cls_') . '.jpg';
        imagejpeg($src, $temp, 95);
        imagedestroy($src);

        return $temp;
    }

    /**
     * @return array{event_type: string, committee_name: ?string}
     */
    private function parseCommitteeText(string $text): array
    {
        $lower = strtolower($text);

        if (str_contains($lower, 'subcommittee')) {
            $eventType = 'subcommittee';
        } else {
            $eventType = 'committee';
        }

        $name = $this->extractCommitteeName($text);

        return ['event_type' => $eventType, 'committee_name' => $name];
    }

    private function extractCommitteeName(string $text): ?string
    {
        $lines = preg_split('/\r?\n/', $text);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, static fn (string $line) => $line !== '');

        $boilerplate = [
            'the meeting will begin shortly',
            'house of delegates',
            'virginia',
            'commonwealth',
        ];

        $candidates = [];
        foreach ($lines as $line) {
            $lower = strtolower($line);
            $isBoilerplate = false;
            foreach ($boilerplate as $phrase) {
                if (str_contains($lower, $phrase)) {
                    $isBoilerplate = true;
                    break;
                }
            }
            // Skip date-like lines
            if (preg_match('/^\w+day,?\s+\w+\s+\d{1,2}/', $line)) {
                $isBoilerplate = true;
            }
            // Skip lines that are just a time
            if (preg_match('/^\d{1,2}:\d{2}\s*(a\.?m\.?|p\.?m\.?)?$/i', $line)) {
                $isBoilerplate = true;
            }
            if (!$isBoilerplate && strlen($line) >= 4) {
                $candidates[] = $line;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // Prefer lines containing "committee" or "subcommittee"
        foreach ($candidates as $candidate) {
            if (preg_match('/committee/i', $candidate)) {
                return $this->cleanCommitteeName($candidate);
            }
        }

        // Otherwise return the longest candidate as most likely the committee name
        usort($candidates, static fn (string $a, string $b) => strlen($b) - strlen($a));
        return $this->cleanCommitteeName($candidates[0]);
    }

    private function cleanCommitteeName(string $name): string
    {
        // Remove "House" prefix
        $name = preg_replace('/^\s*House\s+/i', '', $name);
        // Remove leading "Committee on" or "Subcommittee on"
        $name = preg_replace('/^(Sub)?Committee\s+on\s+/i', '', $name);
        return trim($name);
    }
}
