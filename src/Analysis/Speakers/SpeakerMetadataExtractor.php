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

        // Collect raw entries first
        $raw = [];
        foreach ($speakerData as $entry) {
            $name = trim((string) ($entry['name'] ?? $entry['text'] ?? ''));
            $start = $entry['start_time'] ?? $entry['startTime'] ?? null;
            if ($name === '' || !$start) {
                continue;
            }
            $raw[] = ['name' => $name, 'start' => (string) $start];
        }

        // Detect ISO timestamps and find earliest as baseline
        $baseline = null;
        foreach ($raw as $entry) {
            if ($this->isIsoTimestamp($entry['start'])) {
                $ts = strtotime($entry['start']);
                if ($ts !== false && ($baseline === null || $ts < $baseline)) {
                    $baseline = $ts;
                }
            }
        }

        $segments = [];
        foreach ($raw as $entry) {
            if ($baseline !== null && $this->isIsoTimestamp($entry['start'])) {
                $ts = strtotime($entry['start']);
                $seconds = ($ts !== false) ? (float) max(0, $ts - $baseline) : 0.0;
            } else {
                $seconds = $this->parseTime($entry['start']);
            }
            $segments[] = [
                'name' => $this->normalizeName($entry['name']),
                'start' => $seconds,
            ];
        }
        return $segments;
    }

    private function isIsoTimestamp(string $value): bool
    {
        return (strpos($value, 'T') !== false) ||
               (preg_match('/\d{4}-\d{2}-\d{2}/', $value) === 1);
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
