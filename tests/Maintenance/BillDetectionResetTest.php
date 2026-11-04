<?php

namespace RichmondSunlight\VideoProcessor\Tests\Maintenance;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJobQueue;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Maintenance\BillDetectionReset;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class BillDetectionResetTest extends TestCase
{
    private PDO $pdo;
    private JobLedger $ledger;

    protected function setUp(): void
    {
        $this->pdo = TestDatabaseFactory::create();
        TestDatabaseFactory::addPipelineTables($this->pdo);
        $this->ledger = new JobLedger($this->pdo);
    }

    private function video(string $chamber, ?int $committeeId, string $date = '2025-02-01'): int
    {
        $this->pdo->prepare(
            "INSERT INTO files (chamber, committee_id, date, capture_directory, path)
             VALUES (:chamber, :committee, :date, '/senate/floor/x/', 'https://video.richmondsunlight.com/x.mp4')"
        )->execute([':chamber' => $chamber, ':committee' => $committeeId, ':date' => $date]);

        return (int) $this->pdo->lastInsertId();
    }

    private function billRow(int $fileId, string $rawText, string $ignored = 'n', string $type = 'bill'): void
    {
        $this->pdo->prepare(
            "INSERT INTO video_index (file_id, time, raw_text, type, ignored) VALUES (:id, '00:00:01', :raw, :type, :ignored)"
        )->execute([':id' => $fileId, ':raw' => $rawText, ':type' => $type, ':ignored' => $ignored]);
    }

    private function finished(int $fileId): void
    {
        $this->ledger->begin($fileId, JobLedger::STAGE_BILLS);
        $this->ledger->recordSuccess($fileId, JobLedger::STAGE_BILLS);
    }

    public function testListsFinishedSenateFloorVideosOnly(): void
    {
        $nothingFound = $this->video('senate', null, '2024-01-10');
        $this->billRow($nothingFound, '/none', 'y');
        $someFound = $this->video('senate', null, '2025-01-10');
        $this->billRow($someFound, 'SB12');

        $committee = $this->video('senate', 7);
        $this->billRow($committee, '/none', 'y');
        $house = $this->video('house', null);
        $this->billRow($house, '/none', 'y');
        $notYetRun = $this->video('senate', null);
        $tooOld = $this->video('senate', null, '2019-02-01');
        $this->billRow($tooOld, '/none', 'y');
        $claimed = $this->video('senate', null);
        $this->billRow($claimed, '/pending', 'y');
        $running = $this->video('senate', null);
        $this->billRow($running, '/none', 'y');
        $this->ledger->begin($running, JobLedger::STAGE_BILLS);

        $this->assertSame([$someFound, $nothingFound], (new BillDetectionReset($this->pdo))->senateFloorVideos());
    }

    public function testAResetVideoIsQueuedForBillDetectionAgain(): void
    {
        $id = $this->video('senate', null);
        $this->billRow($id, '/none', 'y');
        $this->billRow($id, 'Sen. Someone', 'n', 'legislator');
        $this->finished($id);
        $queue = new BillDetectionJobQueue($this->pdo);
        $this->assertSame(0, $queue->countPending());

        (new BillDetectionReset($this->pdo))->reset($id);

        $this->assertSame(1, $queue->countPending());
        $this->assertSame(
            ['legislator'],
            $this->pdo->query('SELECT type FROM video_index WHERE file_id = ' . $id)->fetchAll(PDO::FETCH_COLUMN),
            'Speaker results are kept.'
        );
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM video_jobs WHERE stage = 'bills'")->fetchColumn());
    }
}
