<?php

namespace RichmondSunlight\VideoProcessor\Maintenance;

use PDO;
use RichmondSunlight\VideoProcessor\Sync\VideoFilter;

/**
 * Finds videos of meetings that do not advance legislation (task forces, work
 * groups, councils, ...; see VideoFilter) and removes the ones nothing has been
 * done with yet. The scraper now skips such meetings; these were imported
 * before it did.
 *
 * A video counts as worked on once it has been downloaded, screenshotted,
 * indexed or transcribed, or while a stage is running on it. Those, and videos
 * from before this pipeline, are left alone and listed so a person can decide.
 */
class UnwantedVideoCleaner
{
    /**
     * SQL true when nothing has been done with video f. Only rows this pipeline
     * imported (a JSON source record, no embedded player) qualify, so videos
     * from the old site, whose paths look "not downloaded", are never removed.
     */
    private const UNTOUCHED = "f.video_index_cache LIKE '{%'
            AND (f.html IS NULL OR f.html = '')
            AND (f.path IS NULL OR (f.path NOT LIKE 'https://video.richmondsunlight.com/%'
                                 AND f.path NOT LIKE 'https://archive.org/%'))
            AND (f.capture_directory IS NULL OR f.capture_directory = '')
            AND NOT EXISTS (SELECT 1 FROM video_index vi WHERE vi.file_id = f.id)
            AND NOT EXISTS (SELECT 1 FROM video_transcript vt WHERE vt.file_id = f.id)
            AND NOT EXISTS (SELECT 1 FROM video_jobs vj WHERE vj.file_id = f.id AND vj.status = 'running')";

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{remove: list<array<string,mixed>>, processed: list<array<string,mixed>>}
     *         unwanted videos nothing has been done with, and unwanted videos
     *         that have been worked on; each row has id, chamber, date, title
     */
    public function plan(): array
    {
        $rows = $this->pdo->query(
            "SELECT f.id, f.chamber, f.date, f.title, f.description,
                    CASE WHEN " . self::UNTOUCHED . " THEN 1 ELSE 0 END AS untouched
             FROM files f
             WHERE f.type = 'video'
             ORDER BY f.date DESC, f.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $plan = ['remove' => [], 'processed' => []];
        foreach ($rows as $row) {
            // The stored title is the chamber plus the committee name, which is
            // what says what kind of body met.
            if (VideoFilter::shouldKeep(['title' => (string) $row['title'], 'description' => $row['description']])) {
                continue;
            }
            $entry = [
                'id' => (int) $row['id'],
                'chamber' => $row['chamber'],
                'date' => $row['date'],
                'title' => $row['title'],
            ];
            $plan[(int) $row['untouched'] === 1 ? 'remove' : 'processed'][] = $entry;
        }

        return $plan;
    }

    /**
     * Delete these videos and their records, checking again for each that
     * nothing has been done with it in the meantime.
     *
     * @param int[] $fileIds
     * @return int how many were deleted
     */
    public function remove(array $fileIds): int
    {
        $stillUntouched = $this->pdo->prepare('SELECT COUNT(*) FROM files f WHERE f.id = :id AND ' . self::UNTOUCHED);
        $removed = 0;

        foreach ($fileIds as $fileId) {
            $fileId = (int) $fileId;
            $this->pdo->beginTransaction();
            try {
                $stillUntouched->execute([':id' => $fileId]);
                if ((int) $stillUntouched->fetchColumn() !== 1) {
                    $this->pdo->rollBack();
                    continue;
                }
                // MariaDB would cascade most of these from files; SQLite does
                // not, and saying so outright costs nothing.
                foreach (['video_jobs', 'video_sources', 'video_index', 'video_transcript'] as $table) {
                    $this->pdo->prepare("DELETE FROM {$table} WHERE file_id = :id")->execute([':id' => $fileId]);
                }
                $this->pdo->prepare('DELETE FROM files WHERE id = :id')->execute([':id' => $fileId]);
                $this->pdo->commit();
                $removed++;
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        return $removed;
    }
}
