<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use RuntimeException;

/**
 * Word timestamps from the openai-whisper command-line tool on this machine,
 * instead of the OpenAI API. Free, and accurate enough to measure caption
 * offsets with; used by bin/align_transcripts.php --local-whisper.
 */
class LocalWhisperTranscriber implements WordTranscriber
{
    public function __construct(private string $model = 'base.en', private string $binary = 'whisper')
    {
    }

    public function transcribeWords(string $audioPath): array
    {
        $outputDir = sys_get_temp_dir() . '/whisper_' . uniqid('', true);
        if (!mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new RuntimeException('Unable to create a directory for Whisper output.');
        }

        try {
            $cmd = sprintf(
                '%s %s --model %s --language en --word_timestamps True --output_format json'
                . ' --output_dir %s --fp16 False --verbose False 2>&1',
                escapeshellcmd($this->binary),
                escapeshellarg($audioPath),
                escapeshellarg($this->model),
                escapeshellarg($outputDir)
            );
            exec($cmd, $output, $status);
            $jsonPath = $outputDir . '/' . pathinfo($audioPath, PATHINFO_FILENAME) . '.json';
            if ($status !== 0 || !is_file($jsonPath)) {
                throw new RuntimeException('Whisper failed: ' . trim(implode("\n", array_slice($output, -3))));
            }

            $payload = json_decode((string) file_get_contents($jsonPath), true);
            $words = [];
            foreach ($payload['segments'] ?? [] as $segment) {
                foreach ($segment['words'] ?? [] as $word) {
                    $words[] = [
                        'word' => (string) $word['word'],
                        'start' => (float) $word['start'],
                        'end' => (float) $word['end'],
                    ];
                }
            }

            return $words;
        } finally {
            foreach (glob($outputDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($outputDir);
        }
    }
}
