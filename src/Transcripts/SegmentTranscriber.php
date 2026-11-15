<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

/**
 * Transcribes audio into timed segments (a sentence or so each), timed from
 * the start of the file: the transcript of a video that has no captions.
 */
interface SegmentTranscriber
{
    /**
     * @return array<int,array{start:float,end:float,text:string}>
     */
    public function transcribe(string $audioPath): array;
}
