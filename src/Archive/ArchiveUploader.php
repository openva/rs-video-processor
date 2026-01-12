<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use Log;
use RuntimeException;

class ArchiveUploader
{
    public function __construct(private ?Log $logger = null)
    {
    }

    public function ensureConfigExists(): void
    {
        $path = getenv('HOME') . '/.config/internetarchive/ia.ini';
        if (!file_exists($path)) {
            $this->logger?->put('Internet Archive configuration (' . $path . ') missing. Run `ia configure`.', 6);
            throw new RuntimeException('IA configuration missing.');
        }
    }
}
