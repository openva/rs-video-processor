<?php

namespace RichmondSunlight\VideoProcessor\Resolution;

use PDO;
use RichmondSunlight\VideoProcessor\Resolution\FuzzyMatcher\NameMatcher;

/**
 * Resolves legislator names from raw text to people.id.
 */
class LegislatorResolver
{
    private NameMatcher $nameMatcher;
    private ContextAnalyzer $contextAnalyzer;

    /** @var array<int, array<int, array<string, mixed>>> Cached legislators by session */
    private array $legislatorCache = [];

    public function __construct(
        private PDO $pdo,
        ?NameMatcher $nameMatcher = null,
        ?ContextAnalyzer $contextAnalyzer = null
    ) {
        $this->nameMatcher = $nameMatcher ?? new NameMatcher();
        $this->contextAnalyzer = $contextAnalyzer ?? new ContextAnalyzer($pdo);
    }

    /**
     * Resolve a legislator name to a person ID.
     *
     * @param string $rawText Raw OCR text
     * @param array<string, mixed> $context Context including file_id, screenshot, session_id, etc.
     * @param float $confidenceThreshold Minimum confidence score (0-100) required to match
     * @return array{id: int, name: string, confidence: float}|null Match result or null
     */
    public function resolve(string $rawText, array $context, float $confidenceThreshold = 75.0): ?array
    {
        // Extract clean name
        $extracted = $this->nameMatcher->extractLegislatorName($rawText);

        if (empty($extracted['cleaned'])) {
            return null;
        }

        // Get candidates
        $sessionId = $context['session_id'] ?? null;
        if ($sessionId === null) {
            return null;
        }

        $candidates = $this->loadLegislatorCandidates($sessionId);

        if (empty($candidates)) {
            return null;
        }

        // Get temporal context
        $temporalContext = [];
        if (isset($context['file_id']) && isset($context['screenshot'])) {
            $temporalContext = $this->contextAnalyzer->getTemporalContext(
                $context['file_id'],
                $context['screenshot'],
                5 // ±5 seconds
            );
        }

        // Get meeting context (speaker list)
        $meetingContext = $this->contextAnalyzer->extractMeetingContext($context);

        // Score all candidates
        $scored = [];
        foreach ($candidates as $legislator) {
            $score = $this->nameMatcher->calculateNameScore(
                $extracted['cleaned'],
                $legislator['name'],
                $extracted['tokens']
            );

            // Also try formal name
            if (isset($legislator['name_formal']) && $legislator['name_formal']) {
                $formalScore = $this->nameMatcher->calculateNameScore(
                    $extracted['cleaned'],
                    $legislator['name_formal'],
                    $extracted['tokens']
                );
                $score = max($score, $formalScore);
            }

            // Context boosting
            if (!empty($temporalContext)) {
                $occurrences = $this->contextAnalyzer->countOccurrences($legislator['id'], $temporalContext);
                if ($occurrences > 0) {
                    // 5% boost per occurrence, up to 20%
                    $boost = min(0.20, $occurrences * 0.05);
                    $score = $score * (1.0 + $boost);
                }
            }

            // Speaker list boost
            if ($this->contextAnalyzer->inSpeakerList($legislator['id'], $meetingContext['speaker_ids'])) {
                $score *= 1.10; // 10% boost
            }

            // Party/district validation bonus
            if ($extracted['party'] && isset($legislator['party'])) {
                if (strtoupper($extracted['party']) === strtoupper($legislator['party'])) {
                    $score *= 1.05; // 5% boost for matching party
                }
            }

            $scored[] = [
                'id' => $legislator['id'],
                'name' => $legislator['name'],
                'confidence' => min($score, 100.0),
            ];
        }

        // Sort by confidence descending
        usort($scored, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

        // Return best match if above threshold
        if (!empty($scored) && $scored[0]['confidence'] >= $confidenceThreshold) {
            return $scored[0];
        }

        // Fallback: Use temporal consensus (only if text looks like a name)
        if (!empty($temporalContext) && !$this->isNonNameText($extracted['cleaned'])) {
            $consensusId = $this->contextAnalyzer->findConsensusMatch($temporalContext, 3);
            if ($consensusId !== null) {
                // Find this legislator in our candidates
                foreach ($candidates as $legislator) {
                    if ($legislator['id'] == $consensusId) {
                        return [
                            'id' => $legislator['id'],
                            'name' => $legislator['name'],
                            'confidence' => 70.0, // Lower confidence, but consensus-based
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if text contains obvious non-name terms that should never match legislators.
     * Prevents titles, roles, and job descriptions from being matched via temporal consensus.
     */
    private function isNonNameText(string $text): bool
    {
        $nonNameTerms = [
            'staff', 'attorney', 'counsel', 'clerk', 'director', 'manager',
            'assistant', 'secretary', 'chair', 'chairman', 'chairwoman',
            'vice', 'president', 'officer', 'coordinator', 'analyst',
            'advisor', 'administrator', 'executive', 'chief', 'commissioner',
            'committee', 'subcommittee', 'member', 'representative',
        ];

        $lowerText = strtolower($text);
        foreach ($nonNameTerms as $term) {
            if (str_contains($lowerText, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Load all legislators for a session.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadLegislatorCandidates(int $sessionId): array
    {
        if (isset($this->legislatorCache[$sessionId])) {
            return $this->legislatorCache[$sessionId];
        }

        $stmt = $this->pdo->prepare('
            SELECT DISTINCT
                p.id,
                p.name,
                p.name_formal,
                t.party,
                t.district_id AS district
            FROM people p
            INNER JOIN terms t ON p.id = t.person_id
            INNER JOIN sessions s ON s.id = :session_id
                AND t.date_started <= COALESCE(s.date_ended, CURDATE())
                AND (t.date_ended IS NULL OR t.date_ended >= s.date_started)
        ');

        $stmt->execute([':session_id' => $sessionId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->legislatorCache[$sessionId] = $results;

        return $results;
    }
}
