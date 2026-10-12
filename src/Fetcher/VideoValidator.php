<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use RuntimeException;

/**
 * Decides whether a downloaded file is genuinely a video.
 *
 * Three things have been stored as videos here: IIS "502 Bad Gateway" pages
 * saved as .mp4, caption and manifest files fetched from a wrong key, and
 * truncated uploads with no moov atom.
 */
class VideoValidator
{
    /** A real session video is never smaller than this; anything less is an error page. */
    public const MIN_VIDEO_BYTES = 3 * 1024 * 1024;

    /**
     * @throws RuntimeException with a message containing "not a valid video"
     */
    public function assertValid(string $path): void
    {
        $size = @filesize($path);
        if ($size === false || $size < self::MIN_VIDEO_BYTES) {
            throw new RuntimeException(sprintf(
                'Downloaded file is not a valid video: %s bytes is below the %d-byte minimum '
                . '(source is likely an error page rather than a video).',
                $size === false ? '0' : (string) $size,
                self::MIN_VIDEO_BYTES
            ));
        }

        $cmd = sprintf(
            'ffprobe -v error -show_entries stream=codec_type -of csv=p=0 %s 2>&1',
            escapeshellarg($path)
        );
        exec($cmd, $output, $status);
        $detail = trim(implode(' ', $output));

        if ($status !== 0) {
            throw new RuntimeException(sprintf(
                'Downloaded file is not a valid video (ffprobe exit %d): %s',
                $status,
                $detail ?: 'no output'
            ));
        }

        // ffprobe exits 0 on files it can open but that hold no video stream,
        // such as a caption file. Require an actual video stream.
        if (!in_array('video', array_map('trim', $output), true)) {
            throw new RuntimeException(sprintf(
                'Downloaded file is not a valid video: no video stream found (ffprobe reported: %s).',
                $detail !== '' ? $detail : 'no streams'
            ));
        }
    }
}
