<?php

namespace RichmondSunlight\VideoProcessor\Resolution\FuzzyMatcher;

/**
 * Handles bill number parsing and OCR error correction.
 * Critical: Must be very conservative to avoid wrong bill matches.
 */
class BillNumberMatcher
{
    /**
     * Parse bill number from raw text.
     *
     * @return array{chamber: string, type: string, number: string, raw: string}|null
     */
    public function parseBillNumber(string $rawText): ?array
    {
        // Expected formats:
        // HB1234, HB 1234, H.B. 1234, House Bill 1234
        // SB567, SB 567, S.B. 567, Senate Bill 567
        // + Resolutions: HJR, SJR, HR, SR

        // Pre-process: Fix common OCR errors in bill prefixes
        $rawText = $this->correctPrefixOcrErrors($rawText);

        $patterns = [
            '/\b(HB|H\.B\.|House\s+Bill)\s*(\d{1,4})\b/i' => ['chamber' => 'house', 'type' => 'bill'],
            '/\b(SB|S\.B\.|Senate\s+Bill)\s*(\d{1,4})\b/i' => ['chamber' => 'senate', 'type' => 'bill'],
            '/\b(HJ|HJR|H\.J\.R\.|House\s+Joint\s+Resolution)\s*(\d{1,4})\b/i' => ['chamber' => 'house', 'type' => 'joint_resolution'],
            '/\b(SJ|SJR|S\.J\.R\.|Senate\s+Joint\s+Resolution)\s*(\d{1,4})\b/i' => ['chamber' => 'senate', 'type' => 'joint_resolution'],
            '/\b(HR|H\.R\.|House\s+Resolution)\s*(\d{1,4})\b/i' => ['chamber' => 'house', 'type' => 'resolution'],
            '/\b(SR|S\.R\.|Senate\s+Resolution)\s*(\d{1,4})\b/i' => ['chamber' => 'senate', 'type' => 'resolution'],
        ];

        foreach ($patterns as $pattern => $meta) {
            if (preg_match($pattern, $rawText, $matches)) {
                $number = ltrim($matches[2], '0') ?: '0'; // Remove leading zeros

                return [
                    'chamber' => $meta['chamber'],
                    'type' => $meta['type'],
                    'number' => $number,
                    'raw' => $matches[0],
                ];
            }
        }

        return null;
    }

    /**
     * Correct common OCR errors in bill prefixes.
     * Handles: H8→HB, 1H→H, LH→H, S8→SB, etc.
     */
    private function correctPrefixOcrErrors(string $text): string
    {
        // Common OCR substitutions for bill prefixes
        $substitutions = [
            // "H8" → "HB" (8 confused with B)
            '/\b([HL1I])\s*[8]\s*(\d)/i' => 'HB $2',
            // "H3" → "HB" (3 confused with B)
            '/\b([HL1I])\s*[3]\s*(\d)/i' => 'HB $2',
            // "HE" → "HB" (E confused with B)
            '/\b([HL1I])\s*[E]\s*(\d)/i' => 'HB $2',
            // "S8" → "SB" (8 confused with B)
            '/\b[5S]\s*[8]\s*(\d)/i' => 'SB $1',
            // "SE" → "SB" (E confused with B)
            '/\b[5S]\s*[E]\s*(\d)/i' => 'SB $1',
            // "LHB" or "1HB" → "HB" (leading character before H)
            '/\b[L1I]\s*H\s*B\s*(\d)/i' => 'HB $1',
            // "LSB" or "1SB" → "SB"
            '/\b[L1I]\s*S\s*B\s*(\d)/i' => 'SB $1',
            // "HJ8" → "HJR", "SJ8" → "SJR"
            '/\b([HS])\s*J\s*[8R]\s*(\d)/i' => '$1JR $2',
            // "H 8" with space → "HB"
            '/\b([HL1I])\s+[8]\s+(\d)/i' => 'HB $2',
            '/\bS\s+[8]\s+(\d)/i' => 'SB $1',
        ];

        foreach ($substitutions as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * Generate conservative number variations for OCR errors.
     * Only single-character substitutions to avoid false matches.
     *
     * @return array<int, string>
     */
    public function generateNumberVariations(string $number): array
    {
        $variations = [];

        // Conservative OCR pairs for digits only
        $ocrPairs = [
            '0' => ['8', 'O'],
            '1' => ['7', 'l', 'I'],
            '5' => ['6', 'S'],
            '8' => ['0', '3', 'B'],
        ];

        // Generate single-character substitutions only
        for ($i = 0; $i < strlen($number); $i++) {
            $char = $number[$i];
            if (isset($ocrPairs[$char])) {
                foreach ($ocrPairs[$char] as $replacement) {
                    $variant = substr_replace($number, $replacement, $i, 1);
                    $variations[] = $variant;
                }
            }
        }

        return array_unique($variations);
    }

    /**
     * Format bill number consistently (e.g., "HB1234").
     */
    public function formatBillNumber(string $chamber, string $type, string $number): string
    {
        $prefix = match ($chamber . '_' . $type) {
            'house_bill' => 'HB',
            'senate_bill' => 'SB',
            'house_joint_resolution' => 'HJR',
            'senate_joint_resolution' => 'SJR',
            'house_resolution' => 'HR',
            'senate_resolution' => 'SR',
            default => 'UNKNOWN',
        };

        return $prefix . $number;
    }

    /**
     * Determine chamber from bill prefix.
     */
    public function determineChamber(string $prefix): string
    {
        return match (strtoupper(substr($prefix, 0, 1))) {
            'H' => 'house',
            'S' => 'senate',
            default => 'unknown',
        };
    }

    /**
     * Determine bill type from prefix.
     */
    public function determineBillType(string $prefix): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Z]/', '', $prefix));

        return match ($prefix) {
            'HB', 'SB' => 'bill',
            'HJ', 'HJR', 'SJ', 'SJR' => 'joint_resolution',
            'HR', 'SR' => 'resolution',
            default => 'unknown',
        };
    }
}
