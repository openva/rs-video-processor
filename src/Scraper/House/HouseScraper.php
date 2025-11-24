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
        private ?Log $logger = null
    ) {
    }

    public function scrape(): array
    {
        $listing = $this->fetchListing();
        $videos = [];

        foreach ($this->flattenWeeks($listing) as $event) {
            if (!$this->shouldProcess($event)) {
                continue;
            }

            $detailUrl = $this->buildDetailUrl($event);
            $detailHtml = $this->client->get($detailUrl);
            $videos[] = $this->parseDetailPage($event, $detailUrl, $detailHtml);
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

        $title = $event['Title'] ?? $rootState['set_title'] ?? '';
        $description = $event['Description'] ?? $rootState['set_description'] ?? '';
        $scheduledStart = $event['ScheduledStart'] ?? $rootState['set_scheduledStart'] ?? null;
        $isCommittee = !empty($event['CommitteeId']);
        $committeeName = $isCommittee ? $title : null;
        $eventType = $isCommittee ? (stripos($committeeName ?? '', 'subcommittee') !== false ? 'subcommittee' : 'committee') : 'floor';

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
            'scraped_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        ];
    }

    private function extractJsonArray(string $html, string $variable): array
    {
        $pattern = sprintf('/var\s+%s\s*=\s*(\[[\s\S]*?\]);/m', preg_quote($variable, '/'));
        if (!preg_match($pattern, $html, $matches)) {
            return [];
        }

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function extractJsonSection(string $html, string $key): array
    {
        $pattern = sprintf('/%s\s*:\s*(\[[\s\S]*?\])/m', preg_quote($key, '/'));
        if (!preg_match($pattern, $html, $matches)) {
            return [];
        }

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function extractRootClone(string $html): array
    {
        if (!preg_match('/Root\.clone\(\s*(\{[\s\S]*?\})\s*\);/m', $html, $matches)) {
            return [];
        }

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
