<?php

namespace RichmondSunlight\VideoProcessor\Jobs;

use RuntimeException;

/**
 * Thrown by a stage whose work is waiting on someone else, such as the
 * Internet Archive processing an upload. StageRunner then puts the job aside
 * for $retrySeconds without counting it as a failed attempt.
 *
 * $everyJobWaits says the other jobs are waiting on the same thing, such as
 * the Internet Archive turning every upload away, so StageRunner stops the run.
 */
class NotReadyYet extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retrySeconds,
        public readonly bool $everyJobWaits = false
    )
    {
        parent::__construct($message);
    }
}
