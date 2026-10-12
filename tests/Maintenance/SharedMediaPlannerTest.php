<?php

namespace RichmondSunlight\VideoProcessor\Tests\Maintenance;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Maintenance\SharedMediaPlanner;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class SharedMediaPlannerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabaseFactory::create();
    }

    private function seed(string $title, string $dir, ?array $cache, ?string $path = null): int
    {
        $this->pdo->prepare(
            "INSERT INTO files (chamber, title, date, path, capture_directory, video_index_cache, date_created)
             VALUES ('house', :title, '2026-03-10', :path, :dir, :cache, '2026-03-10 00:00:00')"
        )->execute([
            ':title' => $title,
            ':path' => $path ?? 'https://video.richmondsunlight.com' . rtrim($dir, '/') . '.mp4',
            ':dir' => $dir,
            ':cache' => $cache === null ? null : json_encode($cache),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testDuplicatesAreDeletedAndSurvivorsThatStillShareAreRequeued(): void
    {
        $debate = ['source' => 'house', 'content_id' => 1, 'video_url' => 'https://source.example/debate.mp4'];
        $session = ['source' => 'house', 'content_id' => 2, 'video_url' => 'https://source.example/session.mp4'];

        // Two different meetings overwrote each other, and one was imported three times.
        $first = $this->seed('Page Debate', '/house/floor/20260310/', $debate);
        $other = $this->seed('House Session', '/house/floor/20260310/', $session);
        $copyA = $this->seed('Page Debate', '/house/floor/20260310/', $debate);
        $copyB = $this->seed('Page Debate', '/house/floor/20260310/', $debate);

        $plan = (new SharedMediaPlanner($this->pdo))->plan();

        $this->assertSame([$copyA, $copyB], $plan['delete'], 'Later copies of one upstream video are deleted.');
        $this->assertSame([$first, $other], $plan['requeue'], 'The two real meetings still share media, so both are redone.');
        $this->assertSame([], $plan['unrecoverable']);
    }

    public function testOneMeetingImportedTwiceNeedsNoRequeue(): void
    {
        $cache = ['source' => 'house', 'content_id' => 5, 'video_url' => 'https://source.example/five.mp4'];
        $keep = $this->seed('Firearms Subcommittee', '/house/floor/20260226/', $cache);
        $copy = $this->seed('Firearms Subcommittee', '/house/floor/20260226/', $cache);

        $plan = (new SharedMediaPlanner($this->pdo))->plan();

        $this->assertSame([$copy], $plan['delete']);
        $this->assertSame([], $plan['requeue'], 'Once the copy is gone the survivor shares with nobody; its video is its own.');
        $this->assertSame([], $plan['unrecoverable']);
        $this->assertNotContains($keep, $plan['delete']);
    }

    public function testRowsWithNoSourceUrlAreReportedNotRequeued(): void
    {
        $a = $this->seed('House Video', '/house/committee//20190130/', null);
        $b = $this->seed('House Video', '/house/committee//20190130/', null);

        $plan = (new SharedMediaPlanner($this->pdo))->plan();

        $this->assertSame([], $plan['delete']);
        $this->assertSame([], $plan['requeue']);
        $this->assertSame([$a, $b], $plan['unrecoverable']);
    }

    public function testRowsSharingOnlyAVideoAreFound(): void
    {
        $shared = 'https://video.richmondsunlight.com/house/floor/20260211.mp4';
        $a = $this->seed('Higher Ed', '/house/floor/20260211-a/', ['source' => 'house', 'content_id' => 7, 'video_url' => 'https://source.example/7.mp4'], $shared);
        $b = $this->seed('Health', '/house/floor/20260211-b/', ['source' => 'house', 'content_id' => 8, 'video_url' => 'https://source.example/8.mp4'], $shared);

        $plan = (new SharedMediaPlanner($this->pdo))->plan();

        $this->assertSame([$a, $b], $plan['requeue']);
    }
}
