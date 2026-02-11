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

        $crop = $this->chamberConfig->getCrop($job->chamber, $job->eventType, $job->date);
        if (!$crop) {
            $this->logger?->put('No crop configuration for chamber ' . $job->chamber, 4);
            return;
        }

        $agenda = $this->agendaExtractor->extract($job->metadata);

        $this->writer->clearExisting($job->fileId);

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
