<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use GuzzleHttp\Client;
use Log;
use PDO;

class VideoDownloadProcessor
{
    private string $downloadDir;
    private Client $http;
    private array $recentCaptionCandidates = [];

    public function __construct(
        private PDO $pdo,
        private StorageInterface $storage,
        private CommitteeDirectory $committeeDirectory,
        private VideoMetadataExtractor $metadataExtractor,
        private S3KeyBuilder $keyBuilder,
        private ?Log $logger = null,
        ?string $downloadDir = null
    ) {
        $this->downloadDir = $downloadDir ?? __DIR__ . '/../../storage/downloads';
        if (!is_dir($this->downloadDir)) {
            mkdir($this->downloadDir, 0775, true);
        }
        $this->http = new Client(['timeout' => 600]);
        $this->recentCaptionCandidates = [];
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

        @unlink($localVideo);
        if ($captionPath) {
            @unlink($captionPath);
        }
    }

    private function downloadVideo(VideoDownloadJob $job): string
    {
        $ext = '.mp4';
        $localPath = $this->downloadDir . '/' . $job->chamber . '-' . $job->id . $ext;

        $this->recentCaptionCandidates = [];

        if ($this->isGranicusUrl($job->remoteUrl)) {
            $this->downloadViaHttp($job->remoteUrl, $localPath);
        } elseif ($job->isSenate() || str_contains($job->remoteUrl, 'youtube.com') || str_contains($job->remoteUrl, 'youtu.be')) {
            $this->downloadViaYtDlp($job->remoteUrl, $localPath);
        } elseif (str_ends_with($job->remoteUrl, '.m3u8')) {
            $this->downloadViaFfmpeg($job->remoteUrl, $localPath);
        } else {
            $this->downloadViaHttp($job->remoteUrl, $localPath);
        }

        if (!file_exists($localPath) || filesize($localPath) < 1048576) {
            throw new \RuntimeException('Downloaded file is invalid for ' . $job->remoteUrl);
        }

        return $localPath;
    }

    protected function downloadViaHttp(string $url, string $destination): void
    {
        $response = $this->http->get($url, ['sink' => $destination]);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('Failed to download video via HTTP.');
        }
    }

    protected function downloadViaFfmpeg(string $url, string $destination): void
    {
        $cmd = sprintf('ffmpeg -y -loglevel error -i %s -c copy %s', escapeshellarg($url), escapeshellarg($destination));
        $this->runCommand($cmd, 'Failed to download HLS stream via ffmpeg.');
    }

    protected function downloadViaYtDlp(string $url, string $destination): void
    {
        $ytDlp = trim((string) shell_exec('command -v yt-dlp'));
        if ($ytDlp === '') {
            throw new \RuntimeException('yt-dlp is not installed. Run bin/install-yt-dlp.sh.');
        }

        $base = substr($destination, 0, -strlen('.mp4'));
        $cmd = sprintf(
            '%s --no-progress --recode-video mp4 --write-auto-sub --sub-lang en --sub-format vtt -o %s %s',
            escapeshellcmd($ytDlp),
            escapeshellarg($base . '.%(ext)s'),
            escapeshellarg($url)
        );
        $this->runCommand($cmd, 'Failed to download video via yt-dlp.');

        $downloaded = glob($base . '.mp4');
        if (!$downloaded) {
            throw new \RuntimeException('Unable to find yt-dlp output file.');
        }
        rename($downloaded[0], $destination);

        $captionMatches = glob($base . '*.vtt');
        $this->recentCaptionCandidates = $captionMatches ?: [];
    }

    protected function downloadCaptions(VideoDownloadJob $job): ?string
    {
        if ($job->isSenate() && !empty($this->recentCaptionCandidates)) {
            return $this->recentCaptionCandidates[0];
        }

        $metadata = $job->metadata;
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
        $sql = 'UPDATE files SET path = :path, capture_directory = :dir, length = :length,
            width = :width, height = :height, fps = :fps, webvtt = :webvtt,
            date_modified = CURRENT_TIMESTAMP WHERE id = :id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':path' => $s3Url,
            ':dir' => $s3Key,
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
        if (str_contains($normalized, 'granicus.com') && str_contains($normalized, '.mp4')) {
            return true;
        }
        return false;
    }
}
