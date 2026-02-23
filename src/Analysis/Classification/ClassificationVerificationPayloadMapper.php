<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Classification;

use RichmondSunlight\VideoProcessor\Queue\JobPayload;
use RichmondSunlight\VideoProcessor\Queue\JobType;

class ClassificationVerificationPayloadMapper
{
    public function toPayload(ClassificationVerificationJob $job): JobPayload
    {
        return new JobPayload(JobType::CLASSIFICATION_VERIFICATION, $job->fileId, [
            'chamber' => $job->chamber,
            'current_event_type' => $job->currentEventType,
            'current_committee_id' => $job->currentCommitteeId,
            'capture_directory' => $job->captureDirectory,
            'manifest_url' => $job->manifestUrl,
            'title' => $job->title,
            'date' => $job->date,
        ]);
    }

    public function fromPayload(JobPayload $payload): ClassificationVerificationJob
    {
        $context = $payload->context;

        return new ClassificationVerificationJob(
            $payload->fileId,
            (string) ($context['chamber'] ?? ''),
            (string) ($context['current_event_type'] ?? 'floor'),
            isset($context['current_committee_id']) ? (int) $context['current_committee_id'] : null,
            (string) ($context['capture_directory'] ?? ''),
            $context['manifest_url'] ?? null,
            null,
            $context['title'] ?? null,
            (string) ($context['date'] ?? '2020-01-01')
        );
    }
}
