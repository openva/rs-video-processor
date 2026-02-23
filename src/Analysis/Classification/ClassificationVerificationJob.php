<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Classification;

class ClassificationVerificationJob
{
    public function __construct(
        public int $fileId,
        public string $chamber,
        public string $currentEventType,
        public ?int $currentCommitteeId,
        public string $captureDirectory,
        public ?string $manifestUrl,
        public ?array $videoIndexCache,
        public ?string $title,
        public string $date = '2020-01-01'
    ) {
    }
}
