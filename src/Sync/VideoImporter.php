<?php

namespace RichmondSunlight\VideoProcessor\Sync;

use DateInterval;
use DateTimeImmutable;
use Log;
use PDO;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RuntimeException;

class VideoImporter
{
    /** Two rows for one chamber, date and committee are the same meeting if their lengths are this close. */
    private const DUPLICATE_DURATION_TOLERANCE_SECONDS = 300;

    public function __construct(
        private PDO $pdo,
        private CommitteeDirectory $committees,
        private ?Log $logger = null
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function import(array $records): int
    {
        if (empty($records)) {
            return 0;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO files (
                chamber, committee_id, title, description, type, length, date, sponsor,
                width, height, fps, capture_rate, capture_directory, path, html,
                author_name, license, date_created, date_modified, video_index_cache, raw_metadata
            ) VALUES (
                :chamber, :committee_id, :title, :description, :type, :length, :date, :sponsor,
                :width, :height, :fps, :capture_rate, :capture_directory, :path, :html,
                :author_name, :license, :date_created, :date_modified, :video_index_cache, :raw_metadata
            )'
        );

        $sources = new VideoSourceRegistry($this->pdo);
        $count = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($records as $record) {
            $payload = $this->buildPayload($record);
            if ($payload === null) {
                $this->logger?->put('Skipping record with insufficient metadata for insertion', 2);
                continue;
            }

            // The upstream ID is exact. Fall back to resemblance only when it is
            // unknown, and remember the match so the fallback is not needed again.
            $identity = VideoSourceRegistry::identityOf($record);
            $existingId = $identity ? $sources->findFileId($identity['source'], $identity['source_id']) : null;
            if ($existingId === null) {
                $existingId = $this->findLikelyDuplicate($payload, VideoRecordNormalizer::deriveDurationSeconds($record));
                if ($existingId !== null && $identity) {
                    $sources->register($existingId, $identity['source'], $identity['source_id']);
                }
            }

            if ($existingId !== null) {
                $skipped++;
                $this->logger?->put(
                    sprintf(
                        'Skipping duplicate of file #%d: %s %s %s',
                        $existingId,
                        $payload['chamber'],
                        $payload['date'],
                        $payload['title']
                    ),
                    1
                );
                continue;
            }

            // One bad record must not stop the rest of the batch from importing.
            try {
                if ($insert->execute($payload) === false) {
                    throw new RuntimeException('Failed to insert video record into files table.');
                }
                $fileId = (int) $this->pdo->lastInsertId();
                if ($identity) {
                    $sources->register($fileId, $identity['source'], $identity['source_id']);
                }
            } catch (\Throwable $e) {
                $failed++;
                // A statement that failed must be reset before it is executed again.
                $insert->closeCursor();
                $this->logger?->put(
                    sprintf(
                        'Insert failed (%s). Title length=%d Title sample=%s | Path length=%d Path sample=%s',
                        $e->getMessage(),
                        strlen((string) ($payload['title'] ?? '')),
                        substr((string) ($payload['title'] ?? ''), 0, 200),
                        strlen((string) ($payload['path'] ?? '')),
                        substr((string) ($payload['path'] ?? ''), 0, 200)
                    ),
                    6
                );
                continue;
            }

            $count++;
        }

        if ($skipped > 0) {
            $this->logger?->put(sprintf('Skipped %d duplicate record(s)', $skipped), 2);
        }
        if ($failed > 0) {
            $this->logger?->put(sprintf('Failed to import %d record(s)', $failed), 5);
        }

        return $count;
    }

    /**
     * Look for an existing row that is probably the same meeting, for records
     * whose upstream ID has not been seen. A committee can meet twice in a day
     * and the floor can sit twice, so chamber, date and committee alone are not
     * enough: the lengths must also be close, or one of them unknown.
     *
     * @param array<string,mixed> $payload
     */
    private function findLikelyDuplicate(array $payload, ?int $durationSeconds): ?int
    {
        if ($payload['committee_id'] === 0) {
            // Unresolved committee: nothing to compare but the source URL.
            $stmt = $this->pdo->prepare('SELECT id FROM files WHERE path = ? LIMIT 1');
            $stmt->execute([$payload['path']]);
            $id = $stmt->fetchColumn();
            return $id === false ? null : (int) $id;
        }

        if ($payload['committee_id'] === null) {
            $stmt = $this->pdo->prepare(
                'SELECT id, length FROM files WHERE chamber = ? AND date = ? AND title = ?
                 AND (committee_id IS NULL OR committee_id = \'\')'
            );
            $stmt->execute([$payload['chamber'], $payload['date'], $payload['title']]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id, length FROM files WHERE chamber = ? AND date = ? AND committee_id = ?'
            );
            $stmt->execute([$payload['chamber'], $payload['date'], $payload['committee_id']]);
        }

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $existingSeconds = $this->parseDuration($row['length'] ?? null);
            if (
                $durationSeconds === null
                || $existingSeconds === null
                || abs($existingSeconds - $durationSeconds) <= self::DUPLICATE_DURATION_TOLERANCE_SECONDS
            ) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    private function parseDuration(?string $length): ?int
    {
        if ($length === null || !preg_match('/^(\d+):(\d{2}):(\d{2})/', $length, $m)) {
            return null;
        }

        return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildPayload(array $record): ?array
    {
        $chamber = isset($record['chamber']) ? strtolower((string) $record['chamber']) : null;
        $title = $record['title'] ?? 'Video';
        $title = \RichmondSunlight\VideoProcessor\Sync\VideoFilter::normalizeTitle($title);
        $date = VideoRecordNormalizer::deriveMeetingDate($record);
        $duration = VideoRecordNormalizer::deriveDurationSeconds($record);
        if (!$chamber || !$date || empty($record['video_url'])) {
            $this->logger?->put(
                sprintf(
                    'Skipping record: chamber=%s date=%s duration=%s path=%s title=%s',
                    var_export($chamber, true),
                    var_export($date, true),
                    var_export($duration, true),
                    isset($record['video_url']) ? substr((string) $record['video_url'], 0, 120) : 'NULL',
                    isset($record['title']) ? substr((string) $record['title'], 0, 120) : ''
                ),
                2
            );
            return null;
        }

        $now = new DateTimeImmutable('now');
        $eventType = strtolower($record['event_type'] ?? 'floor');
        $committeeName = $record['committee_name'] ?? null;
        $committeeEntry = $committeeName ? $this->committees->matchEntry($committeeName, $chamber, $eventType === 'subcommittee' ? 'subcommittee' : 'committee') : null;
        $committeeId = $committeeEntry['id'] ?? ($eventType !== 'floor' ? 0 : null);

        // Build descriptive title: chamber and meeting name, without the dates,
        // times and rooms that listings add to it.
        $title = $committeeName
            ? TitleCleaner::storedTitle($committeeName, $chamber, 'committee')
            : sprintf('%s Session', ucfirst($chamber));

        // Build raw metadata (basic info only, excludes agenda and speakers)
        $rawMetadata = $record;
        unset($rawMetadata['agenda'], $rawMetadata['speakers']);

        return [
            'chamber' => $chamber,
            'committee_id' => $committeeId,
            'title' => $title,
            'description' => $record['description'] ?? '',
            'type' => 'video',
            'length' => $duration !== null ? $this->formatDuration($duration) : null,
            'date' => $date,
            'sponsor' => null,  // Legacy field - no longer used
            'width' => $record['width'] ?? null,
            'height' => $record['height'] ?? null,
            'fps' => $record['fps'] ?? null,
            'capture_rate' => $record['capture_rate'] ?? null,
            'capture_directory' => $record['capture_directory'] ?? null,
            'path' => $record['video_url'] ?? null,
            'html' => null,  // Will be populated by VideoDownloadProcessor on failure
            'author_name' => $record['speaker'] ?? null,
            'license' => $record['license'] ?? 'public-domain',
            'date_created' => $now->format('Y-m-d H:i:s'),
            'date_modified' => $now->format('Y-m-d H:i:s'),
            'video_index_cache' => json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'raw_metadata' => json_encode($rawMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max($seconds, 0);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}
