<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use RichmondSunlight\VideoProcessor\Queue\JobPayload;
use RichmondSunlight\VideoProcessor\Queue\JobType;

class TranscriptJobPayloadMapper
{
    public function toPayload(TranscriptJob $job): JobPayload
    {
        return new JobPayload(JobType::TRANSCRIPT, $job->fileId, [
            'chamber' => $job->chamber,
            'video_url' => $job->videoUrl,
            'webvtt' => $job->webvtt,
            'srt' => $job->srt,
            'title' => $job->title,
        ]);
    }

    public function fromPayload(JobPayload $payload): TranscriptJob
    {
        $context = $payload->context;

        return new TranscriptJob(
            $payload->fileId,
            (string) ($context['chamber'] ?? ''),
            (string) ($context['video_url'] ?? ''),
            $context['webvtt'] ?? null,
            $context['srt'] ?? null,
            $context['title'] ?? null
        );
    }
}
