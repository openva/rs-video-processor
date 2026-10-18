<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use GuzzleHttp\ClientInterface;
use RuntimeException;

class OpenAITranscriber implements WordTranscriber
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

    /**
     * Each word with its own start and end time, which segments are too coarse
     * to give.
     *
     * @return list<array{word:string,start:float,end:float}>
     */
    public function transcribeWords(string $audioPath): array
    {
        $response = $this->client->request('POST', 'https://api.openai.com/v1/audio/transcriptions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
            ],
            'multipart' => [
                ['name' => 'model', 'contents' => 'whisper-1'],
                ['name' => 'response_format', 'contents' => 'verbose_json'],
                ['name' => 'timestamp_granularities[]', 'contents' => 'word'],
                ['name' => 'file', 'contents' => fopen($audioPath, 'r'), 'filename' => basename($audioPath)],
            ],
        ]);

        $payload = json_decode((string) $response->getBody(), true);
        if (!isset($payload['words']) || !is_array($payload['words'])) {
            throw new RuntimeException('Unexpected response from OpenAI: no word timestamps.');
        }

        $words = [];
        foreach ($payload['words'] as $word) {
            $words[] = [
                'word' => (string) $word['word'],
                'start' => (float) $word['start'],
                'end' => (float) $word['end'],
            ];
        }

        return $words;
    }
}
