<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use Log;
use RichmondSunlight\VideoProcessor\Analysis\CropSignature;

class BillDetectionProcessor
{
    public function __construct(
        private ScreenshotManifestLoader $manifestLoader,
        private ScreenshotFetcher $screenshotFetcher,
        private BillTextExtractor $textExtractor,
        private BillParser $parser,
        private BillResultWriter $writer,
        private ChamberConfig $chamberConfig,
        private AgendaExtractor $agendaExtractor,
        private ?Log $logger = null,
        private int $frameStep = 1
    ) {
    }

    /**
     * @return bool true when detection finished (bills or a none-found sentinel
     *         were written); false when it could not finish and should be retried
     */
    public function process(BillDetectionJob $job): bool
    {
        // Get a fresh DB connection before each job — bill detection takes minutes
        // (downloading + OCR on every screenshot) and the connection times out.
        $this->writer->reconnect();

        // The job queue inserted a claim placeholder (raw_text='/pending',
        // ignored='y') when it fetched this job. On early failure we return
        // WITHOUT touching it: the claim blocks immediate re-fetch, and
        // StaleClaimCleaner releases it after the stale-claim threshold for a retry.

        if (!$job->manifestUrl) {
            $this->logger?->put('No manifest available for file #' . $job->fileId . '; leaving claim for later retry.', 4);
            return false;
        }

        try {
            $manifest = $this->manifestLoader->load($job->manifestUrl);
        } catch (\Throwable $e) {
            $this->logger?->put('Manifest load failed for file #' . $job->fileId . ': ' . $e->getMessage() . '; leaving claim for later retry.', 4);
            return false;
        }

        $crop = $this->chamberConfig->getCrop($job->chamber, $job->eventType, $job->date);
        if (!$crop) {
            $this->logger?->put('No crop configuration for chamber ' . $job->chamber . '; leaving claim for later retry.', 4);
            return false;
        }

        $agenda = $this->agendaExtractor->extract($job->metadata);

        // OCR every screenshot, tolerating per-frame failures — a corrupt frame
        // or a transient S3 fetch error must not abort the whole job. Results are
        // collected and only committed after the loop, so the placeholder claim
        // is never cleared unless we have a usable result. Clearing it before the
        // loop (as this once did) meant a mid-loop exception could either mark the
        // video "done" with partial data or wipe the claim and lose the retry.
        $collected = [];
        $attempted = 0;
        $failed = 0;
        $missingInARow = 0;
        $firstMissing = '';
        // Read every Nth frame. A bill number stays on screen for many seconds,
        // so skipping frames costs little accuracy and saves most of the OCR time.
        $step = max(1, $this->frameStep);

        // The last frame actually OCRed, and what it said. Frames that look the
        // same reuse that reading; see CropSignature.
        $lastSignature = null;
        $lastText = '';
        $ocrRuns = 0;

        $entries = [];
        foreach (array_values($manifest) as $index => $entry) {
            if ($index % $step === 0) {
                $entries[] = $entry;
            }
        }

        foreach ($entries as $position => $entry) {
            // Download the next batch of frames all at once, rather than each
            // one as it comes up.
            $batch = ScreenshotFetcher::PREFETCH_CONCURRENCY;
            if ($position % $batch === 0) {
                $this->screenshotFetcher->prefetch(array_column(array_slice($entries, $position, $batch), 'full'));
            }
            $attempted++;
            try {
                $imagePath = $this->screenshotFetcher->fetch($entry['full']);
                try {
                    $signature = CropSignature::of($imagePath, $crop);
                    if (CropSignature::changed($lastSignature, $signature)) {
                        $lastSignature = null;
                        $lastText = $this->textExtractor->extract($job->chamber, $imagePath, $crop);
                        $lastSignature = $signature;
                        $ocrRuns++;
                    }
                    $text = $lastText;
                } finally {
                    @unlink($imagePath);
                }
                $missingInARow = 0;
            } catch (ScreenshotMissingException $e) {
                $failed++;
                $firstMissing = $missingInARow === 0 ? $entry['full'] : $firstMissing;
                if (++$missingInARow >= ScreenshotMissingException::LIMIT_IN_A_ROW) {
                    $this->screenshotFetcher->discardPrefetched();
                    throw ScreenshotMissingException::givingUp('file #' . $job->fileId, $missingInARow, $firstMissing);
                }
                continue;
            } catch (\Throwable $e) {
                $this->logger?->put('Screenshot processing failed for file #' . $job->fileId . ' (' . $entry['full'] . '): ' . $e->getMessage(), 4);
                $failed++;
                $missingInARow = 0;
                continue;
            }
            $bills = $this->parser->parse($text);
            if (empty($bills) && !empty($agenda)) {
                $bills = $this->matchAgenda($agenda, $entry['timestamp']);
            }
            $collected[] = [
                'timestamp' => $entry['timestamp'],
                'bills' => $bills,
                'screenshot' => basename($entry['full']),
            ];
        }

        $this->screenshotFetcher->discardPrefetched();

        $this->logger?->put(
            sprintf('Bill OCR for file #%d ran on %d of %d frames read; the rest were unchanged.', $job->fileId, $ocrRuns, $attempted),
            3
        );

        $totalBills = 0;
        foreach ($collected as $item) {
            $totalBills += count($item['bills']);
        }

        // If we found no bills AND some screenshots failed, we did not actually
        // get to look at the whole video — do NOT finalize it as "none found".
        // Leave the claim placeholder intact so StaleClaimCleaner releases it for
        // a bounded retry (this also covers the all-screenshots-failed case).
        if ($totalBills === 0 && $failed > 0) {
            $this->logger?->put(
                'Bill detection incomplete for file #' . $job->fileId
                . ' (' . $failed . '/' . $attempted . ' screenshots failed, no bills found); leaving claim for later retry.',
                4
            );
            return false;
        }

        // Commit: clear the placeholder (and any stale results) and write fresh.
        // Reconnect first — the OCR loop above does no DB work and can run for
        // hours on a long video, so the connection from the top of process()
        // is almost certainly dead by now ("MySQL server has gone away").
        $this->writer->reconnect();
        $this->writer->clearExisting($job->fileId);
        foreach ($collected as $item) {
            $this->writer->record($job->fileId, $item['timestamp'], $item['bills'], $item['screenshot']);
        }

        if ($totalBills === 0) {
            // Every screenshot was processed and genuinely no bills were found
            // (or the manifest was empty). Record a terminal sentinel so this
            // video isn't re-OCRed on every future pass.
            $reason = $attempted === 0 ? 'manifest was empty' : 'processed ' . $attempted . ' screenshot(s), found none';
            $this->writer->recordNoneFound($job->fileId);
            $this->logger?->put('No bills detected for file #' . $job->fileId . ' (' . $reason . '); recorded none-found sentinel.', 3);
            return true;
        }

        $this->logger?->put(
            'Finished bill detection for file #' . $job->fileId
            . ($failed > 0 ? ' (' . $failed . '/' . $attempted . ' screenshots failed, kept partial results)' : ''),
            3
        );

        return true;
    }

    private function matchAgenda(array $agenda, int $timestamp): array
    {
        $closest = null;
        foreach ($agenda as $item) {
            $time = (int) $item['time'];
            if ($closest === null || abs($timestamp - $time) < abs($timestamp - $closest['time'])) {
                $closest = $item;
            }
        }
        return $closest ? [$closest['bill']] : [];
    }
}
