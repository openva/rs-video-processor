<?php

namespace RichmondSunlight\VideoProcessor\Queue;

final class JobType
{
    public const SCREENSHOTS = 'screenshots';
    public const TRANSCRIPT = 'transcript';
    public const BILL_DETECTION = 'bill_detection';
    public const SPEAKER_DETECTION = 'speaker_detection';
    public const VIDEO_DOWNLOAD = 'video_download';
    public const ARCHIVE_UPLOAD = 'archive_upload';
}
