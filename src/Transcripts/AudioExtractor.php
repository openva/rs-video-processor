<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Fetcher\BucketReader;
use RuntimeException;

class AudioExtractor
{
    private Client $http;

    /** Seconds to wait before trying a failed audio window again. */
    public int $retryPauseSeconds = 5;

    /**
     * @param BucketReader|null $bucket read our own videos straight from S3,
     *        which is free, rather than through CloudFront, which is not
     */
    public function __construct(private ?string $workingDir = null, private ?BucketReader $bucket = null)
    {
        // Videos run to several GB; ten minutes was not always enough.
        $this->http = new Client(['timeout' => 3600, 'connect_timeout' => 30]);
        $this->workingDir = $workingDir ?? sys_get_temp_dir();
    }

    public function extract(string $videoUrl): string
    {
        $videoPath = $this->downloadVideo($videoUrl);
        $audioPath = tempnam($this->workingDir, 'transcript_') . '.mp3';
        $cmd = sprintf(
            'ffmpeg -y -loglevel error -i %s -ar 16000 -ac 1 -b:a 32k %s',
            escapeshellarg($videoPath),
            escapeshellarg($audioPath)
        );
        $this->runCommand($cmd, 'Failed to convert audio for transcription.');
        @unlink($videoPath);
        return $audioPath;
    }

    /**
     * Extract the audio as a series of MP3 files of at most $segmentSeconds each.
     *
     * OpenAI's transcription endpoint rejects files over 25 MB. At 32 kbps a
     * whole meeting passes that after about 1h44m; a 20-minute piece is under 5 MB.
     *
     * @return array<int,array{path:string,offset:float}> in order; offset is the piece's
     *         start time in the original video, in seconds. The caller deletes the files.
     */
    public function extractChunks(string $videoUrl, int $segmentSeconds = 1200): array
    {
        $videoPath = $this->downloadVideo($videoUrl);
        $dir = $this->workingDir . '/transcript_chunks_' . uniqid('', true);
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            @unlink($videoPath);
            throw new RuntimeException('Unable to create a working directory for audio chunks.');
        }

        try {
            $cmd = sprintf(
                'ffmpeg -y -loglevel error -i %s -vn -ar 16000 -ac 1 -b:a 32k '
                . '-f segment -segment_time %d -reset_timestamps 1 %s',
                escapeshellarg($videoPath),
                $segmentSeconds,
                escapeshellarg($dir . '/chunk_%05d.mp3')
            );
            $this->runCommand($cmd, 'Failed to convert audio for transcription.');
        } finally {
            @unlink($videoPath);
        }

        $files = glob($dir . '/chunk_*.mp3') ?: [];
        sort($files);
        if (empty($files)) {
            @rmdir($dir);
            throw new RuntimeException('ffmpeg produced no audio for transcription.');
        }

        $chunks = [];
        foreach ($files as $index => $file) {
            $chunks[] = ['path' => $file, 'offset' => (float) ($index * $segmentSeconds)];
        }

        return $chunks;
    }

    /**
     * Extract $seconds of audio starting $start seconds into the video, as an
     * MP3. Seeking before -i makes ffmpeg read only the byte ranges it needs,
     * so the video is not downloaded. The caller deletes the file.
     */
    public function extractWindow(string $videoUrl, float $start, int $seconds): string
    {
        $result = $this->extractWindows($videoUrl, [$start], $seconds)[0];
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    /**
     * Several windows at once. Reading a window from archive.org takes most
     * of a minute, nearly all of it waiting on the network, so they are read
     * in parallel.
     *
     * @param float[] $starts
     * @return array<int,string|RuntimeException> keyed like $starts: an MP3's path
     *         (the caller deletes it), or why that window could not be read
     */
    public function extractWindows(string $videoUrl, array $starts, int $seconds): array
    {
        $results = $this->runWindows($videoUrl, $starts, $seconds);

        // archive.org sometimes refuses or drops a request when several arrive
        // at once. Try each failed window again, on its own, after a pause. A
        // window past the end of the video would only fail again.
        foreach ($results as $key => $result) {
            if ($result instanceof RuntimeException && !str_starts_with($result->getMessage(), 'No audio at')) {
                if ($this->retryPauseSeconds > 0) {
                    sleep($this->retryPauseSeconds);
                }
                $results[$key] = $this->runWindows($videoUrl, [$key => $starts[$key]], $seconds)[$key];
            }
        }

        return $results;
    }

    /**
     * @param float[] $starts
     * @return array<int,string|RuntimeException> keyed like $starts
     */
    private function runWindows(string $videoUrl, array $starts, int $seconds): array
    {
        $input = str_starts_with($videoUrl, 'file://')
            ? substr($videoUrl, 7)
            : ($this->bucket?->presignedUrl($videoUrl) ?? $videoUrl);
        $running = [];
        foreach ($starts as $key => $start) {
            $audioPath = $this->workingDir . '/window_' . uniqid('', true) . '.mp3';
            $errorPath = $audioPath . '.err';
            $cmd = sprintf(
                'ffmpeg -y -loglevel error -ss %.2f -t %d -i %s -vn -ar 16000 -ac 1 -b:a 32k %s',
                max(0.0, $start),
                $seconds,
                escapeshellarg($input),
                escapeshellarg($audioPath)
            );
            $process = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $errorPath, 'w']], $pipes);
            $running[$key] = [$process, $audioPath, $errorPath, (float) $start];
        }

        $results = [];
        foreach ($running as $key => [$process, $audioPath, $errorPath, $start]) {
            $status = is_resource($process) ? proc_close($process) : 1;
            $errors = trim((string) @file_get_contents($errorPath));
            @unlink($errorPath);
            $results[$key] = $this->checkWindow($status, $audioPath, $start, $videoUrl, $errors);
        }

        return $results;
    }

    private function checkWindow(int $status, string $audioPath, float $start, string $videoUrl, string $errors = ''): string|RuntimeException
    {
        if ($status !== 0 || !is_file($audioPath)) {
            @unlink($audioPath);
            $lines = $errors === '' ? [] : explode("\n", $errors);
            return new RuntimeException(sprintf(
                'Failed to extract audio at %ds of %s%s.',
                (int) $start,
                $videoUrl,
                $lines ? ': ' . trim((string) end($lines)) : ''
            ));
        }
        // Past the end of the video, ffmpeg succeeds and writes only a header.
        if (filesize($audioPath) < 1024) {
            @unlink($audioPath);
            return new RuntimeException(sprintf('No audio at %ds; the video is shorter than that.', (int) $start));
        }

        return $audioPath;
    }

    private function downloadVideo(string $url): string
    {
        if (str_starts_with($url, 'file://')) {
            $src = substr($url, 7);
            $dest = tempnam($this->workingDir, 'video_');
            if (!copy($src, $dest)) {
                throw new RuntimeException('Unable to copy local video fixture.');
            }
            return $dest;
        }
        $dest = tempnam($this->workingDir, 'video_');
        if ($this->bucket?->download($url, $dest) !== null) {
            return $dest;
        }
        $response = $this->http->get($url, ['sink' => $dest]);
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Failed to download video for transcription.');
        }
        return $dest;
    }

    private function runCommand(string $cmd, string $error): void
    {
        exec($cmd, $output, $status);
        if ($status !== 0) {
            throw new RuntimeException($error);
        }
    }
}
