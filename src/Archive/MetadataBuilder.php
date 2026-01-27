<?php

namespace RichmondSunlight\VideoProcessor\Archive;

class MetadataBuilder
{
    public function build(ArchiveJob $job): array
    {
        $metadata = [
            'title' => $this->buildTitle($job),
            'mediatype' => 'movies',
            'collection' => 'virginiageneralassembly',
            'subject' => $this->buildSubjects($job),
            'description' => $this->buildDescription($job),
            'date' => $job->date,
        ];
        return $metadata;
    }

    private function buildTitle(ArchiveJob $job): string
    {
        $parts = ['Virginia General Assembly'];

        // Add chamber
        $parts[] = ucfirst($job->chamber);

        // Add committee name if present
        if ($job->committeeName) {
            $parts[] = $job->committeeName;
        }

        // Add meeting type
        $parts[] = $job->committeeName ? 'Meeting' : 'Session';

        // Format date
        $dateObj = \DateTime::createFromFormat('Y-m-d', $job->date);
        $formattedDate = $dateObj ? $dateObj->format('F j, Y') : $job->date;

        return implode(' ', $parts) . ', ' . $formattedDate;
    }

    private function buildDescription(ArchiveJob $job): string
    {
        $chamber = ucfirst($job->chamber);
        if ($job->committeeName) {
            return sprintf('Virginia General Assembly %s %s meeting from %s', $chamber, $job->committeeName, $job->date);
        }
        return sprintf('Virginia General Assembly %s session from %s', $chamber, $job->date);
    }

    private function buildSubjects(ArchiveJob $job): string
    {
        $subjects = [
            'Virginia General Assembly',
            ucfirst($job->chamber) . ' chamber',
        ];
        if ($job->committeeName) {
            $subjects[] = $job->committeeName;
        }
        return implode('; ', $subjects);
    }
}
