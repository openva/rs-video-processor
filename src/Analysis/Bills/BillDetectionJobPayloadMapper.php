<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use RichmondSunlight\VideoProcessor\Queue\JobPayload;
use RichmondSunlight\VideoProcessor\Queue\JobType;

class BillDetectionJobPayloadMapper
{
    public function toPayload(BillDetectionJob $job): JobPayload
    {
        return new JobPayload(JobType::BILL_DETECTION, $job->fileId, [
            'chamber' => $job->chamber,
            'committee_id' => $job->committeeId,
            'event_type' => $job->eventType,
            'capture_directory' => $job->captureDirectory,
            'manifest_url' => $job->manifestUrl,
            'metadata' => $job->metadata,
        ]);
    }

    public function fromPayload(JobPayload $payload): BillDetectionJob
    {
        $context = $payload->context;

        return new BillDetectionJob(
            $payload->fileId,
            (string) ($context['chamber'] ?? ''),
            isset($context['committee_id']) ? (int) $context['committee_id'] : null,
            (string) ($context['event_type'] ?? ''),
            (string) ($context['capture_directory'] ?? ''),
            $context['manifest_url'] ?? null,
            is_array($context['metadata'] ?? null) ? $context['metadata'] : null
        );
    }
}
