<?php

namespace RichmondSunlight\VideoProcessor\Archive;

class ArchiveJob
{
    public function __construct(
        public int $fileId,
        public string $chamber,
        public string $title,
        public string $date,
        public string $s3Path,
        public ?string $webvtt,
        public ?string $srt,
        public ?string $captureDirectory,
        public ?string $videoIndexCache,
        public ?int $committeeId = null,
        public ?string $committeeName = null,
        /** files.caption_alignment: null until the transcript stage has checked the captions' timing */
        public ?string $captionAlignment = null
    ) {
    }
}
