<?php

namespace RichmondSunlight\VideoProcessor\Screenshots;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Log;
use PDO;
use RichmondSunlight\VideoProcessor\Fetcher\BatchStorageInterface;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\StorageInterface;
use RichmondSunlight\VideoProcessor\Fetcher\VideoValidator;
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
        // Videos run to several GB and archive.org is slow; ten minutes was not enough.
        $this->http = $http ?? new Client(['timeout' => 3600, 'connect_timeout' => 30]);
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
        // Only our own bucket holds keys with literal '+' and '%' that need
        // escaping again. archive.org URLs are already correctly encoded.
        $fetchUrl = str_contains($url, '://video.richmondsunlight.com/') ? $this->encodeUrlPath($url) : $url;
        $response = $this->http->get($fetchUrl, ['sink' => $destination]);
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
        (new VideoValidator())->assertValid($path);
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


    /** How many frames to upload at once when the storage can batch. */
    private const UPLOAD_CONCURRENCY = 20;

    /**
     * Upload every frame and thumbnail, and return the manifest that lists them.
     * Storage that supports batches gets them all at once; anything else (the
     * test doubles) gets them one at a time.
     *
     * @param string[] $frameFiles full-size frames, in order
     * @return array<int,array{timestamp:int,full:string,thumb:?string}>
     */
    private function uploadFramesParallel(array $frameFiles, string $thumbDir, string $prefix): array
    {
        $files = [];
        $entries = [];
        foreach (array_values($frameFiles) as $index => $fullImage) {
            $basename = basename($fullImage);
            $thumbImage = $thumbDir . '/' . $basename;

            $fullKey = $prefix . '/' . $basename;
            $files[$fullKey] = $fullImage;

            $thumbKey = null;
            if (file_exists($thumbImage)) {
                $thumbKey = $prefix . '/' . preg_replace('/\.jpg$/', '-thumbnail.jpg', $basename);
                $files[$thumbKey] = $thumbImage;
            }

            $entries[] = ['timestamp' => $index, 'full' => $fullKey, 'thumb' => $thumbKey];
        }

        if ($this->storage instanceof BatchStorageInterface) {
            $urls = $this->storage->uploadBatch($files, self::UPLOAD_CONCURRENCY);
        } else {
            $urls = [];
            foreach ($files as $key => $path) {
                $urls[$key] = $this->storage->upload($path, $key);
            }
        }

        return array_map(static fn(array $entry): array => [
            'timestamp' => $entry['timestamp'],
            'full' => $urls[$entry['full']],
            'thumb' => $entry['thumb'] !== null ? $urls[$entry['thumb']] : null,
        ], $entries);
    }

    /**
     * The directory screenshots are uploaded to. It always ends in the file ID,
     * because several file rows can point at one legacy S3 object and each
     * needs its own frames. The job's existing capture_directory is ignored:
     * reading it back as a prefix is what produced doubled slashes.
     */
    private function buildScreenshotPrefix(ScreenshotJob $job): string
    {
        $videoKey = $job->videoKey();
        if ($videoKey) {
            $prefix = (string) preg_replace('/\.mp4$/', '', trim($videoKey, '/'));
        } else {
            $shortname = $job->committeeId ? $this->committeeDirectory->getShortnameById($job->committeeId) : null;
            $prefix = (string) preg_replace('/\.mp4$/', '', $this->keyBuilder->build($job->chamber, $job->date, $shortname));
        }

        $suffix = '-' . $job->id;
        if (!str_ends_with($prefix, $suffix)) {
            $prefix .= $suffix;
        }

        return $prefix;
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
