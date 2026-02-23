<?php

namespace RichmondSunlight\VideoProcessor\Scraper\Senate;

use DateTimeImmutable;
use DateTimeInterface;
use Log;
use RichmondSunlight\VideoProcessor\Scraper\Http\HttpClientInterface;
use RichmondSunlight\VideoProcessor\Scraper\VideoSourceScraperInterface;
use RichmondSunlight\VideoProcessor\Scraper\YouTube\YouTubeApiClient;

class SenateYouTubeScraper implements VideoSourceScraperInterface
{
    // Virginia Senate YouTube channel ID
    // Found via: https://www.youtube.com/@SenateofVirginia
    private const CHANNEL_ID = 'UC9r1OpPhTY1VmL05bemQD0w';

    private YouTubeApiClient $apiClient;

    public function __construct(
        private HttpClientInterface $client,
        private string $apiKey,
        private ?Log $logger = null,
        private ?int $maxRecords = null
    ) {
        $this->apiClient = new YouTubeApiClient($client, $apiKey, $logger);
    }

    public function scrape(): array
    {
        echo "  Fetching Senate YouTube videos...\n";

        if (empty($this->apiKey)) {
            echo "  WARNING: YouTube API key not configured, skipping YouTube scraper\n";
            $this->logger?->put('YouTube API key not configured, skipping scrape', 2);
            return [];
        }

        // Fetch live videos from the channel
        echo "  Querying YouTube API for channel: " . self::CHANNEL_ID . "\n";
        $videos = $this->apiClient->fetchLiveVideos(self::CHANNEL_ID, $this->maxRecords ?? 50);

        if (empty($videos)) {
            echo "  No YouTube videos found\n";
            $this->logger?->put('YouTube scraper found 0 videos - check API response', 2);
            return [];
        }

        // Extract video IDs
        $videoIds = array_map(fn($video) => $video['id']['videoId'] ?? null, $videos);
        $videoIds = array_filter($videoIds);

        if (empty($videoIds)) {
            echo "  No valid video IDs found\n";
            return [];
        }

        echo "  Found " . count($videoIds) . " YouTube videos\n";

        // Fetch detailed information for all videos
        $details = $this->apiClient->fetchVideoDetails($videoIds);

        $results = [];
        $processed = 0;

        foreach ($videoIds as $videoId) {
            if (!isset($details[$videoId])) {
                continue;
            }

            $detail = $details[$videoId];
            $snippet = $detail['snippet'] ?? [];
            $contentDetails = $detail['contentDetails'] ?? [];

            $title = $snippet['title'] ?? 'Untitled';
            $processed++;
            echo "  [{$processed}/" . count($videoIds) . "] Processing: {$title}\n";

            $record = $this->mapVideoToRecord($videoId, $snippet, $contentDetails);
            if ($record) {
                $results[] = $record;
            }

            if ($this->maxRecords !== null && count($results) >= $this->maxRecords) {
                break;
            }
        }

        $quotaUsed = $this->apiClient->getQuotaUsed();
        $this->logger?->put(
            sprintf('Senate YouTube scraper gathered %d videos (API quota: %d units)', count($results), $quotaUsed),
            3
        );

        echo "  YouTube scrape complete: " . count($results) . " videos (API quota: {$quotaUsed} units)\n";

        return $results;
    }

    private function mapVideoToRecord(string $videoId, array $snippet, array $contentDetails): ?array
    {
        $title = $snippet['title'] ?? '';
        $description = $snippet['description'] ?? '';
        $publishedAt = $snippet['publishedAt'] ?? null;

        // Parse duration
        $isoDuration = $contentDetails['duration'] ?? null;
        $durationSeconds = $isoDuration ? $this->apiClient->parseIsoDuration($isoDuration) : null;

        // Extract committee information from title
        $committeeData = $this->extractCommitteeFromTitle($title);
        $committeeName = $committeeData['subcommittee'] ?? $committeeData['committee'] ?? null;

        // Determine event type
        if ($committeeData['subcommittee']) {
            $eventType = 'subcommittee';
        } elseif ($committeeName && preg_match('/\b(Senate|Floor|Veto|Joint|Reconvened)\s+Session\b/i', $committeeName)) {
            $eventType = 'floor';
            $committeeName = null;
        } elseif ($committeeName && preg_match('/\bfloor\b/i', $committeeName)) {
            $eventType = 'floor';
            $committeeName = null;
        } elseif ($committeeName) {
            $eventType = 'committee';
        } else {
            $eventType = 'floor';
        }

        // Extract date - try from title first, fall back to publishedAt
        $meetingDate = $this->extractDateFromTitle($title);
        if (!$meetingDate && $publishedAt) {
            try {
                $dt = new DateTimeImmutable($publishedAt);
                $meetingDate = $dt->format('Y-m-d');
            } catch (\Exception $e) {
                $meetingDate = null;
            }
        }

        // Get thumbnail URL (prefer high quality)
        $thumbnailUrl = $snippet['thumbnails']['high']['url']
            ?? $snippet['thumbnails']['medium']['url']
            ?? $snippet['thumbnails']['default']['url']
            ?? null;

        $published = $publishedAt ? new DateTimeImmutable($publishedAt) : new DateTimeImmutable('now');

        return [
            'source' => 'senate-youtube',
            'chamber' => 'senate',
            'video_id' => $videoId,
            'youtube_id' => $videoId,
            'title' => $title,
            'description' => $description,
            'meeting_date' => $meetingDate,
            'committee' => $committeeData['committee'],
            'subcommittee' => $committeeData['subcommittee'],
            'committee_name' => $committeeName,
            'event_type' => $eventType,
            'published_at' => $published->format(DateTimeInterface::ATOM),
            'updated_at' => $published->format(DateTimeInterface::ATOM),
            'video_url' => 'https://www.youtube.com/watch?v=' . $videoId,
            'youtube_url' => 'https://www.youtube.com/watch?v=' . $videoId,
            'embed_url' => 'https://www.youtube.com/embed/' . $videoId,
            'thumbnail_url' => $thumbnailUrl,
            'duration_seconds' => $durationSeconds,
            'scraped_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Extract committee and subcommittee names from title.
     * Reuses logic from SenateScraper.
     */
    private function extractCommitteeFromTitle(string $title): array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($title));
        $segments = array_filter(
            array_map('trim', explode(' - ', $normalized)),
            static fn($segment) => $segment !== ''
        );

        $clean = [];
        foreach ($segments as $index => $segment) {
            // Skip date patterns (e.g., "January 15, 2026", "1/15/26")
            if (preg_match('/^[A-Za-z]+\s+\d{1,2},\s+\d{4}$/', $segment)) {
                continue;
            }
            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $segment)) {
                continue;
            }
            // Skip time patterns
            if (preg_match('/^\d{1,2}:\d{2}\s*(am|pm)$/i', $segment)) {
                continue;
            }
            // Skip room numbers
            if (preg_match('/^SR\b/i', $segment)) {
                continue;
            }
            // Skip generic words that aren't committee names
            if (preg_match('/^(Meeting|Hearing|Session|Budget Hearing)$/i', $segment)) {
                continue;
            }
            $clean[] = $segment;
        }

        $committee = $clean[0] ?? null;
        $subcommittee = $clean[1] ?? null;

        return [
            'committee' => $committee ?: null,
            'subcommittee' => $subcommittee ?: null
        ];
    }

    /**
     * Extract date from video title.
     */
    private function extractDateFromTitle(string $title): ?string
    {
        // Try various date patterns
        $patterns = [
            '/([A-Za-z]+\s+\d{1,2},\s+\d{4})/',  // "January 15, 2026"
            '/(\d{1,2}\/\d{1,2}\/\d{2,4})/',     // "1/15/26" or "1/15/2026"
            '/(\d{4}-\d{2}-\d{2})/',             // "2026-01-15"
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                try {
                    $dateStr = $matches[1];
                    // Try to parse the date
                    if (str_contains($dateStr, '/')) {
                        $parts = explode('/', $dateStr);
                        if (count($parts) === 3) {
                            $year = strlen($parts[2]) === 2 ? '20' . $parts[2] : $parts[2];
                            $dt = DateTimeImmutable::createFromFormat('m/d/Y', $parts[0] . '/' . $parts[1] . '/' . $year);
                            if ($dt) {
                                return $dt->format('Y-m-d');
                            }
                        }
                    } elseif (str_contains($dateStr, '-')) {
                        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
                        if ($dt) {
                            return $dt->format('Y-m-d');
                        }
                    } else {
                        // Month name format
                        $dt = DateTimeImmutable::createFromFormat('F j, Y', $dateStr);
                        if (!$dt) {
                            $dt = DateTimeImmutable::createFromFormat('M j, Y', $dateStr);
                        }
                        if ($dt) {
                            return $dt->format('Y-m-d');
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return null;
    }
}
