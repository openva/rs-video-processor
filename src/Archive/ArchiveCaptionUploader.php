<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use GuzzleHttp\Client;
use Log;
use PDO;
use RichmondSunlight\VideoProcessor\Jobs\NotReadyYet;
use RuntimeException;

/**
 * Put an archived video's captions on its Internet Archive item, replacing
 * any earlier ones under the same name. The archive's player shows captions
 * named after the video ("myvid.mp4" with "myvid.en.vtt"), so that is the
 * name used. The website plays its own captions, from files.webvtt, so
 * nothing waits for the archive to finish processing the file.
 */
class ArchiveCaptionUploader
{
    public const SENT = 'sent';
    /** The item already has these captions under the right name. */
    public const UNCHANGED = 'unchanged';
    /** The video is not archived, or has no captions to send. */
    public const NOTHING = 'nothing';

    /** Captions that change while being sent are sent again, at most this many times in all. */
    private const MAX_SENDS = 3;

    /** @var \Closure(): PDO */
    private \Closure $pdoFactory;
    private $commandRunner;
    private $metadataFetcher;
    private Client $http;

    /**
     * @param \Closure(): PDO $pdoFactory a fresh connection; uploads take time
     * @param (callable(string, array): int)|null $commandRunner runs a shell command, filling the array with its output
     * @param (callable(string): ?array)|null $metadataFetcher the archive's metadata API reply for an identifier
     */
    public function __construct(
        \Closure $pdoFactory,
        private ?Log $logger = null,
        ?callable $commandRunner = null,
        ?callable $metadataFetcher = null,
        ?Client $http = null
    ) {
        $this->pdoFactory = $pdoFactory;
        $this->commandRunner = $commandRunner ?? function (string $command, array &$output): int {
            exec($command, $output, $status);
            return $status;
        };
        $this->metadataFetcher = $metadataFetcher;
        $this->http = $http ?? new Client(['timeout' => 30, 'http_errors' => false]);
    }

    /**
     * @return string SENT, UNCHANGED or NOTHING
     * @throws NotReadyYet while the archive is processing the item, or turning requests away
     * @throws RuntimeException when the item cannot be read or the upload fails
     */
    public function send(int $fileId): string
    {
        $file = $this->file($fileId);
        if ($file === null) {
            return self::NOTHING;
        }
        $identifier = CaptionNameFixer::identifierFromPath($file['path']);
        $video = CaptionNameFixer::fileFromPath($file['path']);
        if ($identifier === null || $video === null || !str_ends_with(strtolower($video), '.mp4')) {
            return self::NOTHING;
        }
        // Not rebuilt with IdentifierBuilder: items uploaded before mid-2026 are named differently.
        $name = substr($video, 0, -4) . '.en.vtt';

        $item = $this->fetchMetadata($identifier);
        if ($item === null || empty($item['files'])) {
            throw new RuntimeException(sprintf('Could not read the Internet Archive item %s.', $identifier));
        }
        if (self::md5Of($item, $name) === md5($file['captions'])) {
            return self::UNCHANGED;
        }
        if (!empty($item['pending_tasks'])) {
            // Replacing a file while the archive is still working on the item can race with it.
            throw new NotReadyYet('The Internet Archive is still processing ' . $identifier . '.', InternetArchiveUploader::RECHECK_SECONDS);
        }

        for ($send = 1;; $send++) {
            $this->upload($identifier, $name, $file['captions']);
            $this->logger?->put(sprintf('Sent the captions of file #%d to the Internet Archive as %s/%s.', $fileId, $identifier, $name), 3);

            // The transcript stage may have written new captions meanwhile.
            $now = $this->file($fileId);
            if ($now === null || $now['captions'] === $file['captions'] || $send >= self::MAX_SENDS) {
                return self::SENT;
            }
            $file = $now;
        }
    }

    /**
     * @return array{path:string, captions:string}|null null if there is nothing to send
     */
    private function file(int $fileId): ?array
    {
        $stmt = ($this->pdoFactory)()->prepare('SELECT path, webvtt, caption_alignment FROM files WHERE id = :id');
        $stmt->execute([':id' => $fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $captions = ArchiveCaptions::sendable($row['webvtt'], $row['caption_alignment']);

        return $captions === null ? null : ['path' => (string) $row['path'], 'captions' => $captions];
    }

    private function upload(string $identifier, string $name, string $captions): void
    {
        // ia names the file in the item after the local file, so it gets a folder of its own.
        $dir = sys_get_temp_dir() . '/ia_captions_' . uniqid('', true);
        if (!mkdir($dir, 0700)) {
            throw new RuntimeException('Unable to create a working directory for the captions.');
        }
        $path = $dir . '/' . $name;
        file_put_contents($path, $captions);

        // TQDM_DISABLE turns off ia's progress bar, which otherwise fills the log.
        $command = sprintf('TQDM_DISABLE=1 ia upload %s %s 2>&1', escapeshellarg($identifier), escapeshellarg($path));
        $output = [];
        try {
            $status = call_user_func_array($this->commandRunner, [$command, &$output]);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
        if ($status === 0) {
            return;
        }

        $message = trim(implode('; ', InternetArchiveUploader::withoutProgressBars($output)));
        $message = $message !== '' ? $message : 'No output from ia upload command.';
        if (ArchiveCaptions::isRateLimited($message)) {
            throw new NotReadyYet('The Internet Archive is turning uploads away for now: ' . $message, InternetArchiveUploader::BUSY_RECHECK_SECONDS, true);
        }
        throw new RuntimeException('Uploading the captions failed: ' . $message);
    }

    /** The md5 the archive lists for a file in the item, or null if it has no such file. */
    private static function md5Of(array $item, string $name): ?string
    {
        foreach ($item['files'] ?? [] as $file) {
            if (($file['name'] ?? null) === $name) {
                return isset($file['md5']) ? (string) $file['md5'] : null;
            }
        }

        return null;
    }

    private function fetchMetadata(string $identifier): ?array
    {
        if ($this->metadataFetcher) {
            return ($this->metadataFetcher)($identifier);
        }
        try {
            $response = $this->http->get('https://archive.org/metadata/' . rawurlencode($identifier));
            if ($response->getStatusCode() >= 400) {
                return null;
            }
            $data = json_decode((string) $response->getBody(), true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            $this->logger?->put(sprintf('Failed to fetch Archive.org metadata for %s: %s', $identifier, $e->getMessage()), 5);
            return null;
        }
    }
}
