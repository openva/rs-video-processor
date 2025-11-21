<?php

namespace RichmondSunlight\VideoProcessor\Archive;

class IdentifierBuilder
{
    public function build(ArchiveJob $job): string
    {
        $date = str_replace('-', '', $job->date);
        $titleSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($job->title));
        $titleSlug = trim($titleSlug, '-');
        return sprintf('rs-%s-%s-%s', strtolower($job->chamber), $date, $titleSlug);
    }
}
