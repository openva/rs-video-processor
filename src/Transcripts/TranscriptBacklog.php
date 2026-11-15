<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use Aws\S3\S3Client;
use Log;

/**
 * The exchange between the server and Waldo's Mac for the old videos without
 * captions (TranscriptJobQueue::backlog()), through the S3 bucket, since the
 * instance is off most of the time and the Mac cannot reach the database.
 *
 * Every pipeline pass, the server stores the transcripts the Mac has left in
 * TRANSCRIPTS_PREFIX, deletes them, and publishes the list of videos still
 * waiting to LIST_KEY. scripts/transcribe_backlog.sh, on the Mac, reads the
 * list and leaves a transcript for each video in TRANSCRIPTS_PREFIX.
 */
final class TranscriptBacklog
{
    public const BUCKET = 'video.richmondsunlight.com';
    public const LIST_KEY = 'transcribe-backlog/backlog.json';
    public const TRANSCRIPTS_PREFIX = 'transcribe-backlog/transcripts/';

    public function __construct(private S3Client $s3, private string $bucket = self::BUCKET)
    {
    }

    /**
     * @param list<array<string,mixed>> $videos from TranscriptJobQueue::backlog()->listAll()
     */
    public function publishList(array $videos): void
    {
        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => self::LIST_KEY,
            'Body' => json_encode(['generated_at' => gmdate('c'), 'count' => count($videos), 'videos' => $videos], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'ContentType' => 'application/json',
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function fetchList(): array
    {
        $result = $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => self::LIST_KEY]);
        $list = json_decode((string) $result['Body'], true);

        return is_array($list['videos'] ?? null) ? $list['videos'] : [];
    }

    /** Leave a transcript made here for the server to store. */
    public function send(int $fileId, string $json): void
    {
        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => self::TRANSCRIPTS_PREFIX . $fileId . '.json',
            'Body' => $json,
            'ContentType' => 'application/json',
        ]);
    }

    /**
     * Store every transcript waiting in the bucket. Each one stored, or no
     * longer needed, or unreadable, is deleted; one that could not be stored
     * because of an error is kept for the next pass.
     *
     * @return array<string,int> how many of each BacklogImporter outcome
     */
    public function importAll(BacklogImporter $importer, bool $apply, ?Log $log = null): array
    {
        $tally = [];
        $pages = $this->s3->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket, 'Prefix' => self::TRANSCRIPTS_PREFIX]);
        foreach ($pages as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $key = (string) $object['Key'];
                try {
                    $made = json_decode((string) $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key])['Body'], true);
                    $outcome = $importer->import($made, $apply);
                } catch (\Throwable $e) {
                    $log?->put(sprintf('Could not store the transcript in %s; it will be tried again: %s', $key, $e->getMessage()), 5);
                    $tally['error'] = ($tally['error'] ?? 0) + 1;
                    continue;
                }
                $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
                if ($outcome === BacklogImporter::INVALID) {
                    $log?->put(sprintf('%s is not a transcript of a video in the database; deleting it.', $key), 5);
                } elseif ($outcome === BacklogImporter::STORED) {
                    $log?->put(sprintf('Stored the transcript of file #%d made by %s elsewhere.', $made['file_id'], $made['model'] ?? 'Whisper'), 3);
                }
                if ($apply && $outcome !== BacklogImporter::WOULD_STORE) {
                    $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
                }
            }
        }

        return $tally;
    }
}
