<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

class VideoMetadataExtractor
{
    public function extract(string $filePath): array
    {
        $cmd = sprintf(
            'ffprobe -v error -print_format json -show_streams -show_format %s',
            escapeshellarg($filePath)
        );

        $output = shell_exec($cmd);
        if (!$output) {
            throw new \RuntimeException('Unable to analyze video via ffprobe.');
        }

        $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $stream = $data['streams'][0] ?? [];
        $format = $data['format'] ?? [];

        $durationSeconds = isset($format['duration']) ? (int) round((float) $format['duration']) : null;
        $width = $stream['width'] ?? null;
        $height = $stream['height'] ?? null;
        $fps = null;
        if (isset($stream['r_frame_rate'])) {
            $fps = $this->parseFrameRate($stream['r_frame_rate']);
        }

        return [
            'duration_seconds' => $durationSeconds,
            'length' => $durationSeconds !== null ? $this->formatDuration($durationSeconds) : null,
            'width' => $width,
            'height' => $height,
            'fps' => $fps,
        ];
    }

    private function parseFrameRate(string $value): ?float
    {
        if (str_contains($value, '/')) {
            [$num, $den] = array_map('floatval', explode('/', $value, 2));
            if ($den > 0) {
                return round($num / $den, 3);
            }
        } elseif (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max($seconds, 0);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}
