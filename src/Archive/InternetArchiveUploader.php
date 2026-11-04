<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use Log;
use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Fetcher\BucketReader;
use RichmondSunlight\VideoProcessor\Jobs\NotReadyYet;
use RuntimeException;

class InternetArchiveUploader
{
    private $commandRunner;
    private $downloader;
    private $metadataFetcher;
    private Client $http;

    /** Seconds to wait after uploading before the first look for the video. */
    public int $firstCheckSeconds = 60;
    /**
     * The soonest the archive is asked again. A waiting job does not start the
     * instance (see JobLedger::notWaitingSql()), so the check happens on a
     * later pass of this run, or on whatever run comes next.
     */
    public const RECHECK_SECONDS = 600;
    /**
     * The soonest an upload is tried again after the archive turned it away
     * for the request rate. "total_tasks_queued exceeds global_limit" means
     * the archive's own task queue is full, which can last for hours.
     */
    public const BUSY_RECHECK_SECONDS = 3600;

    public function __construct(
        private ?Log $logger = null,
        ?callable $commandRunner = null,
        ?callable $downloader = null,
        ?callable $metadataFetcher = null,
        ?Client $http = null,
        ?BucketReader $bucket = null
    ) {
        $this->commandRunner = $commandRunner ?? function (string $command, array &$output): int {
            exec($command, $output, $status);
            return $status;
        };
        $this->downloader = $downloader ?? function (string $url) use ($bucket): string {
            $temp = tempnam(sys_get_temp_dir(), 'ia_video_');
            $tempWithExt = $temp . '.mp4';
            rename($temp, $tempWithExt);
            // Our own videos come straight from the bucket, which is free, rather than through CloudFront.
            if ($bucket?->download($url, $tempWithExt) !== null) {
                return $tempWithExt;
            }
            // -f: fail on an HTTP error instead of saving the error page as the video.
            $cmd = sprintf('curl -fL --retry 3 --silent --show-error %s -o %s', escapeshellarg($url), escapeshellarg($tempWithExt));
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

        // An earlier try may have uploaded it already, so ask the archive first.
        $item = $this->fetchMetadata($identifier);
        if ($item === null) {
            $this->logger?->put('Could not ask the Internet Archive about ' . $identifier . ', so file #' . $job->fileId . ' was not uploaded.', 5);
            return null;
        }
        $url = $this->mp4Url($identifier, $item);
        if ($url !== null) {
            $this->logger?->put('File #' . $job->fileId . ' is already on the Internet Archive.', 3);
            return $url;
        }
        if (!empty($item['pending_tasks'])) {
            throw new NotReadyYet(self::processing($item), self::RECHECK_SECONDS);
        }
        if ($item !== []) {
            $this->logger?->put(sprintf('%s exists without its video and has nothing pending; uploading file #%d again.', $identifier, $job->fileId), 4);
        }

        // ia names each file in the item after the local file, and the
        // archive's player shows captions only when they share the video's
        // name ("myvid.mp4" with "myvid.en.vtt"). So both are named after the
        // identifier, in a folder of their own.
        $dir = sys_get_temp_dir() . '/ia_upload_' . uniqid('', true);
        if (!mkdir($dir, 0700)) {
            throw new RuntimeException('Unable to create a working directory for the Internet Archive upload.');
        }
        $videoPath = $dir . '/' . $identifier . '.mp4';
        $downloaded = call_user_func($this->downloader, $job->s3Path);
        if (!rename($downloaded, $videoPath)) {
            @unlink($downloaded);
            @rmdir($dir);
            throw new RuntimeException('Unable to move the downloaded video into place for the Internet Archive.');
        }
        $tempFiles = [$videoPath];
        $captions = null;
        if ($job->webvtt) {
            $captions = $dir . '/' . $identifier . '.en.vtt';
        } elseif ($job->srt) {
            $captions = $dir . '/' . $identifier . '.en.srt';
        }
        if ($captions !== null) {
            file_put_contents($captions, $job->webvtt ?: $job->srt);
            $tempFiles[] = $captions;
        }

        $command = $this->buildCommand($identifier, $videoPath, $metadata, $captions);
        $output = [];
        try {
            $status = $this->invokeCommandRunner($command, $output);
        } finally {
            foreach ($tempFiles as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        if ($status !== 0) {
            $message = trim(implode('; ', self::withoutProgressBars($output)));
            $message = $message !== '' ? $message : 'No output from ia upload command.';
            if (ArchiveCaptions::isRateLimited($message)) {
                throw new NotReadyYet('The Internet Archive is turning uploads away for now: ' . $message, self::BUSY_RECHECK_SECONDS, true);
            }
            $this->logger?->put('Internet Archive upload failed for file #' . $job->fileId . ': ' . $message, 5);
            return null;
        }

        // The archive lists the video once it has filed it, which can take
        // anything from a minute to hours. Until then files.path stays on S3,
        // where the video still plays, and the job is put aside.
        if ($this->firstCheckSeconds > 0) {
            sleep($this->firstCheckSeconds);
        }
        $item = $this->fetchMetadata($identifier);
        $url = $item === null ? null : $this->mp4Url($identifier, $item);
        if ($url === null) {
            throw new NotReadyYet('Uploaded; ' . lcfirst(self::processing($item ?? [])), self::RECHECK_SECONDS);
        }

        return $url;
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
        // ia draws an upload progress bar with tqdm, which redraws itself hundreds
        // of times, and all of it ended up in the log when an upload failed.
        // TQDM_DISABLE turns off only the bar; ia's --quiet would hide its error
        // messages too.
        $parts = ['TQDM_DISABLE=1', 'ia', 'upload', escapeshellarg($identifier), escapeshellarg($videoPath)];
        foreach ($metadata as $key => $value) {
            $parts[] = '--metadata=' . escapeshellarg(sprintf('%s:%s', $key, $value));
        }
        if ($captionPath) {
            $parts[] = escapeshellarg($captionPath);
        }
        return implode(' ', $parts) . ' 2>&1';
    }

    /**
     * ia's output without progress bar redraws, for a tqdm too old to obey
     * TQDM_DISABLE. Each redraw starts with a carriage return and reads like
     * " uploading video.mp4:  45%|####   | 540/1205 [00:18<00:22, 30.1MiB/s]".
     *
     * @param string[] $lines
     * @return string[]
     */
    public static function withoutProgressBars(array $lines): array
    {
        $kept = [];
        foreach ($lines as $line) {
            foreach (explode("\r", (string) $line) as $part) {
                $part = trim($part);
                if ($part === '' || preg_match('/:\s*\d+%\|/', $part)) {
                    continue;
                }
                $kept[] = $part;
            }
        }

        return $kept;
    }

    private function fetchMetadata(string $identifier): ?array
    {
        if ($this->metadataFetcher) {
            return ($this->metadataFetcher)($identifier);
        }

        try {
            $url = sprintf('https://archive.org/metadata/%s', rawurlencode($identifier));
            $response = $this->http->get($url);
            if ($response->getStatusCode() >= 400) {
                $this->logger?->put(
                    sprintf('Archive.org metadata API returned status %d for %s', $response->getStatusCode(), $identifier),
                    5
                );
                return null;
            }
            $data = json_decode((string) $response->getBody(), true);
            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            $this->logger?->put(
                sprintf('Failed to fetch Archive.org metadata for %s: %s', $identifier, $e->getMessage()),
                5
            );
            return null;
        }
    }

    /**
     * The download URL of the video we uploaded: the item's largest original
     * MP4, not one the archive derived from it. Null when it is not listed yet.
     *
     * @param array<string,mixed> $item the metadata API's reply
     */
    private function mp4Url(string $identifier, array $item): ?string
    {
        $best = null;
        foreach ($item['files'] ?? [] as $file) {
            $name = $file['name'] ?? '';
            if (!is_string($name) || !str_ends_with(strtolower($name), '.mp4') || ($file['source'] ?? '') !== 'original') {
                continue;
            }
            if ($best === null || (int) ($file['size'] ?? 0) > (int) ($best['size'] ?? 0)) {
                $best = $file;
            }
        }

        return $best === null ? null : $this->buildDownloadUrl($identifier, $best['name']);
    }

    /**
     * What the archive is doing with an item, e.g. "The Internet Archive is
     * still processing it (archive.php queued)."
     *
     * @param array<string,mixed> $item
     */
    private static function processing(array $item): string
    {
        $tasks = [];
        foreach ($item['tasks'] ?? [] as $task) {
            $tasks[] = trim(($task['cmd'] ?? 'task') . ' ' . ($task['status'] ?? ''));
        }

        return 'The Internet Archive is still processing it' . ($tasks ? ' (' . implode(', ', $tasks) . ')' : '') . '.';
    }

    private function buildDownloadUrl(string $identifier, string $filename): string
    {
        $encoded = str_replace('%2F', '/', rawurlencode($filename));
        return sprintf('https://archive.org/download/%s/%s', rawurlencode($identifier), $encoded);
    }

    private function invokeCommandRunner(string $command, array &$output): int
    {
        if (is_array($this->commandRunner) || $this->commandRunner instanceof \Closure) {
            return call_user_func_array($this->commandRunner, [$command, &$output]);
        }
        return ($this->commandRunner)($command, $output);
    }
}
