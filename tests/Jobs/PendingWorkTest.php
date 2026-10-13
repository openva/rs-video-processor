<?php

namespace RichmondSunlight\VideoProcessor\Tests\Jobs;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\PendingWork;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class PendingWorkTest extends TestCase
{
    public function testCountsEachStageAndIgnoresGivenUpJobs(): void
    {
        $pdo = TestDatabaseFactory::create();

        // File 1: scraped, not downloaded.
        $pdo->prepare(
            "INSERT INTO files (chamber, title, date, path, video_index_cache, date_created)
             VALUES ('house', 'A', '2025-01-01', '', :cache, '2025-01-01 00:00:00')"
        )->execute([':cache' => json_encode(['video_url' => 'https://example.test/a.mp4'])]);

        // File 2: on S3 with nothing else done. Pending screenshots and transcript.
        // Speakers wait until screenshots exist.
        $pdo->exec(
            "INSERT INTO files (chamber, title, date, path, date_created)
             VALUES ('house', 'B', '2025-01-02', 'https://video.richmondsunlight.com/house/floor/20250102-2.mp4', '2025-01-02 00:00:00')"
        );

        $pending = new PendingWork($pdo);

        $this->assertSame(
            ['download' => 1, 'screenshots' => 1, 'transcript' => 1, 'bills' => 0, 'speakers' => 0, 'archive' => 0],
            $pending->counts()
        );
        $this->assertSame(3, $pending->total());

        // A video that has been given up on is not work the pipeline can do.
        (new JobLedger($pdo))->markFailed(1, JobLedger::STAGE_DOWNLOAD, 'dead link');
        $this->assertSame(2, $pending->total());
    }

    public function testLastActivityComesFromTheLedger(): void
    {
        $pdo = TestDatabaseFactory::create();
        $pending = new PendingWork($pdo);

        $this->assertNull($pending->lastActivity());

        $pdo->exec("INSERT INTO files (chamber, title, date, date_created) VALUES ('house', 'A', '2025-01-01', '2025-01-01 00:00:00')");
        (new JobLedger($pdo))->claim(1, JobLedger::STAGE_DOWNLOAD);

        $this->assertEqualsWithDelta(time(), $pending->lastActivity(), 5);
    }
}
