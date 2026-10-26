<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use Aws\S3\ObjectCopier;
use Aws\S3\S3ClientInterface;
use DateTimeImmutable;

/**
 * Moves videos that are no longer played from S3 to Glacier Instant Retrieval.
 *
 * Once a video is on the Internet Archive, files.path points there, and the
 * S3 copy is kept only as a backup. Glacier Instant Retrieval stores it for
 * about a sixth of the price of Standard ($0.004 against $0.023 per GB-month)
 * and still serves it at once, at $0.03 a GB, if it is ever read again. Objects
 * there are billed for at least 90 days, which a backup outlives.
 */
class ColdStorage
{
    public const STORAGE_CLASS = 'GLACIER_IR';

    /** Storage classes worth moving out of. */
    private const WARM = ['STANDARD', 'REDUCED_REDUNDANCY'];

    /**
     * A video uploaded more recently than this may belong to a download whose
     * files row has not been updated yet, so it is left alone.
     */
    private const MIN_AGE_HOURS = 48;

    public function __construct(
        private S3ClientInterface $s3,
        private string $bucket = BucketReader::PUBLIC_HOST
    ) {
    }

    /**
     * Copy the object onto itself in the cold storage class. Its ACL
     * (public-read, as S3Storage uploads it) and metadata are kept.
     *
     * @return string 'moved', or 'already' if it was in cold storage
     */
    public function move(string $key): string
    {
        $head = $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
        // S3 leaves StorageClass out for Standard.
        if (($head['StorageClass'] ?? 'STANDARD') === self::STORAGE_CLASS) {
            return 'already';
        }

        $location = ['Bucket' => $this->bucket, 'Key' => $key];
        (new ObjectCopier($this->s3, $location, $location, 'public-read', [
            'params' => ['StorageClass' => self::STORAGE_CLASS],
        ]))->copy();

        return 'moved';
    }

    /**
     * Every video in the bucket. Videos sit directly under <chamber>/floor/
     * and <chamber>/committee/<name>/; the date directories beside them hold
     * millions of screenshots, which a delimited listing does not open.
     *
     * @return list<array{key:string,size:int,storageClass:string,lastModified:DateTimeImmutable}>
     */
    public function videos(): array
    {
        $videos = [];
        foreach (['house', 'senate'] as $chamber) {
            $this->collect($chamber . '/floor/', $videos);
            foreach ($this->prefixes($chamber . '/committee/') as $committee) {
                $this->collect($committee, $videos);
            }
        }

        return $videos;
    }

    /**
     * The videos to move: in a warm storage class, old enough, and not what
     * any files row plays (files.path on S3).
     *
     * @param list<array{key:string,size:int,storageClass:string,lastModified:DateTimeImmutable}> $videos
     * @param string[] $playedKeys keys of the files rows whose path is on S3
     * @return list<array{key:string,size:int,storageClass:string,lastModified:DateTimeImmutable}>
     */
    public static function toMove(array $videos, array $playedKeys, DateTimeImmutable $now): array
    {
        $played = array_flip($playedKeys);
        $cutoff = $now->modify('-' . self::MIN_AGE_HOURS . ' hours');

        return array_values(array_filter(
            $videos,
            static fn(array $video) => in_array($video['storageClass'], self::WARM, true)
                && !isset($played[$video['key']])
                && $video['lastModified'] < $cutoff
        ));
    }

    private function collect(string $prefix, array &$videos): void
    {
        foreach ($this->s3->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket, 'Prefix' => $prefix, 'Delimiter' => '/']) as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                if (!preg_match('/\.(mp4|m4v|mov)$/i', $object['Key'])) {
                    continue;
                }
                $videos[] = [
                    'key' => $object['Key'],
                    'size' => (int) $object['Size'],
                    'storageClass' => (string) ($object['StorageClass'] ?? 'STANDARD'),
                    'lastModified' => new DateTimeImmutable((string) $object['LastModified']),
                ];
            }
        }
    }

    /** @return string[] */
    private function prefixes(string $prefix): array
    {
        $prefixes = [];
        foreach ($this->s3->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket, 'Prefix' => $prefix, 'Delimiter' => '/']) as $page) {
            foreach ($page['CommonPrefixes'] ?? [] as $common) {
                $prefixes[] = $common['Prefix'];
            }
        }

        return $prefixes;
    }
}
