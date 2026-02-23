<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Classification;

use Log;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;

class ClassificationVerificationProcessor
{
    private const MAX_FRAMES_TO_TRY = 3;

    public function __construct(
        private ScreenshotManifestLoader $manifestLoader,
        private ScreenshotFetcher $screenshotFetcher,
        private FrameClassifier $frameClassifier,
        private ClassificationCorrectionWriter $writer,
        private CommitteeDirectory $committeeDirectory,
        private ?Log $logger = null
    ) {
    }

    public function process(ClassificationVerificationJob $job): void
    {
        if (!$job->manifestUrl) {
            $this->logger?->put('No manifest available for file #' . $job->fileId, 4);
            return;
        }

        try {
            $manifest = $this->manifestLoader->load($job->manifestUrl);
        } catch (\Throwable $e) {
            $this->logger?->put('Manifest load failed for file #' . $job->fileId . ': ' . $e->getMessage(), 4);
            return;
        }

        if (empty($manifest)) {
            $this->logger?->put('Empty manifest for file #' . $job->fileId, 4);
            return;
        }

        $result = null;
        $framesToTry = min(self::MAX_FRAMES_TO_TRY, count($manifest));
        for ($i = 0; $i < $framesToTry; $i++) {
            $imagePath = $this->screenshotFetcher->fetch($manifest[$i]['full']);
            try {
                $result = $this->frameClassifier->classify($imagePath, $job->chamber);
            } catch (\Throwable $e) {
                $this->logger?->put('Frame classification failed for file #' . $job->fileId . ' frame ' . $i . ': ' . $e->getMessage(), 4);
                continue;
            } finally {
                @unlink($imagePath);
            }
            // Accept the result if we got one
            if ($result !== null) {
                break;
            }
        }

        if ($result === null) {
            $this->writer->markVerified($job->fileId, $job->videoIndexCache);
            return;
        }

        $detectedType = $result['event_type'];

        if ($detectedType === $job->currentEventType) {
            $this->writer->markVerified($job->fileId, $job->videoIndexCache);
            $this->logger?->put('Classification confirmed for file #' . $job->fileId . ': ' . $detectedType, 3);
            return;
        }

        // Classification mismatch detected — attempt correction
        if ($detectedType === 'floor') {
            $title = ucfirst($job->chamber) . ' Session';
            $this->writer->correct($job->fileId, null, $title, $detectedType, $job->videoIndexCache);
            $this->logger?->put(
                'Classification corrected for file #' . $job->fileId . ': '
                . $job->currentEventType . ' → floor',
                5
            );
            return;
        }

        // Committee or subcommittee — resolve the name
        $committeeName = $result['committee_name'];
        if ($committeeName === null) {
            $this->writer->markVerified($job->fileId, $job->videoIndexCache);
            $this->logger?->put(
                'Classification mismatch for file #' . $job->fileId
                . ' but no committee name extracted, skipping correction',
                4
            );
            return;
        }

        $entry = $this->committeeDirectory->matchEntry($committeeName, $job->chamber, $detectedType);
        if ($entry === null) {
            $this->writer->markVerified($job->fileId, $job->videoIndexCache);
            $this->logger?->put(
                'Classification mismatch for file #' . $job->fileId
                . ' but committee "' . $committeeName . '" not found in directory, skipping correction',
                4
            );
            return;
        }

        $title = ucfirst($job->chamber) . ' ' . $entry['name'];
        $this->writer->correct($job->fileId, $entry['id'], $title, $detectedType, $job->videoIndexCache);
        $this->logger?->put(
            'Classification corrected for file #' . $job->fileId . ': '
            . $job->currentEventType . ' → ' . $detectedType
            . ' (name=' . $entry['name'] . ')',
            5
        );
    }
}
