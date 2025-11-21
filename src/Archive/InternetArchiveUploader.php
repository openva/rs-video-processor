<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use Log;
use RuntimeException;

class InternetArchiveUploader
{
    private $commandRunner;
    private $downloader;

    public function __construct(private ?Log $logger = null, ?callable $commandRunner = null, ?callable $downloader = null)
    {
        $this->commandRunner = $commandRunner ?? function (string $command, array &$output): int {
            exec($command, $output, $status);
            return $status;
        };
        $this->downloader = $downloader ?? function (string $url): string {
            $temp = tempnam(sys_get_temp_dir(), 'ia_video_');
            $cmd = sprintf('curl -L %s -o %s', escapeshellarg($url), escapeshellarg($temp));
            exec($cmd, $out, $status);
            if ($status !== 0) {
                throw new RuntimeException('Unable to download video for Internet Archive.');
            }
            return $temp;
        };
    }

    public function upload(ArchiveJob $job, array $metadata): ?string
    {
        if (!$this->ensureConfigExists()) {
            return null;
        }
        $identifier = (new IdentifierBuilder())->build($job);
        $videoPath = call_user_func($this->downloader, $job->s3Path);
        $tempFiles = [$videoPath];
        $captions = null;
        if ($job->webvtt) {
            $captions = $this->writeTempFile($job->webvtt, 'ia_vtt_');
            $tempFiles[] = $captions;
        } elseif ($job->srt) {
            $captions = $this->writeTempFile($job->srt, 'ia_srt_');
            $tempFiles[] = $captions;
        }

        $command = $this->buildCommand($identifier, $videoPath, $metadata, $captions);
        $output = [];
        $status = call_user_func($this->commandRunner, $command, $output);
        foreach ($tempFiles as $file) {
            @unlink($file);
        }
        if ($status !== 0) {
            $this->logger?->put('Internet Archive upload failed for file #' . $job->fileId . ': ' . implode('; ', $output), 5);
            return null;
        }

        return sprintf('https://archive.org/details/%s', $identifier);
    }

    private function ensureConfigExists(): bool
    {
        $path = getenv('HOME') . '/.config/ia.ini';
        if (!file_exists($path)) {
            $this->logger?->put('Internet Archive configuration missing at ' . $path . '. Run `ia configure`.', 6);
            return false;
        }
        return true;
    }

    private function buildCommand(string $identifier, string $videoPath, array $metadata, ?string $captionPath): string
    {
        $parts = ['ia', 'upload', escapeshellarg($identifier), escapeshellarg($videoPath)];
        foreach ($metadata as $key => $value) {
            $parts[] = '--metadata=' . escapeshellarg(sprintf('%s:%s', $key, $value));
        }
        if ($captionPath) {
            $parts[] = escapeshellarg($captionPath);
        }
        return implode(' ', $parts);
    }

    private function writeTempFile(string $contents, string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($path, $contents);
        return $path;
    }
}
