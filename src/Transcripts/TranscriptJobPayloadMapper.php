<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use RichmondSunlight\VideoProcessor\Queue\JobPayload;
use RichmondSunlight\VideoProcessor\Queue\JobType;

class TranscriptJobPayloadMapper
{
    public function toPayload(TranscriptJob $job): JobPayload
    {
        // Exclude webvtt/srt to keep payload under SQS 4KB limit
        // Worker will re-fetch from database using fileId
        return new JobPayload(JobType::TRANSCRIPT, $job->fileId, [
            'chamber' => $job->chamber,
            'video_url' => $job->videoUrl,
            'title' => $job->title,
        ]);
    }

    public function fromPayload(JobPayload $payload): TranscriptJob
    {
        $context = $payload->context;

        // webvtt/srt not included in payload - caller must fetch from DB
        return new TranscriptJob(
            $payload->fileId,
            (string) ($context['chamber'] ?? ''),
            (string) ($context['video_url'] ?? ''),
            null, // webvtt - fetch from DB
            null, // srt - fetch from DB
            $context['title'] ?? null
        );
    }
}
