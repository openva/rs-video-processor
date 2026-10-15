<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use RichmondSunlight\VideoProcessor\Sync\MeetingClassifier;

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
        if ($this->meetingName($job)) {
            $parts[] = $this->meetingName($job);
        }

        // Add meeting type
        $parts[] = $this->meetingName($job) ? 'Meeting' : 'Session';

        // Format date
        $dateObj = \DateTime::createFromFormat('Y-m-d', $job->date);
        $formattedDate = $dateObj ? $dateObj->format('F j, Y') : $job->date;

        return implode(' ', $parts) . ', ' . $formattedDate;
    }

    private function buildDescription(ArchiveJob $job): string
    {
        $chamber = ucfirst($job->chamber);
        if ($this->meetingName($job)) {
            return sprintf('Virginia General Assembly %s %s meeting from %s', $chamber, $this->meetingName($job), $job->date);
        }
        return sprintf('Virginia General Assembly %s session from %s', $chamber, $job->date);
    }

    private function buildSubjects(ArchiveJob $job): string
    {
        $subjects = [
            'Virginia General Assembly',
            ucfirst($job->chamber) . ' chamber',
        ];
        if ($this->meetingName($job)) {
            $subjects[] = $this->meetingName($job);
        }
        return implode('; ', $subjects);
    }

    /**
     * The committee's name, or for a committee that could not be matched, the
     * meeting named in the video's own title ("House Civil Subcommittee").
     * Null means a floor session.
     */
    private function meetingName(ArchiveJob $job): ?string
    {
        if ($job->committeeName) {
            return $job->committeeName;
        }

        $name = trim((string) preg_replace('/^' . preg_quote(ucfirst($job->chamber), '/') . '\s+/i', '', trim($job->title)));
        if ($name === '' || MeetingClassifier::classify($job->title) === 'floor') {
            return null;
        }

        return $name;
    }
}
