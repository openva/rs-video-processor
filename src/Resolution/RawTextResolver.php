<?php

namespace RichmondSunlight\VideoProcessor\Resolution;

use PDO;
use Log;

/**
 * Main orchestrator for resolving raw_text entries in video_index to linked_id references.
 */
class RawTextResolver
{
    private LegislatorResolver $legislatorResolver;
    private BillResolver $billResolver;

    public function __construct(
        private PDO $pdo,
        ?LegislatorResolver $legislatorResolver = null,
        ?BillResolver $billResolver = null,
        private ?Log $logger = null
    ) {
        $this->legislatorResolver = $legislatorResolver ?? new LegislatorResolver($pdo);
        $this->billResolver = $billResolver ?? new BillResolver($pdo);
    }

    /**
     * Resolve all unresolved entries for a specific file.
     *
     * @param int $fileId File ID to process
     * @param bool $dryRun If true, don't update database
     * @param bool $force If true, re-resolve entries that already have linked_id
     * @param string|null $type Only process specific type ('legislator' or 'bill'), or null for all
     * @return array{total: int, resolved: int, unresolved: int, skipped: int, by_type: array}
     */
    public function resolveFile(
        int $fileId,
        bool $dryRun = false,
        bool $force = false,
        ?string $type = null
    ): array {
        // Load file metadata
        $fileMetadata = $this->loadFileMetadata($fileId);

        if (!$fileMetadata) {
            throw new \RuntimeException("File {$fileId} not found");
        }

        $this->logger?->put("Starting resolution for file_id={$fileId} (session={$fileMetadata['session_id']})", 3);

        // Fetch entries to process
        $entries = $this->fetchEntries($fileId, $force, $type);

        $stats = [
            'total' => count($entries),
            'resolved' => 0,
            'unresolved' => 0,
            'skipped' => 0,
            'by_type' => [
                'legislator' => ['total' => 0, 'resolved' => 0, 'unresolved' => 0],
                'bill' => ['total' => 0, 'resolved' => 0, 'unresolved' => 0],
            ],
        ];

        foreach ($entries as $entry) {
            $entryType = $entry['type'];
            $stats['by_type'][$entryType]['total']++;

            // Build context
            $context = [
                'file_id' => $fileId,
                'screenshot' => $entry['screenshot'],
                'session_id' => $fileMetadata['session_id'],
                'video_index_cache' => $fileMetadata['video_index_cache'],
                'chamber' => $fileMetadata['chamber'],
            ];

            // Resolve based on type
            $result = null;

            if ($entryType === 'legislator') {
                $result = $this->legislatorResolver->resolve($entry['raw_text'], $context);
            } elseif ($entryType === 'bill') {
                $result = $this->billResolver->resolve($entry['raw_text'], $context);
            }

            if ($result) {
                $stats['resolved']++;
                $stats['by_type'][$entryType]['resolved']++;

                $this->logger?->put(
                    sprintf(
                        'Resolved %s: "%s" → %s (id=%d, confidence=%.1f%%)',
                        $entryType,
                        substr($entry['raw_text'], 0, 50),
                        $result['name'] ?? $result['number'] ?? 'N/A',
                        $result['id'],
                        $result['confidence']
                    ),
                    4
                );

                // Update database
                if (!$dryRun) {
                    $this->updateLinkedId($entry['id'], $result['id']);
                }
            } else {
                $stats['unresolved']++;
                $stats['by_type'][$entryType]['unresolved']++;

                $this->logger?->put(
                    sprintf(
                        'Unresolved %s: "%s" (screenshot=%s)',
                        $entryType,
                        substr($entry['raw_text'], 0, 50),
                        $entry['screenshot']
                    ),
                    5
                );
            }
        }

        $this->logger?->put(
            sprintf(
                'Completed file_id=%d: %d/%d resolved (%.1f%%)',
                $fileId,
                $stats['resolved'],
                $stats['total'],
                $stats['total'] > 0 ? ($stats['resolved'] / $stats['total'] * 100) : 0
            ),
            3
        );

        return $stats;
    }

    /**
     * Resolve all unresolved entries across all files.
     *
     * @return array{files_processed: int, total_entries: int, total_resolved: int, total_unresolved: int}
     */
    public function resolveAll(
        bool $dryRun = false,
        bool $force = false,
        ?string $type = null,
        ?int $limit = null
    ): array {
        // Get all files with unresolved entries
        $fileIds = $this->getFilesWithUnresolvedEntries($force, $type, $limit);

        $totalStats = [
            'files_processed' => 0,
            'total_entries' => 0,
            'total_resolved' => 0,
            'total_unresolved' => 0,
        ];

        foreach ($fileIds as $fileId) {
            try {
                $stats = $this->resolveFile($fileId, $dryRun, $force, $type);

                $totalStats['files_processed']++;
                $totalStats['total_entries'] += $stats['total'];
                $totalStats['total_resolved'] += $stats['resolved'];
                $totalStats['total_unresolved'] += $stats['unresolved'];
            } catch (\Exception $e) {
                $this->logger?->put(
                    sprintf('Error processing file_id=%d: %s', $fileId, $e->getMessage()),
                    1
                );
            }
        }

        return $totalStats;
    }

    /**
     * Load file metadata from files table.
     *
     * @return array<string, mixed>|null
     */
    private function loadFileMetadata(int $fileId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                f.id,
                f.chamber,
                f.video_index_cache,
                f.date,
                s.id AS session_id
            FROM files f
            LEFT JOIN sessions s ON f.date >= s.date_started
                AND (s.date_ended IS NULL OR f.date <= s.date_ended)
            WHERE f.id = :id
        ');

        $stmt->execute([':id' => $fileId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Fetch video_index entries to process.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchEntries(int $fileId, bool $force, ?string $type): array
    {
        $sql = '
            SELECT id, file_id, screenshot, raw_text, type, linked_id
            FROM video_index
            WHERE file_id = :file_id
        ';

        $params = [':file_id' => $fileId];

        // Filter by type if specified
        if ($type !== null) {
            $sql .= ' AND type = :type';
            $params[':type'] = $type;
        }

        // Only unresolved if not forcing
        if (!$force) {
            $sql .= ' AND linked_id IS NULL';
        }

        $sql .= ' ORDER BY CAST(screenshot AS INTEGER)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get file IDs that have unresolved entries.
     *
     * @return array<int, int>
     */
    private function getFilesWithUnresolvedEntries(bool $force, ?string $type, ?int $limit): array
    {
        $sql = '
            SELECT DISTINCT file_id
            FROM video_index
            WHERE 1=1
        ';

        $params = [];

        if ($type !== null) {
            $sql .= ' AND type = :type';
            $params[':type'] = $type;
        }

        if (!$force) {
            $sql .= ' AND linked_id IS NULL';
        }

        $sql .= ' ORDER BY file_id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
        }

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        $stmt->execute();

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'file_id');
    }

    /**
     * Update linked_id for a video_index entry.
     */
    private function updateLinkedId(int $entryId, int $linkedId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE video_index
            SET linked_id = :linked_id
            WHERE id = :id
        ');

        $stmt->execute([
            ':linked_id' => $linkedId,
            ':id' => $entryId,
        ]);
    }
}
