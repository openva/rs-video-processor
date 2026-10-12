<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use GuzzleHttp\Client;
use RuntimeException;

class AudioExtractor
{
    private Client $http;

    public function __construct(private ?string $workingDir = null)
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
