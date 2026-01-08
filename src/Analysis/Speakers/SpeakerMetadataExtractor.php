<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

class SpeakerMetadataExtractor
{
    /**
     * @return array<int,array{name:string,start:float}>
     */
    public function extract(?array $metadata): array
    {
        // Support both 'Speakers' (raw from Sliq) and 'speakers' (normalized by HouseScraper)
        $speakerData = $metadata['speakers'] ?? $metadata['Speakers'] ?? null;
        if (!$metadata || empty($speakerData)) {
            return [];
        }
        $segments = [];
        foreach ($speakerData as $entry) {
            // Support both raw format (text/startTime) and normalized format (name/start_time)
            $name = trim((string) ($entry['name'] ?? $entry['text'] ?? ''));
            $start = $entry['start_time'] ?? $entry['startTime'] ?? null;
            if ($name === '' || !$start) {
                continue;
            }
            $segments[] = [
                'name' => $this->normalizeName($name),
                'start' => strtotime($start) ?: $this->parseTime($start),
            ];
        }
        return $segments;
    }

    private function normalizeName(string $name): string
    {
        $patterns = ['/^Delegate\s+/i','/^Senator\s+/i','/^Chair\s+/i'];
        return trim(preg_replace($patterns, '', $name));
    }

    private function parseTime(string $value): float
    {
        if (preg_match('/(\d{2}):(\d{2}):(\d{2})/', $value, $match)) {
            return (int)$match[1] * 3600 + (int)$match[2] * 60 + (int)$match[3];
        }
        return 0.0;
    }
}
