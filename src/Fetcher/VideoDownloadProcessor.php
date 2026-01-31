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

        try {
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
        } catch (\Throwable $e) {
            // Check if this is a YouTube video that we can provide a fallback for
            if ($this->isYouTubeUrl($job->remoteUrl)) {
                $youtubeId = $this->extractYouTubeId($job);

                if ($youtubeId !== null) {
                    // Determine if this is a permanent failure or transient error
                    $isPermanentFailure = $this->isPermanentFailure($e);

                    if ($isPermanentFailure) {
                        $this->logger?->put(
                            sprintf(
                                'YouTube download failed permanently for video #%d: %s. Creating embed fallback for video ID: %s',
                                $job->id,
                                $e->getMessage(),
                                $youtubeId
                            ),
                            5
                        );

                        // Generate and save embed HTML as fallback
                        $errorReason = $this->getErrorReason($e);
                        $embedHtml = $this->generateYouTubeEmbedHtml($youtubeId, $errorReason);
                        $this->updateDatabaseWithFallbackHtml($job, $embedHtml);

                        // Index metadata even though download failed
                        $this->metadataIndexer?->index($job->id, $job->metadata);

                        $this->logger?->put(
                            sprintf('Saved YouTube embed fallback HTML for video #%d', $job->id),
                            3
                        );

                        return; // Success - fallback saved
                    }
                } else {
                    $this->logger?->put(
                        sprintf(
                            'Could not extract YouTube ID from video #%d (%s), cannot create embed fallback',
                            $job->id,
                            $job->remoteUrl
                        ),
                        4
                    );
                }
            }

            // Re-throw for non-YouTube videos, transient errors, or when no YouTube ID available
            throw $e;
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

    /**
     * Extract YouTube video ID from job metadata or URL.
     */
    private function extractYouTubeId(VideoDownloadJob $job): ?string
    {
        // First, check if metadata already has the YouTube ID (most reliable)
        if (!empty($job->metadata['youtube_id'])) {
            return $job->metadata['youtube_id'];
        }

        // Second, try to extract from embed_url in metadata
        if (!empty($job->metadata['embed_url'])) {
            if (preg_match('#youtube\.com/embed/([a-zA-Z0-9_-]+)#', $job->metadata['embed_url'], $matches)) {
                return $matches[1];
            }
        }

        // Finally, parse the remote URL directly
        $url = $job->remoteUrl;

        // Pattern 1: youtube.com/watch?v=ID
        if (preg_match('#[?&]v=([a-zA-Z0-9_-]+)#', $url, $matches)) {
            return $matches[1];
        }

        // Pattern 2: youtu.be/ID
        if (preg_match('#youtu\.be/([a-zA-Z0-9_-]+)#', $url, $matches)) {
            return $matches[1];
        }

        // Pattern 3: youtube.com/embed/ID
        if (preg_match('#youtube\.com/embed/([a-zA-Z0-9_-]+)#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Generate YouTube embed HTML as fallback when download fails.
     */
    private function generateYouTubeEmbedHtml(string $youtubeId, string $errorReason = ''): string
    {
        $embedUrl = 'https://www.youtube-nocookie.com/embed/' . htmlspecialchars($youtubeId, ENT_QUOTES, 'UTF-8');
        $errorAttr = $errorReason ? ' data-fallback-reason="' . htmlspecialchars($errorReason, ENT_QUOTES, 'UTF-8') . '"' : '';

        return <<<HTML
<!-- YouTube embed fallback: video download failed, displaying YouTube player instead -->
<div class="video-embed-container" style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; max-width: 100%;">
    <iframe
        src="{$embedUrl}"
        frameborder="0"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
        allowfullscreen{$errorAttr}>
    </iframe>
</div>
HTML;
    }

    /**
     * Update database with fallback embed HTML when video download fails.
     * This marks the record as processed (preventing retry) but with embedded player instead of downloaded file.
     */
    private function updateDatabaseWithFallbackHtml(VideoDownloadJob $job, string $html): void
    {
        $sql = 'UPDATE files SET html = :html, date_modified = CURRENT_TIMESTAMP WHERE id = :id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':html' => $html,
            ':id' => $job->id,
        ]);
    }

    /**
     * Determine if an exception represents a permanent failure (use fallback)
     * vs transient error (should retry).
     */
    private function isPermanentFailure(\Throwable $e): bool
    {
        // YouTubeCookiesExpiredException is permanent until cookies are refreshed
        if ($e instanceof YouTubeCookiesExpiredException) {
            return true;
        }

        $message = $e->getMessage();

        // Permanent failures (use fallback):
        $permanentIndicators = [
            'Sign in to confirm',           // Bot detection
            'Video unavailable',             // Deleted/private video
            'This video is private',         // Privacy restriction
            'This video has been removed',   // Deleted
            'HTTP Error 403',                // Forbidden
            'HTTP Error 404',                // Not found
            'This video is no longer available', // Removed
            'file is too small',             // Download produced invalid file
        ];

        foreach ($permanentIndicators as $indicator) {
            if (stripos($message, $indicator) !== false) {
                return true;
            }
        }

        // Transient failures (should retry):
        $transientIndicators = [
            'timeout',
            'timed out',
            'Connection refused',
            'Connection reset',
            'Network is unreachable',
            'Could not resolve host',
            'SSL',
            'certificate',
        ];

        foreach ($transientIndicators as $indicator) {
            if (stripos($message, $indicator) !== false) {
                return false;
            }
        }

        // Default to permanent for unknown errors (prevents infinite retry loops)
        return true;
    }

    /**
     * Extract a brief, user-friendly error reason from an exception.
     */
    private function getErrorReason(\Throwable $e): string
    {
        if ($e instanceof YouTubeCookiesExpiredException) {
            return 'Authentication required';
        }

        $message = $e->getMessage();

        if (stripos($message, 'Sign in to confirm') !== false) {
            return 'Bot detection';
        }
        if (stripos($message, 'Video unavailable') !== false || stripos($message, 'removed') !== false) {
            return 'Video unavailable';
        }
        if (stripos($message, 'private') !== false) {
            return 'Private video';
        }
        if (stripos($message, '403') !== false) {
            return 'Access forbidden';
        }
        if (stripos($message, '404') !== false) {
            return 'Video not found';
        }
        if (stripos($message, 'too small') !== false) {
            return 'Invalid download';
        }

        return 'Download failed';
    }
}
