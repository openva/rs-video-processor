<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Speakers;

use RichmondSunlight\VideoProcessor\Queue\JobPayload;
use RichmondSunlight\VideoProcessor\Queue\JobType;

class SpeakerJobPayloadMapper
{
    public function toPayload(SpeakerJob $job): JobPayload
    {
        // Only include speaker data from metadata to keep payload small for SQS
        $speakers = null;
        if (is_array($job->metadata)) {
            $speakers = $job->metadata['speakers'] ?? $job->metadata['Speakers'] ?? null;
        }

        return new JobPayload(JobType::SPEAKER_DETECTION, $job->fileId, [
            'chamber' => $job->chamber,
            'video_url' => $job->videoUrl,
            'speakers' => $speakers,
            'event_type' => $job->eventType,
            'capture_directory' => $job->captureDirectory,
            'manifest_url' => $job->manifestUrl,
        ]);
    }

    public function fromPayload(JobPayload $payload): SpeakerJob
    {
        $context = $payload->context;

        // Reconstruct metadata with speakers if present
        $metadata = null;
        if (!empty($context['speakers'])) {
            $metadata = ['speakers' => $context['speakers']];
        }

        return new SpeakerJob(
            $payload->fileId,
            (string) ($context['chamber'] ?? ''),
            (string) ($context['video_url'] ?? ''),
            $metadata,
            isset($context['event_type']) ? (string) $context['event_type'] : null,
            isset($context['capture_directory']) ? (string) $context['capture_directory'] : null,
            isset($context['manifest_url']) ? (string) $context['manifest_url'] : null
        );
    }
}
