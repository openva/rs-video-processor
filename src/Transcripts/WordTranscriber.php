<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

/**
 * Transcribes audio into words, each timed from the start of the file.
 * CaptionAligner uses it to hear what is actually said in a stretch of video.
 */
interface WordTranscriber
{
    /**
     * @return list<array{word:string,start:float,end:float}>
     */
    public function transcribeWords(string $audioPath): array;
}
