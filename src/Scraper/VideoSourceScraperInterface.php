<?php

namespace RichmondSunlight\VideoProcessor\Scraper;

interface VideoSourceScraperInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function scrape(): array;
}
