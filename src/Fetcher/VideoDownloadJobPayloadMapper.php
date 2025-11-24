<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use RichmondSunlight\VideoProcessor\Queue\JobPayload;
use RichmondSunlight\VideoProcessor\Queue\JobType;

class VideoDownloadJobPayloadMapper
{
    public function toPayload(VideoDownloadJob $job): JobPayload
    {
        return new JobPayload(JobType::VIDEO_DOWNLOAD, $job->id, [
            'chamber' => $job->chamber,
            'committee_id' => $job->committeeId,
            'date' => $job->date,
            'remote_url' => $job->remoteUrl,
            'metadata' => $job->metadata,
            'title' => $job->title,
        ]);
    }

    public function fromPayload(JobPayload $payload): VideoDownloadJob
    {
        $context = $payload->context;

        return new VideoDownloadJob(
            $payload->fileId,
            (string) ($context['chamber'] ?? ''),
            isset($context['committee_id']) ? (int) $context['committee_id'] : null,
            (string) ($context['date'] ?? ''),
            (string) ($context['remote_url'] ?? ''),
            is_array($context['metadata'] ?? null) ? $context['metadata'] : [],
            $context['title'] ?? null
        );
    }
}
