<?php

namespace RichmondSunlight\VideoProcessor\Resolution\FuzzyMatcher;

/**
 * Handles fuzzy matching of legislator names with OCR error correction.
 */
class NameMatcher
{
    private SimilarityCalculator $similarity;

    public function __construct(?SimilarityCalculator $similarity = null)
    {
        $this->similarity = $similarity ?? new SimilarityCalculator();
    }

    /**
     * Extract clean name from raw text, removing titles, parties, districts, etc.
     *
     * @return array{cleaned: string, tokens: array<int, string>, prefix: ?string, party: ?string, district: ?string}
     */
    public function extractLegislatorName(string $rawText): array
    {
        $original = $rawText;

        // Normalize whitespace: replace newlines, tabs, and multiple spaces with single space
        $rawText = preg_replace('/[\r\n\t]+/', ' ', $rawText);
        $rawText = preg_replace('/\s+/', ' ', $rawText);
        $rawText = trim($rawText);

        // Extract and remove prefix
        $prefix = null;
        $prefixes = ['Sen\.', 'Del\.', 'Delegate', 'Senator', 'Chair', 'Vice Chair', 'Rep\.', 'Representative'];
        foreach ($prefixes as $p) {
            if (preg_match('/^(' . $p . ')\s+/i', $rawText, $matches)) {
                $prefix = $matches[1];
                $rawText = preg_replace('/^' . $p . '\s+/i', '', $rawText);
                break;
            }
        }

        // Extract nickname from parentheses and use it preferentially
        // E.g., "Thomas A. (Tom) Garrett" → "Tom Garrett"
        // E.g., "C. E. (Cliff) Hayes" → "Cliff Hayes"
        if (preg_match('/\(([A-Za-z][A-Za-z\s\-]+)\)\s*(.*)$/i', $rawText, $matches)) {
            $nickname = trim($matches[1]);
            $afterNickname = trim($matches[2]);

            // Check if this looks like a nickname (not a party indicator like "R" or "D-6")
            if (!preg_match('/^[RDI](-\d+)?$/i', $nickname)) {
                // Replace everything before and including the nickname with just the nickname
                // Keep everything after the nickname (usually the last name)
                $rawText = $nickname . ($afterNickname ? ' ' . $afterNickname : '');
            }
        }

        // Extract and remove party indicator and district
        $party = null;
        $district = null;

        // Handle (R-6), (D-42), etc.
        if (preg_match('/\(([RDI])-(\d+)\)/i', $rawText, $matches)) {
            $party = strtoupper($matches[1]);
            $district = $matches[2];
            $rawText = preg_replace('/\(([RDI])-\d+\)/i', '', $rawText);
        }
        // Handle (R), (D), (I)
        elseif (preg_match('/\(([RDI])\)/i', $rawText, $matches)) {
            $party = strtoupper($matches[1]);
            $rawText = preg_replace('/\(([RDI])\)/i', '', $rawText);
        }

        // Handle "District 42"
        if (preg_match('/District\s+(\d+)/i', $rawText, $matches)) {
            $district = $matches[1];
            $rawText = preg_replace('/District\s+\d+/i', '', $rawText);
        }

        // Remove location indicators and district numbers
        // E.g., "Smith - Richmond", "Smith of Richmond", "Pogge James City (996)"
        $rawText = preg_replace('/\s+of\s+[A-Z][a-z]+/i', '', $rawText);
        // Require whitespace before dash to preserve hyphenated names (e.g., "Keys-Gamarra")
        $rawText = preg_replace('/\s+-\s*[A-Z][a-z]+$/i', '', $rawText);
        // Remove multi-word location followed by number in parens (e.g., "James City (996)")
        $rawText = preg_replace('/\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\s*\(\d+\)/i', '', $rawText);
        // Remove standalone numbers in parentheses that aren't part of party (e.g., "(996)")
        $rawText = preg_replace('/\s*\(\d+\)/', '', $rawText);

        // Clean up whitespace and punctuation
        $rawText = preg_replace('/[,\(\)]+/', ' ', $rawText);
        $rawText = trim(preg_replace('/\s+/', ' ', $rawText));

        $tokens = array_filter(explode(' ', $rawText), fn($t) => strlen($t) > 0);

        return [
            'cleaned' => $rawText,
            'tokens' => array_values($tokens),
            'prefix' => $prefix,
            'party' => $party,
            'district' => $district,
        ];
    }

    /**
     * Pivot comma-separated name ("Lastname, Firstname" → "Firstname Lastname").
     */
    public function pivotCommaName(string $name): string
    {
        $parts = array_map('trim', explode(',', $name, 2));
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            return $parts[1] . ' ' . $parts[0];
        }
        return $name;
    }

    /**
     * Generate OCR error variations for a name.
     *
     * @return array<int, string>
     */
    public function generateOcrVariations(string $name, int $maxVariations = 10): array
    {
        $variations = [$name];

        // Common OCR substitutions
        $substitutions = [
            '0' => ['O', 'o'],
            'O' => ['0'],
            'o' => ['0'],
            '1' => ['l', 'I', 'i'],
            'l' => ['1', 'I'],
            'I' => ['1', 'l'],
            '5' => ['S', 's'],
            'S' => ['5'],
            '8' => ['B'],
            'B' => ['8'],
            '6' => ['G'],
            'G' => ['6'],
        ];

        // Generate single-character substitutions
        for ($i = 0; $i < strlen($name) && count($variations) < $maxVariations; $i++) {
            $char = $name[$i];
            if (isset($substitutions[$char])) {
                foreach ($substitutions[$char] as $replacement) {
                    $variant = substr_replace($name, $replacement, $i, 1);
                    if (!in_array($variant, $variations)) {
                        $variations[] = $variant;
                        if (count($variations) >= $maxVariations) {
                            break 2;
                        }
                    }
                }
            }
        }

        return $variations;
    }

    /**
     * Calculate match score for a candidate name against cleaned text.
     *
     * @param string $rawTextCleaned Cleaned raw text from OCR
     * @param string $candidateName Candidate name from database (may be comma-formatted)
     * @param array<int, string> $rawTokens Tokens from cleaned raw text
     * @return float Score from 0.0 to 100.0
     */
    public function calculateNameScore(
        string $rawTextCleaned,
        string $candidateName,
        array $rawTokens
    ): float {
        $candidate = $this->pivotCommaName($candidateName);

        // 1. Exact match (case-insensitive)
        if (strcasecmp($rawTextCleaned, $candidate) === 0) {
            return 100.0;
        }

        // 2. Generate OCR variations and check for matches
        $variations = $this->generateOcrVariations($rawTextCleaned);
        foreach ($variations as $variant) {
            if (strcasecmp($variant, $candidate) === 0) {
                return 95.0; // Very high confidence for OCR variation match
            }
        }

        // 3. Token sequence matching (e.g., "Mundon King" matches "Candice P. Mundon King")
        $candidateTokens = array_values(array_filter(explode(' ', $candidate), fn($t) => strlen($t) > 0));

        // Check if rawTokens is a contiguous sequence within candidateTokens
        if (count($rawTokens) > 0 && count($rawTokens) < count($candidateTokens)) {
            $rawCount = count($rawTokens);
            $candCount = count($candidateTokens);

            for ($i = 0; $i <= $candCount - $rawCount; $i++) {
                $match = true;
                for ($j = 0; $j < $rawCount; $j++) {
                    if (strcasecmp($rawTokens[$j], $candidateTokens[$i + $j]) !== 0) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    // Exact contiguous match - very high confidence
                    // Favor matches at the end (last name) over middle names
                    $isAtEnd = ($i + $rawCount === $candCount);
                    return $isAtEnd ? 95.0 : 92.0;
                }
            }
        }

        // 4. Last-name-only matching (e.g., "Delegate Watts" → "Watts")
        if (count($rawTokens) === 1) {
            if (count($candidateTokens) > 1) {
                $lastName = end($candidateTokens);
                // Check if the single token matches the candidate's last name
                if (strcasecmp($rawTokens[0], $lastName) === 0) {
                    return 90.0; // High confidence for last name match
                }
                // Check OCR variations of the last name
                $lastNameVariations = $this->generateOcrVariations($rawTokens[0]);
                foreach ($lastNameVariations as $variant) {
                    if (strcasecmp($variant, $lastName) === 0) {
                        return 85.0; // High confidence for OCR-corrected last name match
                    }
                }
                // Fuzzy match against just the last name
                $lastNameSimilarity = $this->similarity->combinedSimilarity(
                    $rawTokens[0],
                    $lastName,
                    0.3,
                    0.5,
                    0.2
                );
                if ($lastNameSimilarity > 0.85) {
                    return $lastNameSimilarity * 80.0; // Score up to 80 for fuzzy last name match
                }
            }
        }

        // 5. Fuzzy matching
        $combined = $this->similarity->combinedSimilarity(
            $rawTextCleaned,
            $candidate,
            0.3, // Levenshtein weight
            0.5, // Jaro-Winkler weight
            0.2  // Token set weight
        );

        // 6. Token matching bonus
        $tokenScore = $this->similarity->tokenSetRatio($rawTokens, $candidateTokens);

        // Weighted average: 70% combined similarity, 30% token matching
        $score = ($combined * 0.7 + $tokenScore * 0.3) * 100;

        // 7. Soundex bonus for phonetically similar names
        if ($this->similarity->soundexSimilarity($rawTextCleaned, $candidate)) {
            $score *= 1.1; // 10% boost
        }

        return min($score, 100.0);
    }
}
