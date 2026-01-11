<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use GuzzleHttp\ClientInterface;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;
use RuntimeException;

class OpenAIDiarizer implements DiarizerInterface
{
    public function __construct(
        private ClientInterface $client,
        private string $apiKey,
        private ?AudioExtractor $audioExtractor = null
    ) {
        $this->audioExtractor = $audioExtractor ?? new AudioExtractor();
    }

    public function diarize(string $videoUrl): array
    {
        $audioPath = $this->audioExtractor->extract($videoUrl);
        try {
            $response = $this->client->request('POST', 'https://api.openai.com/v1/audio/transcriptions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'multipart' => [
                    ['name' => 'model', 'contents' => 'gpt-4o-transcribe-diarize'],
                    ['name' => 'file', 'contents' => fopen($audioPath, 'r'), 'filename' => basename($audioPath)],
                    ['name' => 'response_format', 'contents' => 'diarized_json'],
                    ['name' => 'chunking_strategy', 'contents' => 'auto'],
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true);
            if (!isset($payload['segments'])) {
                throw new RuntimeException('Unexpected response from transcription endpoint.');
            }
            $segments = [];
            foreach ($payload['segments'] as $segment) {
                $speaker = trim((string) ($segment['speaker'] ?? 'Unknown Speaker'));
                $segments[] = [
                    'name' => $speaker,
                    'start' => (float) ($segment['start'] ?? 0),
                ];
            }
            return $segments;
        } finally {
            @unlink($audioPath);
        }
    }
}
