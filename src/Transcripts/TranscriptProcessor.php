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
        // Get a fresh DB connection before each job — OpenAI transcription can
        // take minutes and the connection times out between jobs.
        $this->writer->reconnect();

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

        // Transcription can run for minutes with no database activity; the
        // connection from the top of process() has usually timed out by now.
        $this->writer->reconnect();
        $this->writer->write($job->fileId, $segments);
        $this->logger?->put(sprintf('Stored %d transcript segments for file #%d via %s', count($segments), $job->fileId, $source), 3);
    }

    /**
     * @return array<int,array{start:float,end:float,text:string}>
     */
    private function transcribe(string $videoUrl): array
    {
        $chunks = $this->audioExtractor->extractChunks($videoUrl);
        $segments = [];

        try {
            foreach ($chunks as $chunk) {
                // Each piece is timed from zero; shift it to its place in the video.
                foreach ($this->transcriber->transcribe($chunk['path']) as $segment) {
                    $segments[] = [
                        'start' => $segment['start'] + $chunk['offset'],
                        'end' => $segment['end'] + $chunk['offset'],
                        'text' => $segment['text'],
                    ];
                }
            }
        } finally {
            foreach ($chunks as $chunk) {
                @unlink($chunk['path']);
            }
            if (!empty($chunks)) {
                @rmdir(dirname($chunks[0]['path']));
            }
        }

        return $segments;
    }
}
