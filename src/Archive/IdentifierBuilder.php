<?php

namespace RichmondSunlight\VideoProcessor\Archive;

class IdentifierBuilder
{
    /**
     * The Internet Archive identifier for a new upload: va-{chamber}-{YYYYMMDD}-{file ID},
     * e.g. va-senate-20260227-18867.
     *
     * The file ID keeps it unique. Identifiers once ended in the title instead
     * (rs-house-20260227-house-session), so two recordings with the same
     * chamber, date and title got the same one, and the second upload was added
     * to the first video's item. Items uploaded that way keep their identifiers.
     */
    public function build(ArchiveJob $job): string
    {
        return sprintf('va-%s-%s-%d', strtolower($job->chamber), str_replace('-', '', $job->date), $job->fileId);
    }
}
