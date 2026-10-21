<?php

namespace RichmondSunlight\VideoProcessor\Analysis\Bills;

use RuntimeException;

/**
 * A screenshot the manifest lists is not in the bucket (404). Unlike a 500 or
 * a timeout, asking again will not help.
 */
class ScreenshotMissingException extends RuntimeException
{
    /**
     * Give up on a video after this many listed screenshots in a row are
     * missing: its screenshots are gone, or the manifest is another video's,
     * and reading the rest would only repeat the error for every frame.
     */
    public const LIMIT_IN_A_ROW = 25;

    /**
     * The exception a stage throws to give up on a video for that reason.
     *
     * @param string $video which video, e.g. "file #14755"
     */
    public static function givingUp(string $video, int $missing, string $firstUrl): RuntimeException
    {
        return new RuntimeException(sprintf(
            '%d screenshots in a row are missing for %s, starting with %s; its screenshots'
            . ' need to be made again (bin/requeue_video.php).',
            $missing,
            $video,
            $firstUrl
        ));
    }
}
