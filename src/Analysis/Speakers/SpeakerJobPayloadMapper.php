<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use RichmondSunlight\VideoProcessor\Queue\JobPayload;
use RichmondSunlight\VideoProcessor\Queue\JobType;

class SpeakerJobPayloadMapper
{
    public function toPayload(SpeakerJob $job): JobPayload
    {
        return new JobPayload(JobType::SPEAKER_DETECTION, $job->fileId, [
            'chamber' => $job->chamber,
            'video_url' => $job->videoUrl,
            'metadata' => $job->metadata,
        ]);
    }

    public function fromPayload(JobPayload $payload): SpeakerJob
    {
        $context = $payload->context;

        return new SpeakerJob(
            $payload->fileId,
            (string) ($context['chamber'] ?? ''),
            (string) ($context['video_url'] ?? ''),
            is_array($context['metadata'] ?? null) ? $context['metadata'] : null
        );
    }
}
