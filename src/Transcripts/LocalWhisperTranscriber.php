<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use RuntimeException;

/**
 * Transcripts from a Whisper command-line tool on this machine, instead of
 * the OpenAI API, for free. Used by bin/align_transcripts.php --local-whisper
 * and --mlx-whisper to measure caption offsets (word timestamps), and by
 * bin/transcribe_backlog.php to transcribe videos that have no captions.
 *
 * Two tools are supported: openai-whisper (`whisper`, runs on the CPU) and
 * mlx-whisper (`mlx_whisper`, runs on an Apple Silicon GPU, and much faster).
 * They write the same JSON but spell their options differently.
 */
class LocalWhisperTranscriber implements WordTranscriber, SegmentTranscriber
{
    /** Enough to line up captions with; quick. */
    public const MLX_DEFAULT_MODEL = 'mlx-community/whisper-base.en-mlx';

    /** For a transcript people will read: far more accurate, and still quick on Apple Silicon. */
    public const MLX_TRANSCRIPT_MODEL = 'mlx-community/whisper-large-v3-turbo';

    public function __construct(
        private string $model = 'base.en',
        private string $binary = 'whisper',
        private bool $mlx = false
    ) {
    }

    public static function mlx(?string $model = null): self
    {
        return new self($model ?? self::MLX_DEFAULT_MODEL, 'mlx_whisper', true);
    }

    public function transcribeWords(string $audioPath): array
    {
        $words = [];
        foreach ($this->run($audioPath, true, true)['segments'] ?? [] as $segment) {
            foreach ($segment['words'] ?? [] as $word) {
                $words[] = [
                    'word' => (string) $word['word'],
                    'start' => (float) $word['start'],
                    'end' => (float) $word['end'],
                ];
            }
        }

        return $words;
    }

    /**
     * A segment is the text of up to 30 seconds and can run past the 255
     * bytes video_transcript.text holds, so a long one is split at its words,
     * each piece timed by its own words.
     */
    public function transcribe(string $audioPath): array
    {
        $segments = [];
        foreach ($this->run($audioPath, true, false)['segments'] ?? [] as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $words = array_values(array_filter($segment['words'] ?? [], static fn($w): bool => trim((string) ($w['word'] ?? '')) !== ''));
            if (strlen($text) <= self::MAX_SEGMENT_BYTES || empty($words)) {
                $segments[] = ['start' => (float) $segment['start'], 'end' => (float) $segment['end'], 'text' => $text];
                continue;
            }
            foreach (self::split($words) as $piece) {
                $segments[] = $piece;
            }
        }

        return $segments;
    }

    /** Pieces of a long segment are kept under this, below TINYTEXT's 255 bytes. */
    public const MAX_SEGMENT_BYTES = 200;

    /** A piece this long may end at the end of a sentence. */
    private const SENTENCE_BREAK_BYTES = 80;

    /**
     * @param list<array{word:string,start:float|int,end:float|int}> $words
     * @return list<array{start:float,end:float,text:string}>
     */
    private static function split(array $words): array
    {
        $pieces = [];
        $text = '';
        $start = null;
        $end = 0.0;
        foreach ($words as $word) {
            $next = trim($text . (string) $word['word']);
            if ($text !== '' && strlen($next) > self::MAX_SEGMENT_BYTES) {
                $pieces[] = ['start' => $start, 'end' => $end, 'text' => trim($text)];
                $text = '';
                $start = null;
            }
            $start ??= (float) $word['start'];
            $text .= (string) $word['word'];
            $end = (float) $word['end'];
            if (strlen(trim($text)) >= self::SENTENCE_BREAK_BYTES && preg_match('/[.?!]["\')]?$/', trim($text)) === 1) {
                $pieces[] = ['start' => $start, 'end' => $end, 'text' => trim($text)];
                $text = '';
                $start = null;
            }
        }
        if (trim($text) !== '') {
            $pieces[] = ['start' => $start, 'end' => $end, 'text' => trim($text)];
        }

        return $pieces;
    }

    /**
     * @return array<string,mixed> the tool's JSON output
     */
    private function run(string $audioPath, bool $wordTimestamps, bool $conditionOnPreviousText = true): array
    {
        $outputDir = sys_get_temp_dir() . '/whisper_' . uniqid('', true);
        if (!mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new RuntimeException('Unable to create a directory for Whisper output.');
        }

        try {
            // A whole meeting is transcribed in 20-minute pieces. Fed its own
            // earlier output, Whisper can fall into repeating one line for
            // minutes on end, so a transcript is made without it. Two-minute
            // samples are short enough not to.
            $cmd = sprintf(
                $this->mlx
                    ? '%s %s --model %s --language en --word-timestamps %s --condition-on-previous-text %s'
                        . ' --output-format json --output-dir %s --output-name words --verbose False 2>&1'
                    : '%s %s --model %s --language en --word_timestamps %s --condition_on_previous_text %s'
                        . ' --output_format json --output_dir %s --fp16 False --verbose False 2>&1',
                escapeshellcmd($this->binary),
                escapeshellarg($audioPath),
                escapeshellarg($this->model),
                $wordTimestamps ? 'True' : 'False',
                $conditionOnPreviousText ? 'True' : 'False',
                escapeshellarg($outputDir)
            );
            exec($cmd, $output, $status);
            // mlx-whisper would cut the name at its first dot, so it is told the name.
            $jsonPath = $outputDir . '/' . ($this->mlx ? 'words' : pathinfo($audioPath, PATHINFO_FILENAME)) . '.json';
            if ($status !== 0 || !is_file($jsonPath)) {
                throw new RuntimeException('Whisper failed: ' . trim(implode("\n", array_slice($output, -3))));
            }

            $payload = json_decode((string) file_get_contents($jsonPath), true);
            if (!is_array($payload)) {
                throw new RuntimeException('Whisper wrote something other than JSON.');
            }

            return $payload;
        } finally {
            foreach (glob($outputDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($outputDir);
        }
    }
}
