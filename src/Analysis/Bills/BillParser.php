<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

class BillParser
{
    /**
     * What Tesseract makes of a serif capital "H" in front of "B": House
     * committee video sets the bill number in a serif face, and "HB 746" is read
     * as "TIB 746", "IIB 746", "MIB 746", "1B 746" and so on. The digits are
     * read correctly, so only the prefix needs repairing. "SB" is not affected.
     */
    private const MISREAD_HB = '/(?<![A-Z0-9])(?:[TILMH1]{1,2}|I I)B(?=\s*\d{1,4}\b)/';

    public function parse(string $text): array
    {
        $text = strtoupper($text);
        $text = preg_replace(self::MISREAD_HB, 'HB', $text) ?? $text;

        $matches = [];
        preg_match_all('/\b([HS]B\s*\d{1,4})\b/', $text, $matches);
        $bills = [];
        foreach ($matches[1] as $bill) {
            $normalized = preg_replace('/\s+/', '', $bill);
            $bills[] = $normalized;
        }
        return array_values(array_unique($bills));
    }
}
