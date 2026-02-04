<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

class SpeakerJob
{
    public function __construct(
        public int $fileId,
        public string $chamber,
        public string $videoUrl,
        public ?array $metadata,
        public ?string $eventType = null,
        public ?string $captureDirectory = null,
        public ?string $manifestUrl = null,
        public string $date = '2020-01-01'
    ) {
    }
}
