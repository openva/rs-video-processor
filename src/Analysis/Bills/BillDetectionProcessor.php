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
        $manifest = $this->manifestLoader->load($job->manifestUrl);
        $crop = $this->chamberConfig->getCrop($job->chamber, $job->eventType);
        if (!$crop) {
            $this->logger?->put('No crop configuration for chamber ' . $job->chamber, 4);
            return;
        }

        $agenda = $this->agendaExtractor->extract($job->metadata);

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
            $this->writer->record($job->fileId, $entry['timestamp'], $bills);
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
