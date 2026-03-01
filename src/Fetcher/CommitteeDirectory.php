<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use PDO;

class CommitteeDirectory
{
    private array $byId = [];
    private array $byChamberType = [];

    public function __construct(private PDO $pdo)
    {
        $this->load();
    }

    private function load(): void
    {
        $sql = "SELECT
            id,
            name,
            shortname,
            chamber,
            parent_id,
            CASE WHEN parent_id IS NULL THEN 'committee' ELSE 'subcommittee' END AS type
        FROM committees
        ORDER BY chamber ASC";

        foreach ($this->pdo->query($sql, PDO::FETCH_ASSOC) as $row) {
            $entry = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'shortname' => $row['shortname'],
                'chamber' => $row['chamber'],
                'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'type' => $row['type'],
            ];
            $this->byId[$entry['id']] = $entry;
            $chamber = strtolower($entry['chamber']);
            $type = strtolower($entry['type']);
            $this->byChamberType[$chamber][$type][] = $entry;
        }
    }

    public function getShortnameById(?int $id): ?string
    {
        if (!$id || !isset($this->byId[$id])) {
            return null;
        }
        $shortname = $this->byId[$id]['shortname'];
        if ($shortname === null || $shortname === '') {
            return (string) $id;
        }
        return strtolower($shortname);
    }

    public function matchId(?string $name, string $chamber, string $type): ?int
    {
        $entry = $this->matchEntry($name, $chamber, $type);
        return $entry['id'] ?? null;
    }

    public function matchEntry(?string $name, string $chamber, string $type): ?array
    {
        if (!$name) {
            return null;
        }
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $name = $this->expandAbbreviations($name);
        $normalizedInput = $this->normalizeName($name);
        $chamberKey = strtolower($chamber);
        $typeKey = strtolower($type);
        $candidates = $this->byChamberType[$chamberKey][$typeKey] ?? [];
        if (empty($candidates)) {
            return null;
        }

        $aliasMatch = $this->resolveAliasMatch($normalizedInput, $chamberKey, $typeKey);
        if ($aliasMatch) {
            return $aliasMatch;
        }

        if ($typeKey === 'subcommittee' && str_contains($name, ':')) {
            $matched = $this->matchParentChildLabel($name, $chamberKey, $candidates);
            if ($matched) {
                return $matched;
            }
        }

        if ($typeKey === 'subcommittee') {
            $matched = $this->matchSubcommitteeLabel($name, $normalizedInput, $chamberKey, $candidates);
            if ($matched) {
                return $matched;
            }
        }

        $best = null;
        $bestScore = 0;
        foreach ($candidates as $candidate) {
            $candidateName = (string) $candidate['name'];
            $normalizedCandidate = $this->normalizeName($candidateName);
            if ($normalizedCandidate === $normalizedInput) {
                return $candidate;
            }
            similar_text(strtoupper($candidateName), strtoupper($name), $percent);
            // Boost score when input is a meaningful substring of the candidate name
            // e.g., "Public Safety" matching "Militia, Police and Public Safety"
            if (strlen($normalizedInput) >= 6 && str_contains($normalizedCandidate, $normalizedInput)) {
                $percent = max($percent, 80.0);
            }
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $candidate;
            }
        }
        // Only accept matches with at least 70% similarity
        if ($bestScore < 70) {
            return null;
        }
        return $best;
    }

    private function matchParentChildLabel(string $name, string $chamberKey, array $candidates): ?array
    {
        [$parentLabel, $childLabel] = array_map('trim', explode(':', $name, 2));
        if ($parentLabel === '' || $childLabel === '') {
            return null;
        }

        $parentLabel = $this->expandAbbreviations($parentLabel);
        $parent = $this->matchParentCommittee($parentLabel, $chamberKey);
        if (!$parent) {
            return null;
        }

        return $this->matchChildUnderParent($parent, $childLabel, $candidates);
    }

    private function matchParentCommittee(string $label, string $chamberKey): ?array
    {
        $committeeCandidates = $this->byChamberType[$chamberKey]['committee'] ?? [];
        $normalized = $this->normalizeName($label);
        $best = null;
        $bestScore = 0;
        foreach ($committeeCandidates as $candidate) {
            $candidateName = (string) $candidate['name'];
            if ($this->normalizeName($candidateName) === $normalized) {
                return $candidate;
            }
            similar_text(strtoupper($candidateName), strtoupper($label), $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $candidate;
            }
        }
        return $bestScore >= 70 ? $best : null;
    }

    private function matchSubcommitteeLabel(string $name, string $normalizedInput, string $chamberKey, array $candidates): ?array
    {
        $inputLabel = $this->stripSubcommitteeSuffix($name);
        $inputNormalized = $this->normalizeSubcommitteeLabel($inputLabel);
        if ($inputNormalized === '') {
            return null;
        }

        $parts = $this->splitLabelParts($inputLabel);
        $usePartsOnly = count($parts) > 1;
        $minScore = preg_match('/[-\\/]/', $inputLabel) ? 85 : 70;

        $parentFromPrefix = $this->matchParentByPrefix($name, $chamberKey);
        if ($parentFromPrefix) {
            $childLabel = trim(substr($name, strlen($parentFromPrefix['name'])));
            if (!str_contains(strtolower($childLabel), ' and ')) {
                $childLabel = $this->stripSubcommitteeSuffix($childLabel);
                $matched = $this->matchChildUnderParent($parentFromPrefix, $childLabel, $candidates);
                if ($matched) {
                    return $matched;
                }
            }
        }

        $inputHasNumber = (bool) preg_match('/#\\d+/', $inputLabel);
        $best = null;
        $bestScore = 0;
        foreach ($candidates as $candidate) {
            $candidateName = (string) $candidate['name'];
            $candidateLabel = $this->stripSubcommitteeSuffix($candidateName);
            $candidateNormalized = $this->normalizeSubcommitteeLabel($candidateLabel);
            if ($candidateNormalized === '') {
                continue;
            }

            if ($usePartsOnly) {
                $score = $this->scoreLabelParts($parts, $candidateLabel);
            } else {
                $score = $this->scoreNormalizedMatch($inputNormalized, $candidateNormalized);
                $score = max($score, $this->scoreLabelParts($parts, $candidateLabel));
            }
            if (!$inputHasNumber && preg_match('/#\\d+/', $candidateLabel)) {
                $score -= 15;
            }
            if ($inputHasNumber && preg_match('/#\\d+/', $candidateLabel)) {
                $score += 5;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $bestScore >= $minScore ? $best : null;
    }

    private function matchParentByPrefix(string $name, string $chamberKey): ?array
    {
        $committeeCandidates = $this->byChamberType[$chamberKey]['committee'] ?? [];
        foreach ($committeeCandidates as $candidate) {
            $candidateName = (string) $candidate['name'];
            if (str_starts_with($this->normalizeName($name), $this->normalizeName($candidateName))) {
                return $candidate;
            }
        }
        return null;
    }

    private function matchChildUnderParent(array $parent, string $childLabel, array $candidates): ?array
    {
        $parts = $this->splitLabelParts($childLabel);
        $usePartsOnly = count($parts) > 1;
        $childNormalized = $this->normalizeSubcommitteeLabel($childLabel);
        $best = null;
        $bestScore = 0;
        foreach ($candidates as $candidate) {
            if (($candidate['parent_id'] ?? null) !== $parent['id']) {
                continue;
            }
            $candidateName = (string) $candidate['name'];
            $candidateLabel = $candidateName;
            if (str_starts_with($this->normalizeName($candidateName), $this->normalizeName($parent['name']))) {
                $candidateLabel = trim(substr($candidateName, strlen($parent['name'])));
            }
            $candidateNormalized = $this->normalizeSubcommitteeLabel($candidateLabel);
            if ($candidateNormalized === $childNormalized && $childNormalized !== '') {
                return $candidate;
            }
            if ($usePartsOnly) {
                $score = $this->scoreLabelParts($parts, $candidateLabel);
            } else {
                $score = $this->scoreNormalizedMatch($childNormalized, $candidateNormalized);
                $score = max($score, $this->scoreLabelParts($parts, $candidateLabel));
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $bestScore >= 70 ? $best : null;
    }

    private function splitLabelParts(string $label): array
    {
        $label = str_replace('&', '|', $label);
        $parts = array_map('trim', explode('|', $label));
        return array_values(array_filter($parts, static fn ($part) => $part !== ''));
    }

    private function stripSubcommitteeSuffix(string $label): string
    {
        $label = preg_replace('/\\bsubcomittee\\b/i', 'subcommittee', $label);
        $label = preg_replace('/\\bsubco\\b/i', 'subcommittee', $label);
        return trim(preg_replace('/\\bsubcommittee\\b/i', '', $label));
    }

    private function normalizeSubcommitteeLabel(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\\bsubcomittee\\b/i', 'subcommittee', $value);
        $value = preg_replace('/\\bsubco\\b/i', 'subcommittee', $value);
        $value = preg_replace('/\\bsubcommittee\\b/i', '', $value);
        $value = str_replace('&', 'and', $value);
        $value = preg_replace('/[^a-z0-9#\\s]/', ' ', $value);
        $value = preg_replace('/\\s+/', ' ', $value);
        return trim($value);
    }

    private function scoreNormalizedMatch(string $input, string $candidate): float
    {
        if ($input === '' || $candidate === '') {
            return 0.0;
        }
        if ($input === $candidate) {
            return 100.0;
        }
        similar_text(strtoupper($input), strtoupper($candidate), $percent);
        if (str_contains($candidate, $input) || str_contains($input, $candidate)) {
            $percent = max($percent + 10, 75.0);
            if (str_starts_with($input, $candidate)) {
                $percent += 5;
            }
        }
        return $percent;
    }

    private function scoreLabelParts(array $parts, string $candidateLabel): float
    {
        $best = 0.0;
        foreach ($parts as $index => $part) {
            $score = $this->scoreNormalizedMatch(
                $this->normalizeSubcommitteeLabel($part),
                $this->normalizeSubcommitteeLabel($candidateLabel)
            );
            $score += $index * 0.5;
            if ($score >= $best) {
                $best = $score;
            }
        }
        return $best;
    }

    private function expandAbbreviations(string $name): string
    {
        $replacements = [
            '/\\bACNR\\b/i' => 'Agriculture, Conservation and Natural Resources',
            '/\\bCCT\\b/i' => 'Counties, Cities and Towns',
            '/\\bSFAC\\b/i' => 'Finance and Appropriations',
        ];
        return preg_replace(array_keys($replacements), array_values($replacements), $name);
    }

    private function resolveAliasMatch(string $normalizedInput, string $chamberKey, string $typeKey): ?array
    {
        $aliases = [
            'compensation and retirement subcommittee' => [
                'name' => "Workers' Compensation, Unemployment Compensation and Labor",
                'chamber' => 'senate',
                'type' => 'subcommittee',
            ],
            'criminal subcommittee' => [
                'name' => 'Criminal',
                'chamber' => 'senate',
                'type' => 'subcommittee',
            ],
            // "Capital Outlay & Transportation" is how the Virginia Senate YouTube channel
            // labels the Finance and Appropriations Capital Outlay subcommittee.
            // Without this alias, "Transportation" (id=90) wins due to the index bonus
            // in scoreLabelParts() when scoring part[1] "Transportation Subcommittee".
            'capital outlay and transportation' => [
                'name' => 'Capital Outlay',
                'chamber' => 'senate',
                'type' => 'subcommittee',
            ],
        ];

        $alias = null;
        $normalizedLookup = $normalizedInput;
        foreach ($aliases as $key => $value) {
            $keyNormalized = $this->normalizeName($key);
            if ($normalizedLookup === $keyNormalized || str_contains($normalizedLookup, $keyNormalized)) {
                $alias = $value;
                break;
            }
        }
        if ($alias === null) {
            $normalizedLookup = trim(preg_replace('/\\bsubcommittee\\b/', '', $normalizedLookup));
            foreach ($aliases as $key => $value) {
                $keyNormalized = trim(preg_replace('/\\bsubcommittee\\b/', '', $this->normalizeName($key)));
                if ($normalizedLookup !== '' && $normalizedLookup === $keyNormalized) {
                    $alias = $value;
                    break;
                }
            }
        }
        if ($alias === null) {
            return null;
        }
        $candidates = $this->byChamberType[$alias['chamber']][$alias['type']] ?? [];
        $best = null;
        $bestScore = 0;
        foreach ($candidates as $candidate) {
            $candidateName = (string) $candidate['name'];
            if ($this->normalizeName($candidateName) === $this->normalizeName($alias['name'])) {
                return $candidate;
            }
            similar_text(strtoupper($candidateName), strtoupper($alias['name']), $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $candidate;
            }
        }

        return $bestScore >= 70 ? $best : null;
    }

    private function normalizeName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace('&', 'and', $value);
        $value = preg_replace('/[^a-z0-9\\s]/', ' ', $value);
        $value = preg_replace('/\\s+/', ' ', $value);
        return trim($value);
    }
}
