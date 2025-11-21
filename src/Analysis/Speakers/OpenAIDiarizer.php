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
            $response = $this->client->request('POST', 'https://api.openai.com/v1/audio/diarize', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'multipart' => [
                    ['name' => 'model', 'contents' => 'whisper-1'],
                    ['name' => 'file', 'contents' => fopen($audioPath, 'r'), 'filename' => basename($audioPath)],
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true);
            if (!isset($payload['segments'])) {
                throw new RuntimeException('Unexpected response from diarization endpoint.');
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
