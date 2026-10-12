<?php

namespace RichmondSunlight\VideoProcessor\Jobs;

use Log;
use Throwable;

/**
 * The worker loop shared by every pipeline stage: take one job, count the
 * attempt, do the work, record the outcome, repeat.
 *
 * Jobs are taken one at a time, so a worker that dies holds one claim rather
 * than a whole batch.
 */
class StageRunner
{
    public function __construct(
        private JobLedger $ledger,
        private string $stage,
        private ?Log $log = null,
        private int $maxConsecutiveFailures = 3
    ) {
    }

    /**
     * @param callable(): ?object $fetchOne the next job, or null when none is left
     * @param callable(object): int $fileIdOf the job's file ID
     * @param callable(object): mixed $process does the work; throw, or return exactly
     *        false, to report a failure
     * @return int number of jobs that succeeded
     */
    public function run(int $limit, callable $fetchOne, callable $fileIdOf, callable $process): int
    {
        $succeeded = 0;
        $consecutiveFailures = 0;

        for ($i = 0; $i < $limit; $i++) {
            $job = $fetchOne();
            if ($job === null) {
                break;
            }
            $fileId = (int) $fileIdOf($job);

            if (!$this->ledger->begin($fileId, $this->stage)) {
                $this->log?->put(
                    sprintf(
                        'Giving up on %s for file #%d: interrupted %d times without finishing.',
                        $this->stage,
                        $fileId,
                        JobLedger::MAX_ATTEMPTS
                    ),
                    6
                );
                continue;
            }

            $error = null;
            try {
                if ($process($job) === false) {
                    $error = 'The stage finished without a usable result; see earlier log lines for this file.';
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            if ($error === null) {
                $this->ledger->recordSuccess($fileId, $this->stage);
                $succeeded++;
                $consecutiveFailures = 0;
                continue;
            }

            $givingUp = $this->ledger->recordFailure($fileId, $this->stage, $error) === JobLedger::STATUS_FAILED;
            $this->log?->put(
                sprintf(
                    '%s failed for file #%d (%s): %s',
                    ucfirst($this->stage),
                    $fileId,
                    $givingUp ? 'giving up' : 'will retry later',
                    $error
                ),
                $givingUp ? 6 : 5
            );

            // Several failures in a row usually mean an outage, not bad videos.
            // Stop, so an outage does not spend an attempt on every pending job.
            $consecutiveFailures++;
            if ($consecutiveFailures >= $this->maxConsecutiveFailures) {
                $this->log?->put(
                    sprintf('Stopping %s after %d consecutive failures.', $this->stage, $consecutiveFailures),
                    6
                );
                break;
            }
        }

        return $succeeded;
    }
}
