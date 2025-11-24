<?php

namespace RichmondSunlight\VideoProcessor\Scraper;

use Log;

class VideoScraper
{
    /**
     * @param VideoSourceScraperInterface[] $scrapers
     */
    public function __construct(
        private array $scrapers,
        private FilesystemMetadataWriter $writer,
        private ?Log $logger = null
    ) {
    }

    public function run(): string
    {
        $records = [];
        foreach ($this->scrapers as $scraper) {
            $records = array_merge($records, $scraper->scrape());
        }

        $path = $this->writer->write($records);

        if ($this->logger) {
            $this->logger->put(sprintf('Scraped %d videos. Stored at %s', count($records), $path), 3);
        }

        return $path;
    }
}
