<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

class SpeakerJob
{
    public function __construct(
        public int $fileId,
        public string $chamber,
        public string $videoUrl,
        public ?array $metadata
    ) {
    }
}
