<?php

namespace RichmondSunlight\VideoProcessor\Scraper\Senate;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Log;
use RichmondSunlight\VideoProcessor\Scraper\Http\HttpClientInterface;
use RichmondSunlight\VideoProcessor\Scraper\VideoSourceScraperInterface;

class SenateScraper implements VideoSourceScraperInterface
{
    private const DEFAULT_LISTING_URL = 'https://virginia-senate.granicus.com/ViewPublisher.php?view_id=3';
    private const DETAIL_URL_TEMPLATE = 'https://virginia-senate.granicus.com/MediaPlayer.php?view_id=3&clip_id=%d';

    public function __construct(
        private HttpClientInterface $client,
        private string $listingUrl = self::DEFAULT_LISTING_URL,
        private ?Log $logger = null,
        private ?int $maxRecords = null
    ) {
    }

    public function scrape(): array
    {
        $listingHtml = $this->client->get($this->listingUrl);
        $dom = $this->createDom($listingHtml);
        $xpath = new DOMXPath($dom);
        $rows = $xpath->query("//table[@id='archive']//tr[td]");

        $results = [];
        if ($rows === false) {
            return $results;
        }

        foreach ($rows as $row) {
            if (!$row instanceof DOMElement) {
                continue;
            }
            $clipId = $this->extractClipId($row);
            if (!$clipId) {
                continue;
            }
            $baseRecord = $this->mapRow($row, $clipId);
            if (!$baseRecord) {
                continue;
            }

            $detail = $this->client->get(sprintf(self::DETAIL_URL_TEMPLATE, $clipId));
            $detailData = $this->parseDetail($detail);
            $results[] = array_merge($baseRecord, $detailData);

            if ($this->maxRecords !== null && count($results) >= $this->maxRecords) {
                break;
            }
        }

        if ($this->logger) {
            $this->logger->put(sprintf('Senate scraper gathered %d videos', count($results)), 3);
        }

        return $results;
    }

    private function mapRow(DOMElement $row, string $clipId): ?array
    {
        $cells = $row->getElementsByTagName('td');
        if ($cells->length < 2) {
            return null;
        }

        $titleText = trim(preg_replace('/\s+/', ' ', $cells->item(0)->textContent ?? ''));
        $dateCell = $cells->item(1);
        $durationCell = $cells->item(2);

        $meetingDate = $this->extractDate($dateCell?->textContent ?? '', $dateCell);
        $durationSeconds = $this->parseDuration($durationCell?->textContent ?? '');
        $committeeData = $this->extractCommitteeFromTitle($titleText);
        $committeeName = $committeeData['subcommittee'] ?? $committeeData['committee'] ?? null;
        $eventType = $committeeData['subcommittee'] ? 'subcommittee' : ($committeeName ? 'committee' : 'floor');
        $mp4FromRow = $this->extractMp4LinkFromRow($row);

        $published = $meetingDate ? new DateTimeImmutable($meetingDate) : new DateTimeImmutable('today');

        return [
            'source' => 'senate',
            'chamber' => 'senate',
            'video_id' => $clipId,
            'clip_id' => $clipId,
            'title' => $titleText,
            'meeting_date' => $meetingDate,
            'committee' => $committeeData['committee'],
            'subcommittee' => $committeeData['subcommittee'],
            'committee_name' => $committeeName,
            'event_type' => $eventType,
            'description' => null,
            'published_at' => $published->format(DateTimeInterface::ATOM),
            'updated_at' => $published->format(DateTimeInterface::ATOM),
            'video_url' => $mp4FromRow,
            'embed_url' => sprintf(self::DETAIL_URL_TEMPLATE, $clipId),
            'thumbnail_url' => null,
            'captions_url' => sprintf('https://virginia-senate.granicus.com/videos/%s/captions.vtt', $clipId),
            'duration_seconds' => $durationSeconds,
            'scraped_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        ];
    }

    private function parseDetail(string $html): array
    {
        $dom = $this->createDom($html);
        $xpath = new DOMXPath($dom);
        $thumbnail = $this->extractMeta($xpath, 'og:image');
        $description = $this->extractMeta($xpath, 'description');

        return [
            'video_url' => $this->extractDownloadLink($html),
            'thumbnail_url' => $thumbnail,
            'description' => $description,
        ];
    }

    private function extractDownloadLink(string $html): ?string
    {
        if (preg_match('/downloadLinks\s*=\s*(\[[^\;]+\])/i', $html, $matches)) {
            $json = $matches[1];
            $decoded = json_decode($json, true);
            if (is_array($decoded) && isset($decoded[0][0])) {
                return str_replace('\/', '/', $decoded[0][0]);
            }
        }

        if (preg_match('/"VideoUrl"\s*:\s*"([^"]+)"/i', $html, $matches)) {
            return str_replace('\/', '/', $matches[1]);
        }

        return null;
    }

    private function extractMeta(DOMXPath $xpath, string $property): ?string
    {
        $meta = $xpath->query(sprintf('//meta[@property="%s" or @name="%s"]/@content', $property, $property));
        if ($meta && $meta->length > 0) {
            return trim($meta->item(0)->textContent);
        }
        return null;
    }

    private function extractClipId(DOMElement $row): ?string
    {
        $links = $row->getElementsByTagName('a');
        foreach ($links as $link) {
            foreach (['onclick', 'href'] as $attr) {
                if ($link->hasAttribute($attr)) {
                    $value = $link->getAttribute($attr);
                    if (preg_match('/clip_id=(\d+)/', $value, $matches)) {
                        return $matches[1];
                    }
                }
            }
        }

        return null;
    }

    private function extractMp4LinkFromRow(DOMElement $row): ?string
    {
        $links = $row->getElementsByTagName('a');
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if ($href && str_contains($href, '.mp4')) {
                return $href;
            }
        }
        return null;
    }

    private function extractDate(string $cellText, ?DOMElement $cell): ?string
    {
        if ($cell) {
            $spans = $cell->getElementsByTagName('span');
            if ($spans->length > 0) {
                $timestamp = trim($spans->item(0)->textContent ?? '');
                if (is_numeric($timestamp)) {
                    $timezone = new \DateTimeZone(date_default_timezone_get());
                    return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('Y-m-d');
                }
            }
        }

        if (preg_match('/([A-Za-z]+\s+\d{1,2},\s+\d{4})/', $cellText, $matches)) {
            $dt = DateTimeImmutable::createFromFormat('F j, Y', $matches[1]);
            if ($dt) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    private function parseDuration(string $durationText): ?int
    {
        $text = str_replace('&nbsp;', ' ', $durationText);
        if (preg_match('/(?:(\d+)h)?\s*(?:(\d+)m)?\s*(?:(\d+)s)?/i', $text, $matches)) {
            $hours = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
            $minutes = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
            $seconds = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 0;
            $total = ($hours * 3600) + ($minutes * 60) + $seconds;
            return $total > 0 ? $total : null;
        }

        return null;
    }

    private function extractCommitteeFromTitle(string $title): array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($title));
        $segments = array_filter(array_map('trim', explode(' - ', $normalized)), static fn ($segment) => $segment !== '');

        $clean = [];
        foreach ($segments as $index => $segment) {
            if ($index === 0 && preg_match('/^[A-Za-z]+\s+\d{1,2},\s+\d{4}$/', $segment)) {
                continue;
            }
            if (preg_match('/^\d{1,2}:\d{2}\s*(am|pm)$/i', $segment)) {
                continue;
            }
            if (preg_match('/^SR\b/i', $segment)) {
                continue;
            }
            $clean[] = $segment;
        }

        $committee = $clean[0] ?? null;
        $subcommittee = $clean[1] ?? null;

        return ['committee' => $committee ?: null, 'subcommittee' => $subcommittee ?: null];
    }

    private function createDom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        return $dom;
    }
}
