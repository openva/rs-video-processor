<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use Log;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotMissingException;
use RichmondSunlight\VideoProcessor\Analysis\CropSignature;

class OcrSpeakerExtractor
{
    public function __construct(
        private ScreenshotManifestLoader $manifestLoader,
        private ScreenshotFetcher $screenshotFetcher,
        private SpeakerTextExtractor $textExtractor,
        private SpeakerNameParser $parser,
        private SpeakerChamberConfig $chamberConfig,
        private int $minSegmentSeconds = 3,
        private ?Log $logger = null,
        private int $frameStep = 1
    ) {
    }

    /**
     * @return array<int,array{name:string,start:float}>
     */
    public function extract(string $manifestUrl, string $chamber, string $eventType, string $date = '2020-01-01'): array
    {
        $crop = $this->chamberConfig->getCrop($chamber, $eventType, $date);
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

        // Read every Nth frame. A name has to stay on screen for
        // $minSegmentSeconds to count, so skipping alternate frames loses little.
        $step = max(1, $this->frameStep);

        // The last frame actually OCRed, and what it said. Frames that look the
        // same reuse that reading; see CropSignature.
        $lastSignature = null;
        $lastText = '';
        $read = 0;
        $ocrRuns = 0;

        $entries = [];
        foreach (array_values($manifest) as $index => $entry) {
            if ($index % $step === 0) {
                $entries[] = $entry;
            }
        }

        $missingInARow = 0;
        $firstMissing = '';
        foreach ($entries as $position => $entry) {
            // Download the next batch of frames all at once, rather than each
            // one as it comes up.
            $batch = ScreenshotFetcher::PREFETCH_CONCURRENCY;
            if ($position % $batch === 0) {
                $this->screenshotFetcher->prefetch(array_column(array_slice($entries, $position, $batch), 'full'));
            }
            $timestamp = (int) ($entry['timestamp'] ?? 0);
            $imageUrl = (string) ($entry['full'] ?? '');
            if ($imageUrl === '') {
                continue;
            }

            $imagePath = null;
            try {
                $imagePath = $this->screenshotFetcher->fetch($imageUrl);
                $read++;
                $signature = CropSignature::of($imagePath, $crop);
                if (CropSignature::changed($lastSignature, $signature)) {
                    $lastSignature = null;
                    $lastText = $this->textExtractor->extract($chamber, $imagePath, $crop);
                    $lastSignature = $signature;
                    $ocrRuns++;
                }
                $rawText = $lastText;
                $missingInARow = 0;
            } catch (ScreenshotMissingException $e) {
                $firstMissing = $missingInARow === 0 ? $imageUrl : $firstMissing;
                if (++$missingInARow >= ScreenshotMissingException::LIMIT_IN_A_ROW) {
                    $this->screenshotFetcher->discardPrefetched();
                    throw ScreenshotMissingException::givingUp('the video at ' . $manifestUrl, $missingInARow, $firstMissing);
                }
                continue;
            } catch (\Throwable $e) {
                $missingInARow = 0;
                $this->logger?->put('OCR screenshot processing failed for ' . $imageUrl . ': ' . $e->getMessage(), 4);
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
        $this->screenshotFetcher->discardPrefetched();

        $this->logger?->put(sprintf('Speaker OCR ran on %d of %d frames read; the rest were unchanged.', $ocrRuns, $read), 3);

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
