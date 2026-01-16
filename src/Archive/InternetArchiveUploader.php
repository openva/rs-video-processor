<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use Log;
use GuzzleHttp\Client;
use RuntimeException;

class InternetArchiveUploader
{
    private $commandRunner;
    private $downloader;
    private $metadataFetcher;
    private Client $http;

    public function __construct(
        private ?Log $logger = null,
        ?callable $commandRunner = null,
        ?callable $downloader = null,
        ?callable $metadataFetcher = null,
        ?Client $http = null
    ) {
        $this->commandRunner = $commandRunner ?? function (string $command, array &$output): int {
            exec($command, $output, $status);
            return $status;
        };
        $this->downloader = $downloader ?? function (string $url): string {
            $temp = tempnam(sys_get_temp_dir(), 'ia_video_');
            $tempWithExt = $temp . '.mp4';
            rename($temp, $tempWithExt);
            $cmd = sprintf('curl -L %s -o %s', escapeshellarg($url), escapeshellarg($tempWithExt));
            exec($cmd, $out, $status);
            if ($status !== 0) {
                throw new RuntimeException('Unable to download video for Internet Archive.');
            }
            return $tempWithExt;
        };
        $this->metadataFetcher = $metadataFetcher;
        $this->http = $http ?? new Client(['timeout' => 30]);
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
            $captions = $this->writeTempFile($job->webvtt, 'ia_vtt_', '.vtt');
            $tempFiles[] = $captions;
        } elseif ($job->srt) {
            $captions = $this->writeTempFile($job->srt, 'ia_srt_', '.srt');
            $tempFiles[] = $captions;
        }

        $command = $this->buildCommand($identifier, $videoPath, $metadata, $captions);
        $output = [];
        $status = $this->invokeCommandRunner($command, $output);
        foreach ($tempFiles as $file) {
            @unlink($file);
        }
        if ($status !== 0) {
            $message = trim(implode('; ', $output));
            $message = $message !== '' ? $message : 'No output from ia upload command.';
            $this->logger?->put('Internet Archive upload failed for file #' . $job->fileId . ': ' . $message, 5);
            return null;
        }

        $mp4Url = $this->resolveMp4Url($identifier, basename($videoPath));
        if (!$mp4Url) {
            $detailsUrl = sprintf('https://archive.org/details/%s', $identifier);
            $this->logger?->put(
                'Unable to resolve Archive.org MP4 URL for file #' . $job->fileId . '; storing details URL.',
                5
            );
            return $detailsUrl;
        }

        return $mp4Url;
    }

    private function ensureConfigExists(): bool
    {
        $path = getenv('HOME') . '/.config/internetarchive/ia.ini';
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
        return implode(' ', $parts) . ' 2>&1';
    }

    private function resolveMp4Url(string $identifier, string $preferredFilename, int $attempts = 5, int $delaySeconds = 2): ?string
    {
        for ($i = 0; $i < $attempts; $i++) {
            $metadata = $this->fetchMetadata($identifier);
            if (is_array($metadata)) {
                $file = $this->selectMp4File($metadata['files'] ?? [], $preferredFilename);
                if ($file) {
                    return $this->buildDownloadUrl($identifier, $file['name']);
                }
            }
            if ($i < $attempts - 1) {
                sleep($delaySeconds);
            }
        }

        return null;
    }

    private function fetchMetadata(string $identifier): ?array
    {
        if ($this->metadataFetcher) {
            return ($this->metadataFetcher)($identifier);
        }

        $url = sprintf('https://archive.org/metadata/%s', rawurlencode($identifier));
        $response = $this->http->get($url);
        if ($response->getStatusCode() >= 400) {
            return null;
        }
        $data = json_decode((string) $response->getBody(), true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<int,array<string,mixed>> $files
     * @return array<string,mixed>|null
     */
    private function selectMp4File(array $files, string $preferredFilename): ?array
    {
        $preferred = null;
        $fallback = null;
        $fallbackSize = 0;

        foreach ($files as $file) {
            $name = $file['name'] ?? '';
            if (!is_string($name) || $name === '' || !str_ends_with(strtolower($name), '.mp4')) {
                continue;
            }
            if ($name === $preferredFilename) {
                $preferred = $file;
                break;
            }
            $size = isset($file['size']) ? (int) $file['size'] : 0;
            if ($size >= $fallbackSize) {
                $fallbackSize = $size;
                $fallback = $file;
            }
        }

        return $preferred ?? $fallback;
    }

    private function buildDownloadUrl(string $identifier, string $filename): string
    {
        $encoded = str_replace('%2F', '/', rawurlencode($filename));
        return sprintf('https://archive.org/download/%s/%s', rawurlencode($identifier), $encoded);
    }

    private function writeTempFile(string $contents, string $prefix, string $extension = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($extension) {
            $pathWithExt = $path . $extension;
            rename($path, $pathWithExt);
            $path = $pathWithExt;
        }
        file_put_contents($path, $contents);
        return $path;
    }

    private function invokeCommandRunner(string $command, array &$output): int
    {
        if (is_array($this->commandRunner) || $this->commandRunner instanceof \Closure) {
            return call_user_func_array($this->commandRunner, [$command, &$output]);
        }
        return ($this->commandRunner)($command, $output);
    }
}
