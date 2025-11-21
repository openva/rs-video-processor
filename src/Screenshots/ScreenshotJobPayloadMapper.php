<?php

namespace RichmondSunlight\VideoProcessor\Screenshots;

use RichmondSunlight\VideoProcessor\Queue\JobPayload;
use RichmondSunlight\VideoProcessor\Queue\JobType;

class ScreenshotJobPayloadMapper
{
    public function toPayload(ScreenshotJob $job): JobPayload
    {
        return new JobPayload(JobType::SCREENSHOTS, $job->id, [
            'chamber' => $job->chamber,
            'committee_id' => $job->committeeId,
            'date' => $job->date,
            'video_path' => $job->videoPath,
            'capture_directory' => $job->captureDirectory,
            'title' => $job->title,
        ]);
    }

    public function fromPayload(JobPayload $payload): ScreenshotJob
    {
        $context = $payload->context;

        return new ScreenshotJob(
            $payload->fileId,
            (string) ($context['chamber'] ?? ''),
            isset($context['committee_id']) ? (int) $context['committee_id'] : null,
            (string) ($context['date'] ?? ''),
            (string) ($context['video_path'] ?? ''),
            $context['capture_directory'] ?? null,
            $context['title'] ?? null
        );
    }
}
