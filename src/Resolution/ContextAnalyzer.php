<?php

namespace RichmondSunlight\VideoProcessor\Resolution;

use PDO;

/**
 * Analyzes surrounding screenshots for temporal context to improve matching confidence.
 */
class ContextAnalyzer
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * Get temporal context from surrounding screenshots.
     *
     * @param int $fileId File ID
     * @param string $screenshot Current screenshot number (e.g., "00542")
     * @param int $windowSeconds Time window in seconds (±N seconds)
     * @return array<int, array<string, mixed>>
     */
    public function getTemporalContext(int $fileId, string $screenshot, int $windowSeconds = 5): array
    {
        $screenshotNum = (int)$screenshot;
        $startShot = max(0, $screenshotNum - $windowSeconds);
        $endShot = $screenshotNum + $windowSeconds;

        $stmt = $this->pdo->prepare('
            SELECT screenshot, raw_text, type, linked_id
            FROM video_index
            WHERE file_id = :file_id
            AND CAST(screenshot AS INTEGER) BETWEEN :start AND :end
            AND screenshot != :current
            ORDER BY CAST(screenshot AS INTEGER)
        ');

        $stmt->execute([
            ':file_id' => $fileId,
            ':start' => $startShot,
            ':end' => $endShot,
            ':current' => $screenshot,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find consensus match from surrounding frames using majority vote.
     *
     * @param array<int, array<string, mixed>> $contextRecords Records from getTemporalContext()
     * @param int $minOccurrences Minimum number of occurrences required
     * @return int|null Most common linked_id, or null if no consensus
     */
    public function findConsensusMatch(array $contextRecords, int $minOccurrences = 3): ?int
    {
        $idCounts = [];

        foreach ($contextRecords as $record) {
            if ($record['linked_id'] !== null) {
                $id = (int)$record['linked_id'];
                $idCounts[$id] = ($idCounts[$id] ?? 0) + 1;
            }
        }

        if (empty($idCounts)) {
            return null;
        }

        // Sort by count descending
        arsort($idCounts);

        // Get most common ID
        $topId = array_key_first($idCounts);
        $topCount = $idCounts[$topId];

        // Require minimum occurrences
        if ($topCount >= $minOccurrences) {
            return $topId;
        }

        return null;
    }

    /**
     * Check if a specific ID appears in adjacent frames.
     *
     * @param int $linkedId ID to check for
     * @param array<int, array<string, mixed>> $contextRecords Records from getTemporalContext()
     * @return bool True if ID appears in context
     */
    public function inAdjacentFrames(int $linkedId, array $contextRecords): bool
    {
        foreach ($contextRecords as $record) {
            if ($record['linked_id'] == $linkedId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Count how many times an ID appears in context.
     *
     * @param int $linkedId ID to count
     * @param array<int, array<string, mixed>> $contextRecords Records from getTemporalContext()
     * @return int Number of occurrences
     */
    public function countOccurrences(int $linkedId, array $contextRecords): int
    {
        $count = 0;
        foreach ($contextRecords as $record) {
            if ($record['linked_id'] == $linkedId) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Extract meeting metadata from video_index_cache.
     *
     * @param array<string, mixed> $metadata File metadata (from files table)
     * @return array{agenda_bills: array<int, int>, speaker_ids: array<int, int>}
     */
    public function extractMeetingContext(array $metadata): array
    {
        $agendaBills = [];
        $speakerIds = [];

        // Parse video_index_cache JSON if available
        if (isset($metadata['video_index_cache']) && is_string($metadata['video_index_cache'])) {
            $cache = json_decode($metadata['video_index_cache'], true);

            if (is_array($cache)) {
                // Extract agenda bills
                if (isset($cache['agenda']) && is_array($cache['agenda'])) {
                    foreach ($cache['agenda'] as $item) {
                        if (is_string($item)) {
                            // Parse bill references from agenda text
                            preg_match_all('/\b([HS][BJR]R?)\s*(\d{1,4})\b/i', $item, $matches);
                            foreach ($matches[2] as $number) {
                                // Store as formatted bill number for later lookup
                                $agendaBills[] = (int)ltrim($number, '0') ?: 0;
                            }
                        }
                    }
                }

                // Extract speaker IDs if available
                if (isset($cache['speakers']) && is_array($cache['speakers'])) {
                    foreach ($cache['speakers'] as $speaker) {
                        if (isset($speaker['person_id'])) {
                            $speakerIds[] = (int)$speaker['person_id'];
                        }
                    }
                }
            }
        }

        return [
            'agenda_bills' => array_unique($agendaBills),
            'speaker_ids' => array_unique($speakerIds),
        ];
    }

    /**
     * Check if a person ID is in the meeting's speaker list.
     *
     * @param int $personId Person ID to check
     * @param array<int, int> $speakerIds Speaker IDs from meeting context
     * @return bool True if person is in speaker list
     */
    public function inSpeakerList(int $personId, array $speakerIds): bool
    {
        return in_array($personId, $speakerIds, true);
    }

    /**
     * Check if a bill number is in the meeting's agenda.
     *
     * @param int $billNumber Bill number to check
     * @param array<int, int> $agendaBills Bill numbers from meeting context
     * @return bool True if bill is in agenda
     */
    public function inAgenda(int $billNumber, array $agendaBills): bool
    {
        return in_array($billNumber, $agendaBills, true);
    }
}
