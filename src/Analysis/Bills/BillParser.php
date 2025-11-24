<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

class BillParser
{
    public function parse(string $text): array
    {
        $text = strtoupper($text);
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
