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
        $this->logger?->put(
            sprintf(
                'Processing %s video #%d (%s) for %s',
                $job->chamber,
                $job->id,
                $job->remoteUrl,
                $job->date
            ),
            3
        );

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

        if ($this->isYouTubeUrl($job->remoteUrl)) {
            $this->downloadViaYtDlp($job->remoteUrl, $localPath);
        } elseif ($this->isGranicusUrl($job->remoteUrl)) {
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

    protected function downloadViaYtDlp(string $url, string $destination): void
    {
        // Verify yt-dlp is available
        exec('which yt-dlp 2>/dev/null', $output, $status);
        if ($status !== 0) {
            throw new \RuntimeException('yt-dlp is not installed or not in PATH. Install: pip install yt-dlp');
        }

        // Check yt-dlp version for debugging
        exec('yt-dlp --version 2>&1', $versionOutput, $versionStatus);
        $version = $versionStatus === 0 ? trim($versionOutput[0] ?? 'unknown') : 'unknown';
        $this->logger?->put("Using yt-dlp version: {$version}", 3);

        // Remove the destination file extension to let yt-dlp add it
        $destinationBase = preg_replace('/\.mp4$/', '', $destination);

        // Use cookies file to avoid bot detection
        $cookiesArg = '';

        if (defined('YTDLP_COOKIES_FILE') && YTDLP_COOKIES_FILE !== '' && file_exists(YTDLP_COOKIES_FILE)) {
            $cookiesArg = '--cookies ' . escapeshellarg(YTDLP_COOKIES_FILE);
            $this->logger?->put('Using cookies from file: ' . YTDLP_COOKIES_FILE, 3);
        } else {
            $cookiesFile = defined('YTDLP_COOKIES_FILE') ? YTDLP_COOKIES_FILE : '(not configured)';
            throw new \RuntimeException(
                "YouTube cookies file not found at: {$cookiesFile}\n" .
                "YouTube downloads require cookies to bypass bot detection.\n" .
                "Export cookies from YouTube using 'Get cookies.txt LOCALLY' extension and upload to server."
            );
        }

        $cmd = sprintf(
            'yt-dlp -f "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best" ' .
            '--merge-output-format mp4 --write-auto-sub --sub-lang en ' .
            '--convert-subs vtt --no-abort-on-error --ignore-errors %s -o %s %s 2>&1',
            $cookiesArg,
            escapeshellarg($destinationBase . '.%(ext)s'),
            escapeshellarg($url)
        );

        $this->logger?->put("Running yt-dlp command: {$cmd}", 3);
        $this->runCommand($cmd, sprintf('Failed to download YouTube video via yt-dlp from %s', $url));

        // Verify file was created
        if (!file_exists($destination) || filesize($destination) === 0) {
            throw new \RuntimeException(sprintf(
                'yt-dlp download did not create file at %s (source: %s)',
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

        // For YouTube videos, check if yt-dlp downloaded captions automatically
        if (isset($metadata['youtube_id']) || (isset($metadata['source']) && $metadata['source'] === 'senate-youtube')) {
            $vttPath = $this->downloadDir . '/' . $job->chamber . '-' . $job->id . '.en.vtt';
            if (file_exists($vttPath)) {
                return $vttPath;
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
            $outputStr = implode("\n", $output);

            // Detect YouTube bot detection / expired cookies error
            if (str_contains($outputStr, 'Sign in to confirm you\'re not a bot')) {
                // Log at severity 7 (highest severity for critical errors)
                $this->logger?->put(
                    'CRITICAL: YouTube cookies have expired or are invalid. ' .
                    'Export fresh cookies from your browser using "Get cookies.txt LOCALLY" extension ' .
                    'and upload to ' . (defined('YTDLP_COOKIES_FILE') ? YTDLP_COOKIES_FILE : '/home/ubuntu/youtube-cookies.txt'),
                    7
                );
                throw new YouTubeCookiesExpiredException(
                    'YouTube cookies expired. Bot detection error: ' . $outputStr
                );
            }

            throw new \RuntimeException(
                $errorMessage . "\nCommand output:\n" . $outputStr
            );
        }
    }

    private function isYouTubeUrl(string $url): bool
    {
        $normalized = strtolower($url);
        return str_contains($normalized, 'youtube.com') || str_contains($normalized, 'youtu.be');
    }

    private function isGranicusUrl(string $url): bool
    {
        $normalized = strtolower($url);
        return str_contains($normalized, 'granicus.com') && str_contains($normalized, '.mp4');
    }
}
