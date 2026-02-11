<?php

namespace RichmondSunlight\VideoProcessor\Scraper\House;

use DateTimeImmutable;
use DateTimeInterface;
use Log;
use RichmondSunlight\VideoProcessor\Scraper\Http\HttpClientInterface;
use RichmondSunlight\VideoProcessor\Scraper\VideoSourceScraperInterface;

class HouseScraper implements VideoSourceScraperInterface
{
    private const DEFAULT_BASE = 'https://sg001-harmony.sliq.net/00304/Harmony';
    private const LIST_ENDPOINT = '/en/api/Data/GetListViewData';
    private const DETAIL_PATH = '/en/PowerBrowser/PowerBrowserV2';

    public function __construct(
        private HttpClientInterface $client,
        private string $baseUrl = self::DEFAULT_BASE,
        private array $listingParams = [],
        private ?Log $logger = null,
        private ?int $maxRecords = null
    ) {
    }

    public function scrape(): array
    {
        echo "  Fetching House listing...\n";
        $listing = $this->fetchListing();
        $events = $this->flattenWeeks($listing);
        echo "  Found " . count($events) . " House events to process\n";
        $videos = [];

        foreach ($events as $i => $event) {
            if (!$this->shouldProcess($event)) {
                continue;
            }

            $title = $event['Title'] ?? 'Unknown';
            echo "  [" . ($i + 1) . "/" . count($events) . "] Processing: {$title}\n";
            $detailUrl = $this->buildDetailUrl($event);
            $detailHtml = $this->client->get($detailUrl);
            $videos[] = $this->parseDetailPage($event, $detailUrl, $detailHtml);

            if ($this->maxRecords !== null && count($videos) >= $this->maxRecords) {
                break;
            }
        }

        if ($this->logger) {
            $this->logger->put(sprintf('House scraper gathered %d videos', count($videos)), 3);
        }

        return $videos;
    }

    private function fetchListing(): array
    {
        $default = [
            'categoryId' => -1,
            'fromDate' => '',
            'endDate' => '',
            'searchTime' => '',
            'searchForward' => 'true',
            'order' => 0,
        ];
        $params = array_merge($default, $this->listingParams);
        $url = $this->baseUrl . self::LIST_ENDPOINT . '?' . http_build_query($params);

        $body = $this->client->get($url);

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function flattenWeeks(array $listing): array
    {
        $events = [];
        foreach ($listing['Weeks'] ?? [] as $week) {
            foreach ($week['ContentEntityDatas'] ?? [] as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function shouldProcess(array $event): bool
    {
        return isset($event['Id']);
    }

    private function buildDetailUrl(array $event): string
    {
        $start = !empty($event['ScheduledStart'])
            ? new DateTimeImmutable($event['ScheduledStart'])
            : new DateTimeImmutable('now');
        $currentDate = $start->format('Ymd');

        return sprintf(
            '%s%s/%s/-1/%s',
            $this->baseUrl,
            self::DETAIL_PATH,
            $currentDate,
            $event['Id']
        );
    }

    private function parseDetailPage(array $event, string $detailUrl, string $html): array
    {
        $rootState = $this->extractRootClone($html);
        $downloadMedia = $this->extractJsonArray($html, 'downloadMediaUrls');
        $agendaItems = $this->extractJsonSection($html, 'AgendaTree');
        $speakerItems = $this->extractJsonSection($html, 'Speakers');
        $ccItems = $this->extractJsonSection($html, 'ccItems');
        $captions = $this->extractCaptionsWebVtt($ccItems);

        $title = $event['Title'] ?? $rootState['set_title'] ?? '';
        $description = $event['Description'] ?? $rootState['set_description'] ?? '';
        $scheduledStart = $event['ScheduledStart'] ?? $rootState['set_scheduledStart'] ?? null;

        // Determine event type: floor sessions are the exception, not the rule.
        // Only explicitly labeled sessions ("House Session", "Floor Session", or
        // "Regular Session" in description) are floor. Everything else is a
        // committee or subcommittee meeting, with the title as the committee name.
        $titleTrimmed = trim($title);
        $isFloorSession = stripos($titleTrimmed, 'House Session') !== false ||
                          stripos($titleTrimmed, 'Floor Session') !== false ||
                          stripos($description, 'Regular Session') !== false ||
                          stripos($description, 'Special Session') !== false;

        if ($isFloorSession) {
            $eventType = 'floor';
            $committeeName = null;
        } else {
            $committeeName = $titleTrimmed ?: null;
            $eventType = ($committeeName && stripos($committeeName, 'subcommittee') !== false)
                ? 'subcommittee'
                : 'committee';
        }

        return [
            'source' => 'house',
            'chamber' => 'house',
            'content_id' => (int) $event['Id'],
            'title' => $title,
            'description' => $description,
            'committee_name' => $committeeName,
            'event_type' => $eventType,
            'location' => $event['Location'] ?? $rootState['set_location'] ?? null,
            'scheduled_start' => $scheduledStart,
            'scheduled_end' => $event['ScheduledEnd'] ?? $rootState['set_scheduledEnd'] ?? null,
            'actual_start' => $event['ActualStart'] ?? $rootState['set_actualStart'] ?? null,
            'actual_end' => $event['ActualEnd'] ?? $rootState['set_actualEnd'] ?? null,
            'duration_seconds' => $rootState['mediaDuration'] ?? null,
            'video_url' => $downloadMedia[0]['Url'] ?? null,
            'media_urls' => array_map(
                static fn (array $entry) => [
                    'name' => $entry['Name'] ?? $entry['NAME'] ?? null,
                    'url' => $entry['Url'] ?? null,
                    'audio_only' => $entry['AudioOnly'] ?? false,
                ],
                $downloadMedia
            ),
            'agenda' => array_map(
                static fn (array $item) => [
                    'key' => $item['key'] ?? null,
                    'text' => $item['text'] ?? null,
                    'start_time' => $item['startTime'] ?? null,
                ],
                $agendaItems
            ),
            'speakers' => array_map(
                static fn (array $item) => [
                    'name' => $item['text'] ?? null,
                    'start_time' => $item['startTime'] ?? null,
                    'agenda_key' => $item['parentKey'] ?? null,
                ],
                $speakerItems
            ),
            'detail_url' => $detailUrl,
            'captions_url' => null,
            'captions' => $captions,
            'scraped_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        ];
    }

    private function extractJsonArray(string $html, string $variable): array
    {
        $block = $this->extractJsonBlock($html, $variable);
        if ($block === null) {
            return [];
        }

        return json_decode($block, true, 512, JSON_THROW_ON_ERROR);
    }

    private function extractJsonSection(string $html, string $key): array
    {
        $block = $this->extractJsonBlock($html, $key);
        if ($block === null) {
            return [];
        }

        return json_decode($block, true, 512, JSON_THROW_ON_ERROR);
    }

    private function extractRootClone(string $html): array
    {
        if (!preg_match('/Root\.clone\(\s*(\{[\s\S]*?\})\s*\);/m', $html, $matches)) {
            return [];
        }

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Extract a JSON object/array following a key by scanning for balanced brackets.
     */
    private function extractJsonBlock(string $html, string $key): ?string
    {
        if (!preg_match('/' . preg_quote($key, '/') . '\s*(?:=|:)\s*([{\[])/', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $startChar = $matches[1][0];
        $startPos = $matches[1][1];
        $segment = substr($html, $startPos);

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($segment);
        for ($i = 0; $i < $len; $i++) {
            $ch = $segment[$i];
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($ch === '\\') {
                $escape = true;
                continue;
            }
            if ($ch === '"') {
                $inString = !$inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ($ch === '{' || $ch === '[') {
                $depth++;
            } elseif ($ch === '}' || $ch === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($segment, 0, $i + 1);
                }
            }
        }

        return null;
    }

    private function extractCaptionsWebVtt(array $ccItems): ?string
    {
        if (empty($ccItems)) {
            return null;
        }

        // Pick the first language block (e.g., ['en' => [...] ]).
        $first = array_values($ccItems)[0] ?? null;
        if (!is_array($first)) {
            return null;
        }

        // If nested under a language key.
        if (array_key_exists('Begin', $first) || array_key_exists(0, $first)) {
            $entries = array_key_exists(0, $first) ? $first : $ccItems;
        } else {
            $entries = $first;
        }
        if (!is_array($entries)) {
            return null;
        }

        // Use first timestamp as zero to create relative cues.
        $segments = [];
        $firstStart = null;
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['Begin']) || empty($entry['End']) || !isset($entry['Content'])) {
                continue;
            }
            $begin = strtotime($entry['Begin']);
            $end = strtotime($entry['End']);
            if ($begin === false || $end === false) {
                continue;
            }
            $firstStart ??= $begin;
            $segments[] = [
                'start' => $begin - $firstStart,
                'end' => $end - $firstStart,
                'text' => trim((string) $entry['Content']),
            ];
        }

        if (empty($segments)) {
            return null;
        }

        $buffer = ["WEBVTT", ""];
        foreach ($segments as $seg) {
            $buffer[] = $this->formatSeconds($seg['start']) . ' --> ' . $this->formatSeconds($seg['end']);
            $buffer[] = $seg['text'];
            $buffer[] = '';
        }

        return implode("\n", $buffer);
    }

    private function formatSeconds(float $seconds): string
    {
        $ms = (int) round(($seconds - floor($seconds)) * 1000);
        $totalSeconds = (int) floor($seconds);
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $secs = $totalSeconds % 60;
        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $ms);
    }
}
