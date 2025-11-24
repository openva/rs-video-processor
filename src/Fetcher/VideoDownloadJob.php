<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

class VideoDownloadJob
{
    public function __construct(
        public int $id,
        public string $chamber,
        public ?int $committeeId,
        public string $date,
        public string $remoteUrl,
        public array $metadata,
        public ?string $title
    ) {
    }

    public function isSenate(): bool
    {
        return strtolower($this->chamber) === 'senate';
    }
}
