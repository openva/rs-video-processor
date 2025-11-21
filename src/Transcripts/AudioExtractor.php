<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use GuzzleHttp\Client;
use RuntimeException;

class AudioExtractor
{
    private Client $http;

    public function __construct(private ?string $workingDir = null)
    {
        $this->http = new Client(['timeout' => 600]);
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
