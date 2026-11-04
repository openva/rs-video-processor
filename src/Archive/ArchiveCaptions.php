<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use PDO;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;

/**
 * The rules for a video's captions on the Internet Archive, shared by the
 * archive stage, the archive_captions stage, and whatever writes captions.
 */
class ArchiveCaptions
{
    /**
     * The captions to put on the Internet Archive, or null if there are none
     * to send yet. Captions go only once the transcript stage has checked
     * their timing (files.caption_alignment is set): the House and Senate's
     * own captions can run up to 15 minutes early or late until then.
     * 'unaligned' counts as checked; nothing will improve on those.
     */
    public static function sendable(?string $webvtt, ?string $alignment): ?string
    {
        if ($alignment === null || $alignment === '' || $webvtt === null || trim($webvtt) === '') {
            return null;
        }

        return $webvtt;
    }

    /**
     * After a video's captions change: if it is already on the Internet
     * Archive, ask for the new captions to be sent there. Call it within the
     * same transaction as the change, on the same connection.
     *
     * @return bool whether it was asked for
     */
    public static function requestIfArchived(PDO $pdo, int $fileId): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM files WHERE id = :id AND path LIKE 'https://archive.org/download/%'");
        $stmt->execute([':id' => $fileId]);
        if ($stmt->fetchColumn() === false) {
            return false;
        }
        (new JobLedger($pdo))->request($fileId, JobLedger::STAGE_ARCHIVE_CAPTIONS);

        return true;
    }

    /**
     * Whether ia's output is the archive turning requests away because it is
     * overloaded (an S3 503 SlowDown, e.g. "Please reduce your request rate.
     * - total_tasks_queued exceeds global_limit"). Every request gets the
     * same answer then, so it is a wait, not a fault in this video.
     */
    public static function isRateLimited(string $output): bool
    {
        return preg_match('/reduce your request rate|slow ?down|exceeds global_limit/i', $output) === 1;
    }
}
