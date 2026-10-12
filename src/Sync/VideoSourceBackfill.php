<?php

namespace RichmondSunlight\VideoProcessor\Sync;

use PDO;

/**
 * Registers source IDs for rows imported before video_sources existed.
 *
 * Rows are read oldest first, so the oldest row owns each ID. A later row
 * with the same ID is a duplicate import; it is reported and left alone.
 */
class VideoSourceBackfill
{
    public function __construct(private PDO $pdo, private int $batchSize = 100)
    {
    }

    /**
     * @return array{registered:int,duplicates:array<int,array{file_id:int,duplicate_of:int,source:string,source_id:string}>}
     */
    public function run(): array
    {
        $registry = new VideoSourceRegistry($this->pdo);
        $registered = 0;
        $duplicates = [];
        $lastId = 0;

        // video_index_cache can be large, so read in small batches by id.
        $stmt = $this->pdo->prepare(
            'SELECT id, video_index_cache FROM files
             WHERE id > :last AND video_index_cache IS NOT NULL
             ORDER BY id ASC LIMIT ' . max(1, $this->batchSize)
        );

        do {
            $stmt->execute([':last' => $lastId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $record = json_decode((string) $row['video_index_cache'], true);
                if (!is_array($record)) {
                    continue;
                }
                $identity = VideoSourceRegistry::identityOf($record);
                if ($identity === null) {
                    continue;
                }

                $owner = $registry->findFileId($identity['source'], $identity['source_id']);
                if ($owner === null) {
                    $registry->register($lastId, $identity['source'], $identity['source_id']);
                    $registered++;
                } elseif ($owner !== $lastId) {
                    $duplicates[] = [
                        'file_id' => $lastId,
                        'duplicate_of' => $owner,
                        'source' => $identity['source'],
                        'source_id' => $identity['source_id'],
                    ];
                }
            }
        } while (!empty($rows));

        return ['registered' => $registered, 'duplicates' => $duplicates];
    }
}
