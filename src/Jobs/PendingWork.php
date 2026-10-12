<?php

namespace RichmondSunlight\VideoProcessor\Jobs;

use PDO;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJobQueue;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadQueue;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJobQueue;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobQueue;

/**
 * How much work each stage could do right now.
 *
 * The counts come from the queue classes themselves, so "pending" here always
 * means exactly what the workers would fetch. Videos that are claimed, waiting
 * to be retried, or given up on are not counted.
 */
class PendingWork
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,int> stage name => count, in pipeline order
     */
    public function counts(): array
    {
        return [
            JobLedger::STAGE_DOWNLOAD => (new VideoDownloadQueue($this->pdo))->countPending(),
            JobLedger::STAGE_SCREENSHOTS => (new ScreenshotJobQueue($this->pdo))->countPending(),
            JobLedger::STAGE_TRANSCRIPT => (new TranscriptJobQueue($this->pdo))->countPending(),
            JobLedger::STAGE_BILLS => (new BillDetectionJobQueue($this->pdo))->countPending(),
            JobLedger::STAGE_SPEAKERS => (new SpeakerJobQueue($this->pdo))->countPending(),
            JobLedger::STAGE_ARCHIVE => (new ArchiveJobQueue($this->pdo))->countPending(),
        ];
    }

    public function total(): int
    {
        return array_sum($this->counts());
    }

    /**
     * Unix time a worker last claimed, finished or failed a job; null if never.
     */
    public function lastActivity(): ?int
    {
        return (new JobLedger($this->pdo))->lastActivity();
    }
}
