<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use GuzzleHttp\ClientInterface;
use RuntimeException;

class OpenAITranscriber
{
    public function __construct(private ClientInterface $client, private string $apiKey)
    {
    }

    /**
     * @return array<int,array{start:float,end:float,text:string}>
     */
    public function transcribe(string $audioPath): array
    {
        $response = $this->client->request('POST', 'https://api.openai.com/v1/audio/transcriptions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
            ],
            'multipart' => [
                ['name' => 'model', 'contents' => 'whisper-1'],
                ['name' => 'response_format', 'contents' => 'verbose_json'],
                ['name' => 'file', 'contents' => fopen($audioPath, 'r'), 'filename' => basename($audioPath)],
            ],
        ]);

        $payload = json_decode((string) $response->getBody(), true);
        if (!isset($payload['segments'])) {
            throw new RuntimeException('Unexpected response from OpenAI.');
        }

        $segments = [];
        foreach ($payload['segments'] as $segment) {
            $segments[] = [
                'start' => (float) $segment['start'],
                'end' => (float) $segment['end'],
                'text' => trim((string) $segment['text']),
            ];
        }

        return $segments;
    }
}
