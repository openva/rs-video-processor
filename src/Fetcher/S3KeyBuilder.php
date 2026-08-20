<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

class S3KeyBuilder
{
    public function build(string $chamber, string $date, ?string $committeeShortname = null): string
    {
        $chamber = strtolower($chamber);
        $dateKey = str_replace('-', '', $date);
        $segment = 'floor';

        if ($committeeShortname !== null) {
            $slug = self::slugify($committeeShortname);
            if ($slug !== '') {
                $segment = 'committee/' . $slug;
            }
        }

        return sprintf('%s/%s/%s.mp4', $chamber, $segment, $dateKey);
    }

    /**
     * Reduce a committee shortname to a safe key segment: [a-z0-9-] only.
     *
     * Committee names once reached the key builder unsanitised and were run
     * through urlencode() upstream, producing keys that contain literal '+'
     * and '%' -- e.g. house/committee/militia%2C+police+and+public+safety/.
     * Those 404 over HTTPS unless re-escaped, and 17 such prefixes had to be
     * migrated by hand. Slugifying here means a dirty shortname can no longer
     * create an unreachable key.
     *
     * Returns '' when nothing usable survives, so the caller can fall back to
     * the floor path rather than emit 'committee//<date>.mp4' -- an empty
     * segment that collided across committees in production.
     */
    private static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        // Anything that is not a-z, 0-9 or '-' becomes a separator. This folds
        // spaces, commas, parens, ampersands, slashes and stray percent-escapes
        // into hyphens rather than letting them reach the key.
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }
}
