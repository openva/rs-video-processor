<?php

namespace RichmondSunlight\VideoProcessor\Scraper\Senate;

use DateTimeImmutable;
use DateTimeInterface;
use Log;
use RichmondSunlight\VideoProcessor\Scraper\Http\HttpClientInterface;
use RichmondSunlight\VideoProcessor\Scraper\VideoSourceScraperInterface;
use SimpleXMLElement;

class SenateScraper implements VideoSourceScraperInterface
{
    private const DEFAULT_FEED = 'https://www.youtube.com/feeds/videos.xml?channel_id=UC9r1OpPhTY1VmL05bemQD0w';

    public function __construct(
        private HttpClientInterface $client,
        private string $feedUrl = self::DEFAULT_FEED,
        private ?Log $logger = null
    ) {
    }

    public function scrape(): array
    {
        $xml = $this->client->get($this->feedUrl);
        $document = new SimpleXMLElement($xml);
        $results = [];

        foreach ($document->entry as $entry) {
            $results[] = $this->mapEntry($entry);
        }

        if ($this->logger) {
            $this->logger->put(sprintf('Senate scraper gathered %d videos', count($results)), 3);
        }

        return $results;
    }

    private function mapEntry(SimpleXMLElement $entry): array
    {
        $yt = $entry->children('http://www.youtube.com/xml/schemas/2015');
        $media = $entry->children('http://search.yahoo.com/mrss/');
        $group = $media->group ?? null;
        $thumbnail = $group?->thumbnail;
        $content = $group?->content;

        $videoId = (string) ($yt->videoId ?? '');
        $published = new DateTimeImmutable((string) $entry->published);
        $updated = new DateTimeImmutable((string) $entry->updated);
        $title = (string) $entry->title;
        $extractedDate = $this->extractDateFromTitle($title);
        $committeeData = $this->extractCommitteeFromTitle($title);
        $committeeName = $committeeData['subcommittee'] ?? $committeeData['committee'] ?? null;
        $eventType = $committeeData['subcommittee'] ? 'subcommittee' : ($committeeName ? 'committee' : 'floor');

        return [
            'source' => 'senate',
            'chamber' => 'senate',
            'video_id' => $videoId,
            'title' => $title,
            'meeting_date' => $extractedDate,
            'committee' => $committeeData['committee'],
            'subcommittee' => $committeeData['subcommittee'],
            'committee_name' => $committeeName,
            'event_type' => $eventType,
            'description' => $group ? (string) $group->description : null,
            'published_at' => $published->format(DateTimeInterface::ATOM),
            'updated_at' => $updated->format(DateTimeInterface::ATOM),
            'video_url' => sprintf('https://www.youtube.com/watch?v=%s', $videoId),
            'embed_url' => $content && isset($content['url']) ? (string) $content['url'] : '',
            'thumbnail_url' => $thumbnail && isset($thumbnail['url']) ? (string) $thumbnail['url'] : '',
            'captions_url' => sprintf('https://www.youtube.com/api/timedtext?v=%s&lang=en', $videoId),
            'duration_seconds' => null,
            'scraped_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        ];
    }

    private function extractDateFromTitle(string $title): ?string
    {
        if (preg_match('/(20\d{2}-\d{2}-\d{2})/', $title, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract committee/subcommittee names from titles like
     * "Senate of Virginia: XYZ Committee - ABC Subcommittee on 2025-01-01 [Finished]".
     */
    private function extractCommitteeFromTitle(string $title): array
    {
        $subject = $title;
        if (($colon = strpos($subject, ':')) !== false) {
            $subject = substr($subject, $colon + 1);
        }
        if (($bracketPos = strpos($subject, '[')) !== false) {
            $subject = substr($subject, 0, $bracketPos);
        }
        if (preg_match('/\bon\s+20\d{2}-\d{2}-\d{2}/', $subject, $match, PREG_OFFSET_CAPTURE)) {
            $subject = substr($subject, 0, $match[0][1]);
        }
        $subject = trim($subject);

        $committee = null;
        $subcommittee = null;

        if ($subject === '') {
            return ['committee' => null, 'subcommittee' => null];
        }

        if (strpos($subject, ' - ') !== false) {
            [$committeePart, $subPart] = array_map('trim', explode(' - ', $subject, 2));
            $committee = $committeePart ?: null;
            $subcommittee = $subPart ?: null;
        } elseif (stripos($subject, 'subcommittee') !== false) {
            $subcommittee = $subject;
        } else {
            $committee = $subject;
        }

        return ['committee' => $committee, 'subcommittee' => $subcommittee];
    }
}
