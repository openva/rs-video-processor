<?php

namespace RichmondSunlight\VideoProcessor\Maintenance;

use PDO;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;

/**
 * Sends videos back through bill detection. The bill queue treats any
 * video_index row of type 'bill' as done, so this removes those rows, and the
 * video's bills job, and the next bin/detect_bills.php run reads it again.
 *
 * Written for Senate floor video, whose chyron reads "S.B. 956": BillParser
 * did not accept the periods until November 2026, so it found no bills there.
 */
class BillDetectionReset
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Senate floor videos the bill stage has finished, newest first. Videos
     * being worked on now are left out.
     *
     * @return int[]
     */
    public function senateFloorVideos(): array
    {
        $stmt = $this->pdo->query(
            "SELECT f.id FROM files f
             WHERE f.chamber = 'senate' AND f.committee_id IS NULL
               AND f.date >= '2020-01-01'
               AND EXISTS (SELECT 1 FROM video_index vi WHERE vi.file_id = f.id AND vi.type = 'bill')
               AND NOT EXISTS (
                   SELECT 1 FROM video_index vi
                   WHERE vi.file_id = f.id AND vi.type = 'bill' AND vi.raw_text = '/pending'
               )
               AND NOT EXISTS (
                   SELECT 1 FROM video_jobs vj
                   WHERE vj.file_id = f.id AND vj.stage = '" . JobLedger::STAGE_BILLS . "' AND vj.status = 'running'
               )
             ORDER BY f.date DESC, f.id DESC"
        );

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Removes the video's bill results and bills job, so bill detection runs on it again. */
    public function reset(int $fileId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM video_index WHERE file_id = :id AND type = 'bill'")
                ->execute([':id' => $fileId]);
            $this->pdo->prepare('DELETE FROM video_jobs WHERE file_id = :id AND stage = :stage')
                ->execute([':id' => $fileId, ':stage' => JobLedger::STAGE_BILLS]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
