<?php

namespace RichmondSunlight\VideoProcessor\Screenshots;

class ScreenshotJob
{
    public function __construct(
        public int $id,
        public string $chamber,
        public ?int $committeeId,
        public string $date,
        public string $videoPath,
        public ?string $captureDirectory,
        public ?string $title
    ) {
    }

    public function videoKey(): ?string
    {
        if (!str_contains($this->videoPath, 'video.richmondsunlight.com/')) {
            return null;
        }
        $parts = explode('video.richmondsunlight.com/', $this->videoPath, 2);
        return $parts[1] ?? null;
    }
}
