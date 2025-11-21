<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use RichmondSunlight\VideoProcessor\Queue\JobPayload;
use RichmondSunlight\VideoProcessor\Queue\JobType;

class ArchiveJobPayloadMapper
{
    public function toPayload(ArchiveJob $job): JobPayload
    {
        return new JobPayload(JobType::ARCHIVE_UPLOAD, $job->fileId, [
            'chamber' => $job->chamber,
            'title' => $job->title,
            'date' => $job->date,
            's3_path' => $job->s3Path,
            'webvtt' => $job->webvtt,
            'srt' => $job->srt,
            'capture_directory' => $job->captureDirectory,
            'video_index_cache' => $job->videoIndexCache,
        ]);
    }

    public function fromPayload(JobPayload $payload): ArchiveJob
    {
        $context = $payload->context;

        return new ArchiveJob(
            $payload->fileId,
            (string) ($context['chamber'] ?? ''),
            (string) ($context['title'] ?? ''),
            (string) ($context['date'] ?? ''),
            (string) ($context['s3_path'] ?? ''),
            $context['webvtt'] ?? null,
            $context['srt'] ?? null,
            $context['capture_directory'] ?? null,
            $context['video_index_cache'] ?? null
        );
    }
}
