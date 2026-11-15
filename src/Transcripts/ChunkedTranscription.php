<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

/**
 * Transcribes a whole video: its audio in pieces (OpenAI rejects files over
 * 25 MB), each piece's segments moved to its place in the video.
 */
final class ChunkedTranscription
{
    /**
     * @return array<int,array{start:float,end:float,text:string}>
     */
    public static function run(AudioExtractor $audio, SegmentTranscriber $transcriber, string $videoUrl): array
    {
        $chunks = $audio->extractChunks($videoUrl);
        $segments = [];

        try {
            foreach ($chunks as $chunk) {
                // Each piece is timed from zero; shift it to its place in the video.
                foreach ($transcriber->transcribe($chunk['path']) as $segment) {
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
