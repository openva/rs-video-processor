<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

class TranscriptJob
{
    public function __construct(
        public int $fileId,
        public string $chamber,
        public string $videoUrl,
        public ?string $webvtt,
        public ?string $srt,
        public ?string $title
    ) {
    }
}
