<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

class SpeakerMetadataExtractor
{
    /**
     * @return array<int,array{name:string,start:float}>
     */
    public function extract(?array $metadata): array
    {
        if (!$metadata || empty($metadata['Speakers'])) {
            return [];
        }
        $segments = [];
        foreach ($metadata['Speakers'] as $entry) {
            $name = trim((string) ($entry['text'] ?? ''));
            $start = $entry['startTime'] ?? null;
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
