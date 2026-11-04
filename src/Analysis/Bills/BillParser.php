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

        // "HB 1234", and Senate floor video's "S.B. 956". A space between the
        // letters is allowed only after a period, so stray "H B 12" is not a bill.
        $matches = [];
        preg_match_all('/\b([HS])(?:\.\s?)?B\.?\s*(\d{1,4})\b/', $text, $matches, PREG_SET_ORDER);
        $bills = [];
        foreach ($matches as $match) {
            $bills[] = $match[1] . 'B' . $match[2];
        }
        return array_values(array_unique($bills));
    }
}
