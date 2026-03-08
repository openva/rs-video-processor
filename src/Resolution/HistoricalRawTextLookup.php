<?php

namespace RichmondSunlight\VideoProcessor\Resolution;

use PDO;

/**
 * Resolves raw_text → linked_id by looking up the accumulated history of
 * previously resolved entries in video_index.
 *
 * Requires ≥3 prior uses of the same raw_text+type and ≥80% of those uses
 * to agree on the same linked_id. Also validates that the candidate is active
 * in the current session before returning a match.
 */
class HistoricalRawTextLookup
{
    private const MIN_OCCURRENCES = 3;
    private const MIN_MAJORITY_FRACTION = 0.80;
    private const CONFIDENCE = 65.0;

    public function __construct(private PDO $pdo) {}

    /**
     * @return array{id: int, name?: string, number?: string, confidence: float}|null
     */
    public function lookup(string $rawText, string $type, ?int $sessionId): ?array
    {
        if ($sessionId === null) {
            return null;
        }

        $rows = $this->fetchHistoricalMatches($rawText, $type);
        $linkedId = $this->pickConsensusId($rows);

        if ($linkedId === null) {
            return null;
        }

        return $this->buildValidatedResult($linkedId, $type, $sessionId);
    }

    /**
     * @return list<array{linked_id: int, cnt: int}>
     */
    private function fetchHistoricalMatches(string $rawText, string $type): array
    {
        $stmt = $this->pdo->prepare('
            SELECT linked_id, COUNT(*) AS cnt
            FROM video_index
            WHERE raw_text = :raw_text
              AND type = :type
              AND linked_id IS NOT NULL
              AND ignored != \'y\'
            GROUP BY linked_id
            ORDER BY cnt DESC
        ');
        $stmt->execute([':raw_text' => $rawText, ':type' => $type]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Returns the consensus linked_id if ≥MIN_OCCURRENCES total and the top
     * candidate holds ≥MIN_MAJORITY_FRACTION of all matches. Returns null otherwise.
     */
    private function pickConsensusId(array $rows): ?int
    {
        if (empty($rows)) {
            return null;
        }

        $total = array_sum(array_column($rows, 'cnt'));
        if ($total < self::MIN_OCCURRENCES) {
            return null;
        }

        $topCount = (int) $rows[0]['cnt'];
        if ($topCount < self::MIN_MAJORITY_FRACTION * $total) {
            return null;
        }

        return (int) $rows[0]['linked_id'];
    }

    /**
     * Validates the linked_id is active in the current session and fetches
     * the name/number for the result array.
     *
     * @return array{id: int, name?: string, number?: string, confidence: float}|null
     */
    private function buildValidatedResult(int $linkedId, string $type, int $sessionId): ?array
    {
        if ($type === 'legislator') {
            return $this->buildLegislatorResult($linkedId, $sessionId);
        }

        if ($type === 'bill') {
            return $this->buildBillResult($linkedId, $sessionId);
        }

        return null;
    }

    /**
     * @return array{id: int, name: string, confidence: float}|null
     */
    private function buildLegislatorResult(int $linkedId, int $sessionId): ?array
    {
        // Verify the person has a term overlapping the session, then fetch name.
        // Uses date comparison against session boundaries to avoid CURDATE() —
        // works in both MySQL (production) and SQLite (tests).
        $stmt = $this->pdo->prepare('
            SELECT p.name
            FROM people p
            INNER JOIN terms t ON t.person_id = p.id
            INNER JOIN sessions s ON s.id = :session_id
            WHERE p.id = :linked_id
              AND t.date_started <= s.date_started
              AND (t.date_ended IS NULL OR t.date_ended >= s.date_started)
            LIMIT 1
        ');
        $stmt->execute([':linked_id' => $linkedId, ':session_id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return ['id' => $linkedId, 'name' => $row['name'], 'confidence' => self::CONFIDENCE];
    }

    /**
     * @return array{id: int, number: string, confidence: float}|null
     */
    private function buildBillResult(int $linkedId, int $sessionId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT number FROM bills
            WHERE id = :id AND session_id = :session_id
            LIMIT 1
        ');
        $stmt->execute([':id' => $linkedId, ':session_id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return ['id' => $linkedId, 'number' => $row['number'], 'confidence' => self::CONFIDENCE];
    }
}
