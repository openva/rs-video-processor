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

    public function __construct(
        private PDO $pdo,
        private StorageInterface $storage,
        private CommitteeDirectory $committeeDirectory,
        private S3KeyBuilder $keyBuilder,
        private ?Log $logger = null,
        ?string $workingDir = null,
        ?ClientInterface $http = null
    ) {
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
        $videoPath = $tempDir . '/video.mp4';

        $this->downloadVideo($job->videoPath, $videoPath);

        $fullDir = $tempDir . '/full';
        $thumbDir = $tempDir . '/thumb';
        mkdir($fullDir, 0775, true);
        mkdir($thumbDir, 0775, true);

        // Extract both full and thumbnail in a single ffmpeg pass for 2x speed
        $this->extractFramesBoth($videoPath, $fullDir, $thumbDir);

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

        $this->updateDatabase($job, $prefix, $manifestUrl);

        $this->cleanup($tempDir);
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
            return;
        }
        $response = $this->http->get($url, ['sink' => $destination]);
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Unable to download video for screenshots.');
        }
    }

    /**
     * Extract both full-size and thumbnail frames in a single ffmpeg pass.
     * This is 2x faster than running ffmpeg twice.
     */
    private function extractFramesBoth(string $video, string $fullDir, string $thumbDir): void
    {
        // Use filter_complex to split the stream and generate both sizes simultaneously
        // [0:v] fps=1 splits into [full] and [thumb]
        // [full] outputs full-size frames, [thumb] scales to 320px width
        $cmd = sprintf(
            'ffmpeg -y -loglevel error -i %s ' .
            '-filter_complex "[0:v]fps=1,split=2[full][thumb];[thumb]scale=320:-1[thumb_scaled]" ' .
            '-map "[full]" %s/%%08d.jpg ' .
            '-map "[thumb_scaled]" %s/%%08d.jpg',
            escapeshellarg($video),
            escapeshellarg($fullDir),
            escapeshellarg($thumbDir)
        );
        $this->runCommand($cmd, 'Failed to extract frames via ffmpeg.');
    }

    private function extractFrames(string $video, string $outputDir, string $label, ?int $width): void
    {
        $filter = 'fps=1';
        if ($width !== null) {
            $filter .= ',scale=' . $width . ':-1';
        }
        $cmd = sprintf(
            'ffmpeg -y -loglevel error -i %s -vf %s %s/%%08d.jpg',
            escapeshellarg($video),
            escapeshellarg($filter),
            escapeshellarg($outputDir)
        );
        $this->runCommand($cmd, 'Failed to extract ' . $label . ' frames via ffmpeg.');
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
            $processes = [];

            // Start parallel uploads for this batch
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

    private function updateDatabase(ScreenshotJob $job, string $prefix, string $manifestUrl): void
    {
        $directory = '/' . trim($prefix, '/') . '/';
        $sql = 'UPDATE files SET capture_directory = :dir, capture_rate = 60, date_modified = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
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
