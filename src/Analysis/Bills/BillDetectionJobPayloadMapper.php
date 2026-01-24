<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use RichmondSunlight\VideoProcessor\Queue\JobPayload;
use RichmondSunlight\VideoProcessor\Queue\JobType;

class BillDetectionJobPayloadMapper
{
    public function toPayload(BillDetectionJob $job): JobPayload
    {
        // Only include AgendaTree from metadata to keep payload small for SQS
        $agendaTree = null;
        if (is_array($job->metadata) && !empty($job->metadata['AgendaTree'])) {
            $agendaTree = $job->metadata['AgendaTree'];
        }

        return new JobPayload(JobType::BILL_DETECTION, $job->fileId, [
            'chamber' => $job->chamber,
            'committee_id' => $job->committeeId,
            'event_type' => $job->eventType,
            'capture_directory' => $job->captureDirectory,
            'manifest_url' => $job->manifestUrl,
            'agenda_tree' => $agendaTree,
        ]);
    }

    public function fromPayload(JobPayload $payload): BillDetectionJob
    {
        $context = $payload->context;

        // Reconstruct metadata with AgendaTree if present
        $metadata = null;
        if (!empty($context['agenda_tree'])) {
            $metadata = ['AgendaTree' => $context['agenda_tree']];
        }

        return new BillDetectionJob(
            $payload->fileId,
            (string) ($context['chamber'] ?? ''),
            isset($context['committee_id']) ? (int) $context['committee_id'] : null,
            (string) ($context['event_type'] ?? ''),
            (string) ($context['capture_directory'] ?? ''),
            $context['manifest_url'] ?? null,
            $metadata
        );
    }
}
