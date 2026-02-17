<?php

namespace RichmondSunlight\VideoProcessor\Scraper\YouTube;

use Log;
use RichmondSunlight\VideoProcessor\Scraper\Http\HttpClientInterface;

class YouTubeApiClient
{
    private const API_BASE_URL = 'https://www.googleapis.com/youtube/v3';

    private int $quotaUsed = 0;

    public function __construct(
        private HttpClientInterface $client,
        private string $apiKey,
        private ?Log $logger = null
    ) {
    }

    /**
     * Fetch recent videos from a channel.
     *
     * @param string $channelId YouTube channel ID
     * @param int $maxResults Maximum number of results to fetch (1-50)
     * @param bool $liveOnly Only fetch completed live streams (default: false, fetch all videos)
     * @return array<int, array<string, mixed>>
     */
    public function fetchLiveVideos(string $channelId, int $maxResults = 50, bool $liveOnly = false): array
    {
        $eventTypeParam = $liveOnly ? '&eventType=completed' : '';
        $url = sprintf(
            '%s/search?part=id,snippet&channelId=%s&type=video&order=date%s&maxResults=%d&key=%s',
            self::API_BASE_URL,
            urlencode($channelId),
            $eventTypeParam,
            min($maxResults, 50),
            urlencode($this->apiKey)
        );

        try {
            $response = $this->client->get($url);
            $this->recordQuota(100); // search.list with 2 parts costs 100 units

            $data = json_decode($response, true);
            if (!is_array($data)) {
                $this->logger?->put('YouTube API returned non-array response: ' . substr($response, 0, 200), 1);
                return [];
            }

            if (isset($data['error'])) {
                $errorMsg = $data['error']['message'] ?? 'Unknown error';
                $this->logger?->put('YouTube API error: ' . $errorMsg, 1);
                return [];
            }

            if (!isset($data['items'])) {
                $this->logger?->put('YouTube API response missing items field. Keys: ' . implode(', ', array_keys($data)), 1);
                return [];
            }

            $itemCount = count($data['items']);
            $this->logger?->put("YouTube API returned {$itemCount} videos", 4);

            return $data['items'];
        } catch (\Exception $e) {
            $this->handleApiError($e, 'search.list');
            return [];
        }
    }

    /**
     * Fetch detailed information for specific video IDs.
     *
     * @param array<int, string> $videoIds Array of YouTube video IDs
     * @return array<string, array<string, mixed>> Video details keyed by video ID
     */
    public function fetchVideoDetails(array $videoIds): array
    {
        if (empty($videoIds)) {
            return [];
        }

        $url = sprintf(
            '%s/videos?part=snippet,contentDetails&id=%s&key=%s',
            self::API_BASE_URL,
            urlencode(implode(',', $videoIds)),
            urlencode($this->apiKey)
        );

        try {
            $response = $this->client->get($url);
            $this->recordQuota(count($videoIds)); // videos.list with 2 parts costs 1 unit per video

            $data = json_decode($response, true);
            if (!is_array($data) || !isset($data['items'])) {
                $this->logger?->put('YouTube API returned invalid response format for video details', 1);
                return [];
            }

            $result = [];
            foreach ($data['items'] as $item) {
                if (isset($item['id'])) {
                    $result[$item['id']] = $item;
                }
            }

            return $result;
        } catch (\Exception $e) {
            $this->handleApiError($e, 'videos.list');
            return [];
        }
    }

    /**
     * Parse ISO 8601 duration format (PT1H23M45S) to seconds.
     *
     * @param string $duration ISO 8601 duration string
     * @return int|null Duration in seconds, or null if parsing fails
     */
    public function parseIsoDuration(string $duration): ?int
    {
        if (!preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $duration, $matches)) {
            return null;
        }

        $hours = !empty($matches[1]) ? (int)$matches[1] : 0;
        $minutes = !empty($matches[2]) ? (int)$matches[2] : 0;
        $seconds = !empty($matches[3]) ? (int)$matches[3] : 0;

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    /**
     * Get total quota units used in this session.
     */
    public function getQuotaUsed(): int
    {
        return $this->quotaUsed;
    }

    private function recordQuota(int $cost): void
    {
        $this->quotaUsed += $cost;
        $this->logger?->put(
            sprintf('YouTube API quota: %d units (total: %d)', $cost, $this->quotaUsed),
            2
        );
    }

    private function handleApiError(\Exception $e, string $endpoint): void
    {
        $message = $e->getMessage();

        // Check for quota exceeded (403 or specific error message)
        if (str_contains($message, '403') || str_contains($message, 'quotaExceeded')) {
            $this->logger?->put(
                sprintf('YouTube API quota exceeded for %s: %s', $endpoint, $message),
                1
            );
        } elseif (str_contains($message, '404')) {
            $this->logger?->put(
                sprintf('YouTube API resource not found for %s: %s', $endpoint, $message),
                2
            );
        } else {
            $this->logger?->put(
                sprintf('YouTube API error for %s: %s', $endpoint, $message),
                2
            );
        }
    }
}
