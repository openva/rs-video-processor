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

        // OCR is about to run: clear the placeholder and any stale results.
        $this->writer->clearExisting($job->fileId);

        $agenda = $this->agendaExtractor->extract($job->metadata);

        $recorded = 0;
        foreach ($manifest as $entry) {
            $imagePath = $this->screenshotFetcher->fetch($entry['full']);
            try {
                $text = $this->textExtractor->extract($job->chamber, $imagePath, $crop);
            } finally {
                @unlink($imagePath);
            }
            $bills = $this->parser->parse($text);
            if (empty($bills) && !empty($agenda)) {
                $bills = $this->matchAgenda($agenda, $entry['timestamp']);
            }
            $screenshotFilename = basename($entry['full']);
            $this->writer->record($job->fileId, $entry['timestamp'], $bills, $screenshotFilename);
            $recorded += count($bills);
        }

        if ($recorded === 0) {
            // OCR genuinely found nothing. Record a terminal sentinel so this
            // video isn't re-OCRed on every future pass.
            $this->writer->recordNoneFound($job->fileId);
            $this->logger?->put('No bills detected for file #' . $job->fileId . '; recorded none-found sentinel.', 3);
            return;
        }

        $this->logger?->put('Finished bill detection for file #' . $job->fileId, 3);
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
