<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use RichmondSunlight\VideoProcessor\Jobs\NotReadyYet;

/**
 * OpenAI is refusing requests because the account's spending cap (or prepaid
 * credit) is used up: HTTP 429 with the error code "insufficient_quota".
 * Every request gets the same answer until the cap resets at the start of the
 * month, or Waldo raises it, so this is a wait for every job, not a failure.
 */
class OpenAIQuotaExceeded extends NotReadyYet
{
    /** The error codes OpenAI uses for a spent cap or exhausted credit. */
    public const CODES = ['insufficient_quota', 'billing_hard_limit_reached'];

    /**
     * Near the reset, ask this often rather than waiting a whole month in
     * case the cap resets later than the turn of the month.
     */
    public const RETRY_NEAR_RESET_SECONDS = 6 * 3600;

    /** How long after the turn of the month a refusal is still taken for a late reset. */
    public const LATE_RESET_SECONDS = 2 * 86400;

    public readonly int $resumesAt;

    public function __construct(string $openAiMessage, ?int $now = null)
    {
        $now ??= time();
        $this->resumesAt = self::resumesAt($now);
        parent::__construct(
            sprintf(
                'OpenAI\'s spending cap has been reached (%s). Nothing will be sent to OpenAI until %s UTC; to resume sooner, after raising the cap, run php bin/job_status.php --resume=transcript.',
                $openAiMessage,
                gmdate('Y-m-d H:i', $this->resumesAt)
            ),
            $this->resumesAt - $now,
            true
        );
    }

    /**
     * The start of next month (UTC), when monthly caps reset. Close to the
     * turn of the month, before or just after it, a few hours from now
     * instead, so a reset that comes late doesn't cost a month.
     */
    public static function resumesAt(int $now): int
    {
        $month = (int) gmdate('n', $now);
        $year = (int) gmdate('Y', $now);
        $thisMonth = gmmktime(0, 0, 0, $month, 1, $year);
        $nextMonth = gmmktime(0, 0, 0, $month + 1, 1, $year);
        if ($now - $thisMonth < self::LATE_RESET_SECONDS) {
            return $now + self::RETRY_NEAR_RESET_SECONDS;
        }

        return max($nextMonth, $now + self::RETRY_NEAR_RESET_SECONDS);
    }
}
