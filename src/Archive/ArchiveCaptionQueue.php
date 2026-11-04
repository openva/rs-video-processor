<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use PDO;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;

/**
 * Archived videos with new captions to send to their Internet Archive items.
 * A job exists only once something asks for it (ArchiveCaptions::requestIfArchived()),
 * so an archived video without a job has nothing to send.
 */
class ArchiveCaptionQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    private function pendingWhere(): string
    {
        // Captions go only once their timing has been checked (ArchiveCaptions::sendable()).
        return "f.path LIKE 'https://archive.org/download/%'
            AND f.webvtt IS NOT NULL AND f.webvtt != ''
            AND f.caption_alignment IS NOT NULL
            AND EXISTS (SELECT 1 FROM video_jobs ac
                        WHERE ac.file_id = f.id AND ac.stage = '" . JobLedger::STAGE_ARCHIVE_CAPTIONS . "'
                          AND ac.status != '" . JobLedger::STATUS_DONE . "')
            AND " . JobLedger::eligibleSql(JobLedger::STAGE_ARCHIVE_CAPTIONS);
    }

    public function countPending(): int
    {
        // A job waiting on the Internet Archive is not work for this machine.
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files f WHERE ' . $this->pendingWhere()
            . ' AND ' . JobLedger::notWaitingSql(JobLedger::STAGE_ARCHIVE_CAPTIONS));
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return int[] file IDs, newest first, each claimed
     */
    public function fetch(int $limit = 1): array
    {
        $sql = 'SELECT f.id FROM files f WHERE ' . $this->pendingWhere() . ' ORDER BY f.date DESC, f.id DESC LIMIT :limit';

        $locking = in_array($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql'], true);
        if ($locking) {
            $sql .= ' FOR UPDATE SKIP LOCKED';
            $this->pdo->beginTransaction();
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        // The claim keeps two workers from sending the same captions.
        $ledger = new JobLedger($this->pdo);
        foreach ($ids as $id) {
            $ledger->claim($id, JobLedger::STAGE_ARCHIVE_CAPTIONS);
        }

        if ($locking) {
            $this->pdo->commit();
        }

        return $ids;
    }
}
