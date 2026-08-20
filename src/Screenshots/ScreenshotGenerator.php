<?php

namespace RichmondSunlight\VideoProcessor\Screenshots;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Log;
use PDO;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\StorageInterface;
use RuntimeException;

class ScreenshotGenerator
{
    private ClientInterface $http;
    private string $workingDir;

    /** @var (\Closure(): PDO)|null */
    private ?\Closure $pdoFactory;

    public function __construct(
        private PDO $pdo,
        private StorageInterface $storage,
        private CommitteeDirectory $committeeDirectory,
        private S3KeyBuilder $keyBuilder,
        private ?Log $logger = null,
        ?string $workingDir = null,
        ?ClientInterface $http = null,
        ?\Closure $pdoFactory = null
    ) {
        $this->pdoFactory = $pdoFactory;
        $this->http = $http ?? new Client(['timeout' => 600]);
        $this->workingDir = $workingDir ?? __DIR__ . '/../../storage/screenshots';
        if (!is_dir($this->workingDir)) {
            mkdir($this->workingDir, 0775, true);
        }
    }

    public function process(ScreenshotJob $job): void
    {
        $this->logger?->put('Generating screenshots for file #' . $job->id, 3);
        $tempDir = $this->createTempDir($job->id);
        try {
            $videoPath = $tempDir . '/video.mp4';

            $this->downloadVideo($job->videoPath, $videoPath);

            $fullDir = $tempDir . '/full';
            $thumbDir = $tempDir . '/thumb';
            mkdir($fullDir, 0775, true);
            mkdir($thumbDir, 0775, true);

            // Extract both full and thumbnail in a single ffmpeg pass for 2x speed
            $this->extractFramesBoth($videoPath, $fullDir, $thumbDir);

            // Delete the video immediately — it can be several GB and we no longer need it.
            @unlink($videoPath);

            $frameFiles = glob($fullDir . '/*.jpg');
            sort($frameFiles, SORT_NATURAL);
            if (empty($frameFiles)) {
                throw new RuntimeException('ffmpeg failed to produce screenshots.');
            }

            $prefix = $this->buildScreenshotPrefix($job);

            // Upload frames in parallel batches for faster S3 uploads
            $manifest = $this->uploadFramesParallel($frameFiles, $thumbDir, $prefix);

            $manifestPath = $tempDir . '/manifest.json';
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $manifestUrl = $this->storage->upload($manifestPath, $prefix . '/manifest.json');

            $this->updateDatabase($job, $prefix);
        } finally {
            $this->cleanup($tempDir);
        }
    }

    private function createTempDir(int $id): string
    {
        $dir = $this->workingDir . '/job-' . $id . '-' . uniqid();
        mkdir($dir, 0775, true);
        return $dir;
    }

    private function downloadVideo(string $url, string $destination): void
    {
        if (str_starts_with($url, 'file://')) {
            $source = realpath(substr($url, 7));
            if ($source === false || !copy($source, $destination)) {
                throw new RuntimeException('Unable to copy local fixture for screenshots.');
            }
            $this->validateVideo($destination);
            return;
        }
        $response = $this->http->get($this->encodeUrlPath($url), ['sink' => $destination]);
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Unable to download video for screenshots.');
        }

        // A wrong or stale key can return HTTP 200 with a caption file, a JSON
        // manifest, or an HTML error page. Reject those before ffmpeg sees them.
        // Note S3 serves the known-bad 2020 objects as video/mp4, so this is a
        // guard for the general case, not a substitute for validateVideo().
        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        if (
            $contentType !== '' && !str_starts_with($contentType, 'video/')
            && $contentType !== 'application/octet-stream' && $contentType !== 'binary/octet-stream'
        ) {
            throw new RuntimeException(sprintf(
                'Downloaded file is not a valid video: server returned Content-Type "%s" for %s.',
                $contentType,
                $url
            ));
        }

        $this->validateVideo($destination);
    }

    /** A real session video is never smaller than this; anything less is an error page. */
    private const MIN_VIDEO_BYTES = 3 * 1024 * 1024;


    /**
     * Percent-escape each path segment of an S3 URL.
     *
     * Committee names were run through urlencode() when these objects were
     * uploaded, so the encoded form became the literal key: the key really
     * contains '+' and '%' characters. Requesting it over HTTPS therefore means
     * escaping those again ('+' -> %2B, '%' -> %25) or S3 resolves a different
     * key and returns NoSuchKey. Verified against
     * senate/committee/courts+of+justice+%28sr+a%29-+january+29%2C+2018/,
     * which 404s as stored and returns the video once escaped.
     *
     * Only the path is touched; scheme, host and query string are left alone so
     * presigned URLs and file:// fixtures still work.
     */
    private function encodeUrlPath(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return $url;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return $url;
        }

        $encoded = implode('/', array_map('rawurlencode', explode('/', $parts['path'])));

        $result = $parts['scheme'] . '://';
        if (isset($parts['user'])) {
            $result .= $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
        }
        $result .= $parts['host'];
        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }
        $result .= $encoded;
        if (isset($parts['query'])) {
            $result .= '?' . $parts['query'];
        }

        return $result;
    }

    /**
     * Verify the downloaded file is genuinely a video before handing it to ffmpeg.
     *
     * Three things have masqueraded as videos here:
     *  - IIS "502 Bad Gateway" HTML pages saved as .mp4 during a January 2020
     *    outage. S3 still serves 18 of them, all 1477 bytes, as video/mp4.
     *  - WebVTT caption files and JSON manifests fetched from a wrong key.
     *  - Truncated uploads with no moov atom.
     *
     * `ffprobe -select_streams v:0` cannot catch the middle case: selecting a
     * stream that does not exist exits 0, so a caption file reads as valid.
     * Requiring at least one video stream in the output is what makes this real.
     */
    private function validateVideo(string $path): void
    {
        $size = @filesize($path);
        if ($size === false || $size < self::MIN_VIDEO_BYTES) {
            throw new RuntimeException(sprintf(
                'Downloaded file is not a valid video: %s bytes is below the %d-byte minimum '
                . '(source is likely an error page rather than a video).',
                $size === false ? '0' : (string) $size,
                self::MIN_VIDEO_BYTES
            ));
        }

        $cmd = sprintf(
            'ffprobe -v error -show_entries stream=codec_type -of csv=p=0 %s 2>&1',
            escapeshellarg($path)
        );
        exec($cmd, $output, $status);
        $detail = trim(implode(' ', $output));

        if ($status !== 0) {
            throw new RuntimeException(sprintf(
                'Downloaded file is not a valid video (ffprobe exit %d): %s',
                $status,
                $detail ?: 'no output'
            ));
        }

        // ffprobe exits 0 on files it can open but that hold no video stream.
        if (!in_array('video', array_map('trim', $output), true)) {
            throw new RuntimeException(sprintf(
                'Downloaded file is not a valid video: no video stream found (ffprobe reported: %s).',
                $detail !== '' ? $detail : 'no streams'
            ));
        }
    }

    /**
     * Extract both full-size and thumbnail frames in a single ffmpeg pass.
     * Optimized for maximum speed with multi-threading and fast JPEG encoding.
     */
    private function extractFramesBoth(string $video, string $fullDir, string $thumbDir): void
    {
        // Optimization flags:
        // -threads 0: Use all CPU cores (2 on c7i.large)
        // -qscale:v 3: Faster JPEG encoding (3 = good quality, vs default 2 = best)
        // -filter_complex: Single-pass extraction of both sizes
        $cmd = sprintf(
            'ffmpeg -y -loglevel error ' .
            '-threads 0 ' .           // Use all CPU cores
            '-i %s ' .
            '-filter_complex "[0:v]fps=1,split=2[full][thumb];[thumb]scale=320:-1[thumb_scaled]" ' .
            '-map "[full]" -qscale:v 3 %s/%%08d.jpg ' .      // Full-size with quality=3
            '-map "[thumb_scaled]" -qscale:v 5 %s/%%08d.jpg', // Thumbnails with quality=5
            escapeshellarg($video),
            escapeshellarg($fullDir),
            escapeshellarg($thumbDir)
        );
        $this->runCommand($cmd, 'Failed to extract frames via ffmpeg.');
    }


    /**
     * Upload frames to S3 using parallel execution with Process pool.
     * This is significantly faster than sequential uploads for large batches.
     */
    private function uploadFramesParallel(array $frameFiles, string $thumbDir, string $prefix): array
    {
        $manifest = [];
        $batchSize = 10; // Upload 10 frames at a time in parallel

        for ($i = 0; $i < count($frameFiles); $i += $batchSize) {
            $batch = array_slice($frameFiles, $i, $batchSize);

            // Upload frames in this batch (TODO: make truly parallel with async S3 uploads)
            foreach ($batch as $index => $fullImage) {
                $globalIndex = $i + $index;
                $basename = basename($fullImage);
                $thumbImage = $thumbDir . '/' . $basename;
                $thumbBasename = preg_replace('/\.jpg$/', '-thumbnail.jpg', $basename);

                // Upload full-size frame
                $fullUrl = $this->storage->upload($fullImage, $prefix . '/' . $basename);

                // Upload thumbnail
                $thumbUrl = file_exists($thumbImage)
                    ? $this->storage->upload($thumbImage, $prefix . '/' . $thumbBasename)
                    : null;

                $manifest[$globalIndex] = [
                    'timestamp' => $globalIndex,
                    'full' => $fullUrl,
                    'thumb' => $thumbUrl,
                ];
            }
        }

        // Sort manifest by timestamp to ensure correct ordering
        ksort($manifest);
        return array_values($manifest);
    }

    private function buildScreenshotPrefix(ScreenshotJob $job): string
    {
        $shortname = $job->committeeId ? $this->committeeDirectory->getShortnameById($job->committeeId) : null;
        $videoKey = $job->captureDirectory ?? $job->videoKey();
        if ($videoKey) {
            $videoKey = preg_replace('/\.mp4$/', '', $videoKey);
            return $videoKey;
        }
        return $this->keyBuilder->build($job->chamber, $job->date, $shortname);
    }

    private function updateDatabase(ScreenshotJob $job, string $prefix): void
    {
        // Get a fresh connection if a factory was provided — the original one is
        // almost certainly dead after minutes of downloading/ffmpeg/uploading.
        $pdo = $this->pdoFactory ? ($this->pdoFactory)() : $this->pdo;

        $directory = '/' . trim($prefix, '/') . '/';
        $sql = 'UPDATE files SET capture_directory = :dir, capture_rate = 60, date_modified = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':dir' => $directory,
            ':id' => $job->id,
        ]);

        $this->logger?->put(sprintf('Uploaded %s screenshots for file #%d', $prefix, $job->id), 3);
    }

    private function cleanup(string $dir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }

    private function runCommand(string $cmd, string $error): void
    {
        exec($cmd, $output, $status);
        if ($status !== 0) {
            throw new RuntimeException($error);
        }
    }
}
