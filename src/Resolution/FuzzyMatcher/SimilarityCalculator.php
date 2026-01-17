<?php

namespace RichmondSunlight\VideoProcessor\Resolution\FuzzyMatcher;

/**
 * Provides various string similarity algorithms for fuzzy matching.
 */
class SimilarityCalculator
{
    /**
     * Calculate normalized Levenshtein distance (0 = completely different, 1 = identical).
     */
    public function levenshteinSimilarity(string $str1, string $str2): float
    {
        $str1 = mb_strtolower($str1);
        $str2 = mb_strtolower($str2);

        $maxLen = max(strlen($str1), strlen($str2));
        if ($maxLen === 0) {
            return 1.0;
        }

        $distance = levenshtein($str1, $str2);
        return 1.0 - ($distance / $maxLen);
    }

    /**
     * Calculate Jaro-Winkler similarity (0 = completely different, 1 = identical).
     * Better for short strings like names, gives weight to matching prefixes.
     */
    public function jaroWinklerSimilarity(string $str1, string $str2): float
    {
        $str1 = mb_strtolower($str1);
        $str2 = mb_strtolower($str2);

        if ($str1 === $str2) {
            return 1.0;
        }

        $len1 = strlen($str1);
        $len2 = strlen($str2);

        if ($len1 === 0 || $len2 === 0) {
            return 0.0;
        }

        // Jaro similarity
        $matchDistance = (int) floor(max($len1, $len2) / 2) - 1;
        $str1Matches = array_fill(0, $len1, false);
        $str2Matches = array_fill(0, $len2, false);

        $matches = 0;
        $transpositions = 0;

        // Find matches
        for ($i = 0; $i < $len1; $i++) {
            $start = max(0, $i - $matchDistance);
            $end = min($i + $matchDistance + 1, $len2);

            for ($j = $start; $j < $end; $j++) {
                if ($str2Matches[$j] || $str1[$i] !== $str2[$j]) {
                    continue;
                }
                $str1Matches[$i] = true;
                $str2Matches[$j] = true;
                $matches++;
                break;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        // Count transpositions
        $k = 0;
        for ($i = 0; $i < $len1; $i++) {
            if (!$str1Matches[$i]) {
                continue;
            }
            while (!$str2Matches[$k]) {
                $k++;
            }
            if ($str1[$i] !== $str2[$k]) {
                $transpositions++;
            }
            $k++;
        }

        $jaro = (($matches / $len1) +
                 ($matches / $len2) +
                 (($matches - $transpositions / 2) / $matches)) / 3;

        // Jaro-Winkler adjustment (prefix bonus)
        $prefixLen = 0;
        for ($i = 0; $i < min($len1, $len2, 4); $i++) {
            if ($str1[$i] === $str2[$i]) {
                $prefixLen++;
            } else {
                break;
            }
        }

        return $jaro + ($prefixLen * 0.1 * (1.0 - $jaro));
    }

    /**
     * Calculate token set ratio (0 = completely different, 1 = identical).
     * Handles word order differences ("Bob Smith" vs "Smith, Bob").
     */
    public function tokenSetRatio(array $tokens1, array $tokens2): float
    {
        $tokens1 = array_map('mb_strtolower', $tokens1);
        $tokens2 = array_map('mb_strtolower', $tokens2);

        $set1 = array_unique($tokens1);
        $set2 = array_unique($tokens2);

        $intersection = array_intersect($set1, $set2);
        $union = array_unique(array_merge($set1, $set2));

        if (empty($union)) {
            return 1.0;
        }

        return count($intersection) / count($union);
    }

    /**
     * Calculate combined similarity score using weighted average of methods.
     */
    public function combinedSimilarity(
        string $str1,
        string $str2,
        float $levWeight = 0.3,
        float $jaroWeight = 0.5,
        float $tokenWeight = 0.2
    ): float {
        $lev = $this->levenshteinSimilarity($str1, $str2);
        $jaro = $this->jaroWinklerSimilarity($str1, $str2);

        $tokens1 = explode(' ', $str1);
        $tokens2 = explode(' ', $str2);
        $token = $this->tokenSetRatio($tokens1, $tokens2);

        return ($lev * $levWeight) + ($jaro * $jaroWeight) + ($token * $tokenWeight);
    }

    /**
     * Calculate Soundex phonetic similarity (for pronunciation-similar names).
     */
    public function soundexSimilarity(string $str1, string $str2): bool
    {
        $str1 = mb_strtolower(trim($str1));
        $str2 = mb_strtolower(trim($str2));

        if (empty($str1) || empty($str2)) {
            return false;
        }

        return soundex($str1) === soundex($str2);
    }
}
