<?php

namespace RichmondSunlight\VideoProcessor\Tests\Jobs;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJobQueue;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadQueue;
use RichmondSunlight\VideoProcessor\Jobs\ProcessingPriority;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJobQueue;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobQueue;

/**
 * Floor sessions are processed first, then committee meetings, then
 * subcommittee meetings; newest first within each.
 */
class QueuePriorityTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:int}>
     */
    public static function titles(): array
    {
        return [
            'house floor' => ['House Session', 0],
            'senate floor' => ['Senate Session', 0],
            'senate floor, youtube' => ['Senate Senate of Virginia: Senate Chamber on 2026-02-04 [Finished]', 0],
            'reconvened' => ['House April 22, 2026 - Reconvened Session', 0],
            'committee' => ['House Public Safety', 1],
            'senate committee, youtube' => ['Senate Senate of Virginia: Finance and Appropriations on 2026-02-04 [Finished]', 1],
            'no title' => ['', 1],
            'subcommittee' => ['House Finance Subcommittee #1', 2],
            'joint subcommittee' => ['House Joint Subcommittee on Tax Policy', 2],
            'senate subcommittee, youtube' => ['Senate Senate of Virginia: SFAC: Health and Human Resources on 2026-01-29 [Finished]', 2],
        ];
    }

    /**
     * @dataProvider titles
     */
    public function testTitleIsRanked(string $title, int $expected): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (id INTEGER PRIMARY KEY, title TEXT)');
        $pdo->prepare('INSERT INTO files (id, title) VALUES (1, :title)')->execute([':title' => $title]);

        $rank = $pdo->query('SELECT ' . ProcessingPriority::orderSql('f') . ' FROM files f')->fetchColumn();
        $this->assertSame($expected, (int) $rank);
    }

    public function testMissingTitleRanksAsCommittee(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (id INTEGER PRIMARY KEY, title TEXT)');
        $pdo->exec('INSERT INTO files (id, title) VALUES (1, NULL)');

        $this->assertSame(1, (int) $pdo->query('SELECT ' . ProcessingPriority::orderSql('f') . ' FROM files f')->fetchColumn());
    }

    /**
     * @return array<string,array{0:\Closure,1:\Closure,2:array<string,mixed>}>
     */
    public static function queues(): array
    {
        $s3 = 'https://video.richmondsunlight.com/house/floor/x.mp4';
        $shots = '/house/floor/x/';
        $cache = json_encode(['video_url' => 'https://example.test/a.mp4']);

        return [
            'download' => [fn(PDO $pdo) => new VideoDownloadQueue($pdo), fn(object $job): int => $job->id, ['path' => '', 'video_index_cache' => $cache]],
            'screenshots' => [fn(PDO $pdo) => new ScreenshotJobQueue($pdo), fn(object $job): int => $job->id, ['path' => $s3]],
            'transcript' => [fn(PDO $pdo) => new TranscriptJobQueue($pdo), fn(object $job): int => $job->fileId, ['path' => $s3]],
            'bills' => [fn(PDO $pdo) => new BillDetectionJobQueue($pdo), fn(object $job): int => $job->fileId, ['capture_directory' => $shots]],
            'speakers' => [fn(PDO $pdo) => new SpeakerJobQueue($pdo), fn(object $job): int => $job->fileId, ['path' => $s3, 'capture_directory' => $shots]],
            'archive' => [fn(PDO $pdo) => new ArchiveJobQueue($pdo), fn(object $job): int => $job->fileId, ['path' => $s3, 'webvtt' => 'WEBVTT', 'capture_directory' => $shots, 'transcript' => 'text']],
        ];
    }

    /**
     * @dataProvider queues
     */
    public function testQueueHandsOutFloorThenCommitteeThenSubcommittee(\Closure $makeQueue, \Closure $idOf, array $row): void
    {
        $pdo = TestDatabaseFactory::create();
        $insert = function (string $title, string $date) use ($pdo, $row): int {
            $data = array_merge(['chamber' => 'house', 'title' => $title, 'date' => $date, 'date_created' => '2026-01-01 00:00:00'], $row);
            $columns = array_keys($data);
            $pdo->prepare(sprintf(
                'INSERT INTO files (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', array_map(fn(string $c) => ':' . $c, $columns))
            ))->execute(array_combine(array_map(fn(string $c) => ':' . $c, $columns), array_values($data)));
            return (int) $pdo->lastInsertId();
        };

        // Dates run opposite to priority, so date order alone would get this backwards.
        $subcommittee = $insert('House Finance Subcommittee #1', '2026-03-03');
        $committee = $insert('House Public Safety', '2026-03-02');
        $oldFloor = $insert('House Session', '2026-02-01');
        $newFloor = $insert('House Session', '2026-03-01');

        $ids = array_map($idOf, $makeQueue($pdo)->fetch(10));

        $this->assertSame([$newFloor, $oldFloor, $committee, $subcommittee], $ids);
    }
}
