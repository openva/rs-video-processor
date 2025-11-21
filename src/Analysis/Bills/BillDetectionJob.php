<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

class BillDetectionJob
{
    public function __construct(
        public int $fileId,
        public string $chamber,
        public ?int $committeeId,
        public string $eventType,
        public string $captureDirectory,
        public ?string $manifestUrl,
        public ?array $metadata
    ) {
    }
}
