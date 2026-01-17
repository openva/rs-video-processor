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
