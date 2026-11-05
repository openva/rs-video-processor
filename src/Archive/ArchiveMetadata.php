<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use RichmondSunlight\VideoProcessor\Jobs\NotReadyYet;

/**
 * Asks the Internet Archive's metadata API about an item.
 *
 * When the archive is down ("Internet Archive: Temporarily Offline", a 503),
 * every video would get the same answer. So that is not this video's failure:
 * it throws NotReadyYet with everyJobWaits set, and StageRunner sets the job
 * aside without using an attempt and stops the run.
 */
final class ArchiveMetadata
{
    /** The soonest the archive is asked again after it was down. */
    public const OFFLINE_RECHECK_SECONDS = 1800;

    /**
     * @return array<string,mixed>|null the item ([] when there is no such item),
     *         or null when the archive answered with a client error (4xx)
     * @throws NotReadyYet when the archive is down or overloaded: a 5xx or 429
     *         reply, no reply at all, or a reply that is not JSON
     */
    public static function fetch(ClientInterface $http, string $identifier): ?array
    {
        $url = 'https://archive.org/metadata/' . rawurlencode($identifier);
        try {
            $response = $http->request('GET', $url, ['http_errors' => false]);
        } catch (GuzzleException $e) {
            throw self::unavailable($identifier, 'no reply (' . $e->getMessage() . ')');
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status >= 500 || $status === 429) {
            throw self::unavailable($identifier, 'HTTP ' . $status . self::title($body));
        }
        if ($status >= 400) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            // The archive's maintenance page has been served in place of the JSON.
            throw self::unavailable($identifier, 'a reply that is not JSON' . self::title($body));
        }

        return $data;
    }

    public static function unavailable(string $identifier, string $why): NotReadyYet
    {
        return new NotReadyYet(
            sprintf('The Internet Archive is unavailable: asking about %s got %s.', $identifier, $why),
            self::OFFLINE_RECHECK_SECONDS,
            true
        );
    }

    /** The HTML page's title, such as ": Internet Archive: Temporarily Offline", or "". */
    private static function title(string $body): string
    {
        if (preg_match('#<title>\s*(.*?)\s*</title>#is', $body, $m) !== 1 || $m[1] === '') {
            return '';
        }

        return ': ' . mb_substr(html_entity_decode($m[1]), 0, 100);
    }
}
