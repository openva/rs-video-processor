<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use RichmondSunlight\VideoProcessor\Analysis\Metadata\SpeakerTimeline;

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

        // Measure from when the recording started, not from the first speaker.
        $baseline = SpeakerTimeline::recordingStart($metadata, array_column($raw, 'start'));

        $segments = [];
        foreach ($raw as $entry) {
            if ($baseline !== null && SpeakerTimeline::isTimestamp($entry['start'])) {
                $ts = SpeakerTimeline::toSeconds($entry['start']);
                $seconds = ($ts !== null) ? (float) max(0, $ts - $baseline) : 0.0;
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
