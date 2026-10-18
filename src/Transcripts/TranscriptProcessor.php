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
        private ?Log $logger = null,
        private ?CaptionAligner $aligner = null
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

        // Without an aligner nothing is measured, and nothing is recorded.
        $alignment = null;
        $offset = null;
        if ($this->aligner !== null) {
            if ($source === 'openai') {
                // Whisper times its output from the audio; there is nothing to correct.
                $alignment = 'whisper';
            } else {
                [$segments, $alignment, $offset] = $this->alignCaptions($job, $segments);
            }
        }

        // Transcription can run for minutes with no database activity; the
        // connection from the top of process() has usually timed out by now.
        $this->writer->reconnect();
        $this->writer->write($job->fileId, $segments, $alignment, $offset);
        $this->logger?->put(sprintf('Stored %d transcript segments for file #%d via %s', count($segments), $job->fileId, $source), 3);
    }

    /**
     * Official captions run early or late by an amount that differs from file
     * to file. Measure it, and shift the captions when the measurement holds up.
     * Captions that cannot be aligned are still stored, as they are.
     *
     * @param array<int,array{start:float,end:float,text:string}> $segments
     * @return array{0:array<int,array{start:float,end:float,text:string}>,1:string,2:?float}
     *         the segments, the files.caption_alignment value, and the seconds applied
     */
    private function alignCaptions(TranscriptJob $job, array $segments): array
    {
        try {
            $result = $this->aligner->align($segments, $job->videoUrl);
        } catch (\Throwable $e) {
            $result = CaptionAlignment::failed('could not measure: ' . $e->getMessage());
        }

        if ($result->shouldShift()) {
            $this->logger?->put(sprintf('Shifted captions for file #%d by %+.1fs: %s', $job->fileId, -$result->offset, $result->summary()), 3);
            return [CaptionShift::segments($segments, $result->offset), 'aligned', $result->offset];
        }
        if (!$result->aligned) {
            $this->logger?->put(sprintf('Captions for file #%d could not be aligned with the audio; stored as they are: %s', $job->fileId, $result->summary()), 4);
            return [$segments, 'unaligned', null];
        }

        return [$segments, 'not_needed', 0.0];
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
