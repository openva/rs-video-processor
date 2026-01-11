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
            $name = (new \ReflectionClass($scraper))->getShortName();
            echo "Starting {$name}...\n";
            $scraped = $scraper->scrape();
            echo "{$name} complete: " . count($scraped) . " videos found\n";
            $records = array_merge($records, $scraped);
        }

        $path = $this->writer->write($records);

        if ($this->logger) {
            $this->logger->put(sprintf('Scraped %d videos. Stored at %s', count($records), $path), 3);
        }

        return $path;
    }
}
