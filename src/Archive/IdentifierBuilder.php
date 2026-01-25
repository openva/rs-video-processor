<?php

namespace RichmondSunlight\VideoProcessor\Archive;

class IdentifierBuilder
{
    public function build(ArchiveJob $job): string
    {
        $date = str_replace('-', '', $job->date);
        $titleSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($job->title));
        $titleSlug = trim($titleSlug, '-');

        if ($titleSlug === '') {
            return sprintf('rs-%s-%s', strtolower($job->chamber), $date);
        }

        // Internet Archive requires identifiers to be max 80 chars
        // Format: rs-{chamber}-{date}-{title}
        // Reserve space for prefix: "rs-senate-20201207-" = 19 chars (worst case)
        // Leaves 61 chars for title slug
        $prefix = sprintf('rs-%s-%s-', strtolower($job->chamber), $date);
        $maxTitleLength = 80 - strlen($prefix);

        if (strlen($titleSlug) > $maxTitleLength) {
            $titleSlug = substr($titleSlug, 0, $maxTitleLength);
            $titleSlug = rtrim($titleSlug, '-');
        }

        return $prefix . $titleSlug;
    }
}
