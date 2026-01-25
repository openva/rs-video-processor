<?php

namespace RichmondSunlight\VideoProcessor\Resolution;

use PDO;
use RichmondSunlight\VideoProcessor\Resolution\FuzzyMatcher\BillNumberMatcher;

/**
 * Resolves bill numbers from raw text to bills.id.
 * Uses strict validation to prevent false matches (wrong bill is worse than no match).
 */
class BillResolver
{
    private BillNumberMatcher $billMatcher;
    private ContextAnalyzer $contextAnalyzer;

    /** @var array<string, array<int, array<string, mixed>>> Cached bills by session+chamber */
    private array $billCache = [];

    public function __construct(
        private PDO $pdo,
        ?BillNumberMatcher $billMatcher = null,
        ?ContextAnalyzer $contextAnalyzer = null
    ) {
        $this->billMatcher = $billMatcher ?? new BillNumberMatcher();
        $this->contextAnalyzer = $contextAnalyzer ?? new ContextAnalyzer($pdo);
    }

    /**
     * Resolve a bill number to a bills.id.
     *
     * @param string $rawText Raw OCR text
     * @param array<string, mixed> $context Context including file_id, screenshot, session_id, etc.
     * @param float $confidenceThreshold Minimum confidence score (0-100) required to match
     * @return array{id: int, number: string, confidence: float}|null Match result or null
     */
    public function resolve(string $rawText, array $context, float $confidenceThreshold = 90.0): ?array
    {
        // Parse bill number
        $parsed = $this->billMatcher->parseBillNumber($rawText);

        if (!$parsed) {
            return null;
        }

        $sessionId = $context['session_id'] ?? null;
        if ($sessionId === null) {
            return null;
        }

        // Get temporal context
        $temporalContext = [];
        if (isset($context['file_id']) && isset($context['screenshot'])) {
            $temporalContext = $this->contextAnalyzer->getTemporalContext(
                $context['file_id'],
                $context['screenshot'],
                10 // ±10 seconds for bills (longer window)
            );
        }

        // Get meeting context (agenda)
        $meetingContext = $this->contextAnalyzer->extractMeetingContext($context);

        // Try exact match first
        $exactMatch = $this->findBill($sessionId, $parsed['chamber'], $parsed['number']);

        if ($exactMatch) {
            $confidence = 100.0;

            // Validate against context for confidence boosting
            $inAgenda = $this->contextAnalyzer->inAgenda((int)$parsed['number'], $meetingContext['agenda_bills']);
            $inAdjacentFrames = !empty($temporalContext) &&
                $this->contextAnalyzer->inAdjacentFrames($exactMatch['id'], $temporalContext);

            // Confidence levels based on validation
            if ($inAgenda) {
                $confidence = 100.0;
            } elseif ($inAdjacentFrames) {
                $confidence = 95.0;
            } else {
                // Exact bill number match should still be accepted with high confidence
                // Bill numbers are unique per session, so an exact match is reliable
                $confidence = 92.0;
            }

            if ($confidence >= $confidenceThreshold) {
                return [
                    'id' => $exactMatch['id'],
                    'number' => $this->billMatcher->formatBillNumber(
                        $parsed['chamber'],
                        $parsed['type'],
                        $parsed['number']
                    ),
                    'confidence' => $confidence,
                ];
            }
        }

        // Try OCR error variations (only if in agenda)
        if (!empty($meetingContext['agenda_bills'])) {
            $variations = $this->billMatcher->generateNumberVariations($parsed['number']);

            foreach ($variations as $variant) {
                $candidate = $this->findBill($sessionId, $parsed['chamber'], $variant);

                if ($candidate && $this->contextAnalyzer->inAgenda((int)$variant, $meetingContext['agenda_bills'])) {
                    return [
                        'id' => $candidate['id'],
                        'number' => $this->billMatcher->formatBillNumber(
                            $parsed['chamber'],
                            $parsed['type'],
                            $variant
                        ),
                        'confidence' => 92.0, // High confidence due to agenda validation
                    ];
                }
            }
        }

        // Fallback: Use temporal consensus (if very strong)
        if (!empty($temporalContext)) {
            $consensusId = $this->contextAnalyzer->findConsensusMatch($temporalContext, 5); // Require 5+ occurrences

            if ($consensusId !== null) {
                // Find this bill in our session
                $bill = $this->findBillById($consensusId);

                if ($bill && $bill['session_id'] == $sessionId) {
                    // Verify it matches expected chamber
                    $billChamber = $this->determineChamberFromBillNumber($bill['number']);

                    if ($billChamber === $parsed['chamber']) {
                        return [
                            'id' => $bill['id'],
                            'number' => $bill['number'],
                            'confidence' => 88.0, // Lower confidence, but strong temporal consensus
                        ];
                    }
                }
            }
        }

        return null; // No confident match - better safe than sorry
    }

    /**
     * Find a bill by session, chamber, and number.
     *
     * @return array<string, mixed>|null
     */
    private function findBill(int $sessionId, string $chamber, string $number): ?array
    {
        $cacheKey = "{$sessionId}_{$chamber}";

        if (!isset($this->billCache[$cacheKey])) {
            $this->loadBillsForSession($sessionId, $chamber);
        }

        $bills = $this->billCache[$cacheKey] ?? [];

        foreach ($bills as $bill) {
            // Match by number (case-insensitive, strip leading zeros)
            $billNum = ltrim(preg_replace('/[^0-9]/', '', $bill['number']), '0') ?: '0';
            $searchNum = ltrim($number, '0') ?: '0';

            if ($billNum === $searchNum) {
                return $bill;
            }
        }

        return null;
    }

    /**
     * Find a bill by ID.
     *
     * @return array<string, mixed>|null
     */
    private function findBillById(int $billId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, session_id, number, chamber
            FROM bills
            WHERE id = :id
        ');

        $stmt->execute([':id' => $billId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Load all bills for a session and chamber into cache.
     */
    private function loadBillsForSession(int $sessionId, string $chamber): void
    {
        $cacheKey = "{$sessionId}_{$chamber}";

        $stmt = $this->pdo->prepare('
            SELECT id, session_id, number, chamber
            FROM bills
            WHERE session_id = :session_id
            AND chamber = :chamber
        ');

        $stmt->execute([
            ':session_id' => $sessionId,
            ':chamber' => $chamber,
        ]);

        $this->billCache[$cacheKey] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Determine chamber from bill number (e.g., "HB123" → "house").
     */
    private function determineChamberFromBillNumber(string $billNumber): ?string
    {
        $prefix = strtoupper(substr($billNumber, 0, 1));

        return match ($prefix) {
            'H' => 'house',
            'S' => 'senate',
            default => null,
        };
    }
}
