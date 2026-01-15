<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use GuzzleHttp\Client;
use Log;
use RichmondSunlight\VideoProcessor\Analysis\Metadata\MetadataIndexer;
use PDO;

class VideoDownloadProcessor
{
    private string $downloadDir;
    private Client $http;

    public function __construct(
        private PDO $pdo,
        private StorageInterface $storage,
        private CommitteeDirectory $committeeDirectory,
        private VideoMetadataExtractor $metadataExtractor,
        private S3KeyBuilder $keyBuilder,
        private ?MetadataIndexer $metadataIndexer = null,
        private ?Log $logger = null,
        ?string $downloadDir = null
    ) {
        $this->downloadDir = $downloadDir ?? __DIR__ . '/../../storage/downloads';
        if (!is_dir($this->downloadDir)) {
            mkdir($this->downloadDir, 0775, true);
        }
        $this->http = new Client(['timeout' => 600]);
        $this->metadataIndexer = $this->metadataIndexer ?? new MetadataIndexer($this->pdo);
    }

    public function process(VideoDownloadJob $job): void
    {
        $this->logger?->put(sprintf('Processing video #%d (%s)', $job->id, $job->remoteUrl), 3);

        $localVideo = $this->downloadVideo($job);
        $captionPath = $this->downloadCaptions($job);

        $meta = $this->metadataExtractor->extract($localVideo);
        $s3Key = $this->buildS3Key($job);
        $s3Url = $this->storage->upload($localVideo, $s3Key);

        $captionContents = $captionPath && is_readable($captionPath)
            ? file_get_contents($captionPath)
            : null;

        $this->updateDatabase($job, $s3Url, $s3Key, $meta, $captionContents);
        $this->metadataIndexer?->index($job->id, $job->metadata);

        @unlink($localVideo);
        if ($captionPath) {
            @unlink($captionPath);
        }
    }

    private function downloadVideo(VideoDownloadJob $job): string
    {
        $ext = '.mp4';
        $localPath = $this->downloadDir . '/' . $job->chamber . '-' . $job->id . $ext;

        // Ensure download directory exists
        if (!is_dir($this->downloadDir)) {
            throw new \RuntimeException('Download directory does not exist: ' . $this->downloadDir);
        }

        if ($this->isGranicusUrl($job->remoteUrl)) {
            $this->downloadViaHttp($job->remoteUrl, $localPath);
        } elseif (str_ends_with($job->remoteUrl, '.m3u8')) {
            $this->downloadViaFfmpeg($job->remoteUrl, $localPath);
        } else {
            $this->downloadViaHttp($job->remoteUrl, $localPath);
        }

        if (!file_exists($localPath)) {
            throw new \RuntimeException(sprintf(
                'Download failed - file does not exist at %s (source: %s)',
                $localPath,
                $job->remoteUrl
            ));
        }

        if (filesize($localPath) < 1048576) {
            @unlink($localPath);
            throw new \RuntimeException(sprintf(
                'Downloaded file is too small (%d bytes) for %s',
                filesize($localPath),
                $job->remoteUrl
            ));
        }

        return $localPath;
    }

    protected function downloadViaHttp(string $url, string $destination): void
    {
        try {
            $response = $this->http->get($url, ['sink' => $destination]);
            if ($response->getStatusCode() >= 400) {
                throw new \RuntimeException(sprintf(
                    'HTTP download failed with status %d for %s',
                    $response->getStatusCode(),
                    $url
                ));
            }
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf(
                'Failed to download video via HTTP from %s: %s',
                $url,
                $e->getMessage()
            ), 0, $e);
        }

        // Verify file was actually written
        if (!file_exists($destination) || filesize($destination) === 0) {
            throw new \RuntimeException(sprintf(
                'HTTP download did not create file at %s (source: %s)',
                $destination,
                $url
            ));
        }
    }

    protected function downloadViaFfmpeg(string $url, string $destination): void
    {
        $cmd = sprintf('ffmpeg -y -loglevel error -i %s -c copy %s', escapeshellarg($url), escapeshellarg($destination));
        $this->runCommand($cmd, sprintf('Failed to download HLS stream via ffmpeg from %s', $url));

        // Verify file was actually created
        if (!file_exists($destination) || filesize($destination) === 0) {
            throw new \RuntimeException(sprintf(
                'FFmpeg download did not create file at %s (source: %s)',
                $destination,
                $url
            ));
        }
    }

    protected function downloadCaptions(VideoDownloadJob $job): ?string
    {
        // If metadata already contains caption text (e.g., from House ccItems), prefer that.
        $metadata = $job->metadata;
        $embedded = $metadata['captions'] ?? $metadata['captions_vtt'] ?? null;
        if (is_string($embedded) && trim($embedded) !== '') {
            $target = $this->downloadDir . '/' . $job->chamber . '-' . $job->id . '.vtt';
            file_put_contents($target, $embedded);
            return $target;
        }

        $captionUrl = $metadata['captions_url'] ?? null;
        if ($captionUrl) {
            $target = $this->downloadDir . '/' . $job->chamber . '-' . $job->id . '.vtt';
            $response = $this->http->get($captionUrl, ['sink' => $target]);
            if ($response->getStatusCode() < 400) {
                return $target;
            }
        }

        return null;
    }

    private function buildS3Key(VideoDownloadJob $job): string
    {
        $short = $job->committeeId ? $this->committeeDirectory->getShortnameById($job->committeeId) : null;
        return $this->keyBuilder->build($job->chamber, $job->date, $short);
    }

    private function updateDatabase(VideoDownloadJob $job, string $s3Url, string $s3Key, array $meta, ?string $caption): void
    {
        $sql = 'UPDATE files SET path = :path, length = :length,
            width = :width, height = :height, fps = :fps, webvtt = :webvtt,
            date_modified = CURRENT_TIMESTAMP WHERE id = :id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':path' => $s3Url,
            ':length' => $meta['length'] ?? null,
            ':width' => $meta['width'] ?? null,
            ':height' => $meta['height'] ?? null,
            ':fps' => $meta['fps'] ?? null,
            ':webvtt' => $caption,
            ':id' => $job->id,
        ]);
    }

    protected function runCommand(string $cmd, string $errorMessage): void
    {
        exec($cmd, $output, $status);
        if ($status !== 0) {
            throw new \RuntimeException($errorMessage);
        }
    }

    private function isGranicusUrl(string $url): bool
    {
        $normalized = strtolower($url);
        return str_contains($normalized, 'granicus.com') && str_contains($normalized, '.mp4');
    }
}
