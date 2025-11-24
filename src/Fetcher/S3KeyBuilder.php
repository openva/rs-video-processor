<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

class S3KeyBuilder
{
    public function build(string $chamber, string $date, ?string $committeeShortname = null): string
    {
        $chamber = strtolower($chamber);
        $dateKey = str_replace('-', '', $date);
        $segment = 'floor';
        if ($committeeShortname) {
            $segment = 'committee/' . strtolower($committeeShortname);
        }

        return sprintf('%s/%s/%s.mp4', $chamber, $segment, $dateKey);
    }
}
