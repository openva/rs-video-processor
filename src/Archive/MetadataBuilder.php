<?php

namespace RichmondSunlight\VideoProcessor\Archive;

class MetadataBuilder
{
    public function build(ArchiveJob $job): array
    {
        $metadata = [
            'title' => $job->title,
            'mediatype' => 'movies',
            'collection' => 'opensource_movies',
            'subject' => $this->buildSubjects($job),
            'description' => sprintf('%s video from %s', ucfirst($job->chamber), $job->date),
            'date' => $job->date,
        ];
        return $metadata;
    }

    private function buildSubjects(ArchiveJob $job): string
    {
        $subjects = [
            'Virginia General Assembly',
            ucfirst($job->chamber) . ' chamber',
        ];
        return implode('; ', $subjects);
    }
}
