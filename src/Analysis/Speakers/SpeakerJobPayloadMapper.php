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
            'event_type' => $job->eventType,
            'capture_directory' => $job->captureDirectory,
            'manifest_url' => $job->manifestUrl,
        ]);
    }

    public function fromPayload(JobPayload $payload): SpeakerJob
    {
        $context = $payload->context;

        return new SpeakerJob(
            $payload->fileId,
            (string) ($context['chamber'] ?? ''),
            (string) ($context['video_url'] ?? ''),
            is_array($context['metadata'] ?? null) ? $context['metadata'] : null,
            isset($context['event_type']) ? (string) $context['event_type'] : null,
            isset($context['capture_directory']) ? (string) $context['capture_directory'] : null,
            isset($context['manifest_url']) ? (string) $context['manifest_url'] : null
        );
    }
}
