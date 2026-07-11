<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use Log;

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
        private ?Log $logger = null
    ) {
    }

    public function process(BillDetectionJob $job): void
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
            return;
        }

        try {
            $manifest = $this->manifestLoader->load($job->manifestUrl);
        } catch (\Throwable $e) {
            $this->logger?->put('Manifest load failed for file #' . $job->fileId . ': ' . $e->getMessage() . '; leaving claim for later retry.', 4);
            return;
        }

        $crop = $this->chamberConfig->getCrop($job->chamber, $job->eventType, $job->date);
        if (!$crop) {
            $this->logger?->put('No crop configuration for chamber ' . $job->chamber . '; leaving claim for later retry.', 4);
            return;
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
        foreach ($manifest as $entry) {
            $attempted++;
            try {
                $imagePath = $this->screenshotFetcher->fetch($entry['full']);
                try {
                    $text = $this->textExtractor->extract($job->chamber, $imagePath, $crop);
                } finally {
                    @unlink($imagePath);
                }
            } catch (\Throwable $e) {
                $this->logger?->put('Screenshot processing failed for file #' . $job->fileId . ' (' . $entry['full'] . '): ' . $e->getMessage(), 4);
                $failed++;
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
            return;
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
            return;
        }

        $this->logger?->put(
            'Finished bill detection for file #' . $job->fileId
            . ($failed > 0 ? ' (' . $failed . '/' . $attempted . ' screenshots failed, kept partial results)' : ''),
            3
        );
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
