<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

class AgendaExtractor
{
    /**
     * @return array<int,array{time:float,bill:string}>
     */
    public function extract(?array $metadata): array
    {
        if (!$metadata || empty($metadata['AgendaTree'])) {
            return [];
        }
        $results = [];
        foreach ($metadata['AgendaTree'] as $item) {
            if (empty($item['text']) || empty($item['startTime'])) {
                continue;
            }
            $bill = $this->normalizeBill((string) $item['text']);
            if (!$bill) {
                continue;
            }
            $results[] = [
                'time' => strtotime($item['startTime']) ?: 0,
                'bill' => $bill,
            ];
        }
        return $results;
    }

    private function normalizeBill(string $text): ?string
    {
        if (preg_match('/\b([HS]B\s*\d{1,4})\b/i', $text, $match)) {
            return strtoupper(str_replace(' ', '', $match[1]));
        }
        return null;
    }
}
