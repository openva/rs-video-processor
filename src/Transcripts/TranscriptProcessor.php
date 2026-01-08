<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use GuzzleHttp\Client;
use Log;
use RuntimeException;

class TranscriptProcessor
{
    private CaptionParser $parser;
    private AudioExtractor $audioExtractor;

    public function __construct(
        private TranscriptWriter $writer,
        private OpenAITranscriber $transcriber,
        ?CaptionParser $parser = null,
        ?AudioExtractor $audioExtractor = null,
        private ?Log $logger = null
    ) {
        $this->parser = $parser ?? new CaptionParser();
        $this->audioExtractor = $audioExtractor ?? new AudioExtractor();
    }

    public function process(TranscriptJob $job): void
    {
        $segments = [];
        $source = 'none';
        if ($job->webvtt) {
            $segments = $this->parser->parseWebVtt($job->webvtt);
            $source = 'webvtt';
        } elseif ($job->srt) {
            $segments = $this->parser->parseSrt($job->srt);
            $source = 'srt';
        }

        if (empty($segments)) {
            $this->logger?->put('Falling back to OpenAI transcription for file #' . $job->fileId, 4);
            $segments = $this->transcribe($job->videoUrl);
            $source = 'openai';
        }

        if (empty($segments)) {
            throw new RuntimeException('Unable to derive transcript for file #' . $job->fileId);
        }

        $this->writer->write($job->fileId, $segments);
        $this->logger?->put(sprintf('Stored %d transcript segments for file #%d via %s', count($segments), $job->fileId, $source), 3);
    }

    /**
     * @return array<int,array{start:float,end:float,text:string}>
     */
    private function transcribe(string $videoUrl): array
    {
        $audio = $this->audioExtractor->extract($videoUrl);
        try {
            return $this->transcriber->transcribe($audio);
        } finally {
            @unlink($audio);
        }
    }
}
