<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use Log;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;

class OcrSpeakerExtractor
{
    public function __construct(
        private ScreenshotManifestLoader $manifestLoader,
        private ScreenshotFetcher $screenshotFetcher,
        private SpeakerTextExtractor $textExtractor,
        private SpeakerNameParser $parser,
        private SpeakerChamberConfig $chamberConfig,
        private int $minSegmentSeconds = 3,
        private ?Log $logger = null
    ) {
    }

    /**
     * @return array<int,array{name:string,start:float}>
     */
    public function extract(string $manifestUrl, string $chamber, string $eventType): array
    {
        $crop = $this->chamberConfig->getCrop($chamber, $eventType);
        if (!$crop) {
            $this->logger?->put(sprintf('No OCR crop config for %s (%s).', $chamber, $eventType), 4);
            return [];
        }

        $manifest = $this->manifestLoader->load($manifestUrl);
        if (empty($manifest)) {
            return [];
        }

        $segments = [];
        $currentName = null;
        $currentStart = null;
        $currentLast = null;
        $currentNormalized = null;

        foreach ($manifest as $entry) {
            $timestamp = (int) ($entry['timestamp'] ?? 0);
            $imageUrl = (string) ($entry['full'] ?? '');
            if ($imageUrl === '') {
                continue;
            }

            $imagePath = null;
            try {
                $imagePath = $this->screenshotFetcher->fetch($imageUrl);
                $rawText = $this->textExtractor->extract($chamber, $imagePath, $crop);
            } catch (\Throwable $e) {
                $this->logger?->put('OCR screenshot processing failed: ' . $e->getMessage(), 4);
                if ($imagePath) {
                    @unlink($imagePath);
                }
                continue;
            }

            if ($imagePath) {
                @unlink($imagePath);
            }

            $name = $this->parser->parse($rawText);
            if ($name === null) {
                continue;
            }

            $normalized = $this->normalizeName($name);
            if ($currentName === null) {
                $currentName = $name;
                $currentNormalized = $normalized;
                $currentStart = $timestamp;
                $currentLast = $timestamp;
                continue;
            }

            if ($normalized === $currentNormalized) {
                $currentLast = $timestamp;
                continue;
            }

            $this->commitSegment($segments, $currentName, $currentStart, $currentLast);
            $currentName = $name;
            $currentNormalized = $normalized;
            $currentStart = $timestamp;
            $currentLast = $timestamp;
        }

        $this->commitSegment($segments, $currentName, $currentStart, $currentLast);

        return $segments;
    }

    /**
     * @param array<int,array{name:string,start:float}> $segments
     */
    private function commitSegment(array &$segments, ?string $name, ?int $start, ?int $last): void
    {
        if ($name === null || $start === null || $last === null) {
            return;
        }

        $duration = ($last - $start) + 1;
        if ($duration < $this->minSegmentSeconds) {
            return;
        }

        $segments[] = [
            'name' => $name,
            'start' => (float) $start,
        ];
    }

    private function normalizeName(string $name): string
    {
        $value = strtolower(trim($name));
        $value = preg_replace('/\s+/', ' ', $value);
        return $value;
    }
}
