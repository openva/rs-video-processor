<?php

namespace RichmondSunlight\VideoProcessor\Maintenance;

use PDO;
use RichmondSunlight\VideoProcessor\Sync\VideoSourceBackfill;

/**
 * Works out how to untangle files rows that share a video or a screenshot
 * directory. Read-only apart from the source-ID backfill, which only adds
 * missing video_sources rows.
 *
 * Two different things produce shared media, and they need different fixes:
 *  - One upstream video imported several times. The later rows are copies and
 *    should be deleted.
 *  - Different meetings whose files overwrote each other. At most one row shows
 *    the right video and there is no telling which, so every one is requeued.
 */
class SharedMediaPlanner
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{delete:int[],requeue:int[],unrecoverable:int[]} file IDs, ascending.
     *         "unrecoverable" rows share media but have no recorded source URL to download from again.
     */
    public function plan(): array
    {
        $delete = [];
        foreach ((new VideoSourceBackfill($this->pdo))->run()['duplicates'] as $duplicate) {
            $delete[$duplicate['file_id']] = true;
        }

        $sharing = [];
        $checks = [
            'capture_directory' => "capture_directory LIKE '/%/%'",
            'path' => "path LIKE 'https://video.richmondsunlight.com/%'",
        ];
        foreach ($checks as $column => $where) {
            $groups = [];
            $rows = $this->pdo->query("SELECT id, {$column} AS shared FROM files WHERE {$where} ORDER BY id");
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!isset($delete[(int) $row['id']])) {
                    $groups[$row['shared']][] = (int) $row['id'];
                }
            }
            // Rows still sharing after the copies are gone are different meetings.
            foreach ($groups as $ids) {
                if (count($ids) > 1) {
                    foreach ($ids as $id) {
                        $sharing[$id] = true;
                    }
                }
            }
        }

        $requeuer = new VideoRequeuer($this->pdo);
        $requeue = [];
        $unrecoverable = [];
        foreach (array_keys($sharing) as $id) {
            try {
                $requeuer->sourceUrl($id);
                $requeue[] = $id;
            } catch (\RuntimeException $e) {
                $unrecoverable[] = $id;
            }
        }

        $delete = array_keys($delete);
        sort($delete);
        sort($requeue);
        sort($unrecoverable);

        return ['delete' => $delete, 'requeue' => $requeue, 'unrecoverable' => $unrecoverable];
    }
}
