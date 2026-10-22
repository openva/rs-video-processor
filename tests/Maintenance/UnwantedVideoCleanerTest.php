<?php

namespace RichmondSunlight\VideoProcessor\Tests\Maintenance;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Maintenance\UnwantedVideoCleaner;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class UnwantedVideoCleanerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabaseFactory::create();
    }

    private function seed(string $title, ?string $path, ?string $captureDirectory = null, ?int $committeeId = 0, ?string $cache = null): int
    {
        $this->pdo->prepare(
            "INSERT INTO files (chamber, committee_id, title, type, date, path, capture_directory, video_index_cache, date_created)
             VALUES ('house', :committee, :title, 'video', '2026-08-17', :path, :dir, :cache, '2026-08-17 00:00:00')"
        )->execute([
            ':committee' => $committeeId,
            ':title' => $title,
            ':path' => $path,
            ':dir' => $captureDirectory,
            ':cache' => $cache ?? json_encode(['video_url' => $path]),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function exists(int $id): bool
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM files WHERE id = {$id}")->fetchColumn() === 1;
    }

    public function testRemovesOnlyUnwantedVideosNothingHasBeenDoneWith(): void
    {
        $untouched = $this->seed('House Autism Advisory Council', 'https://sliq.example/autism.mp4');
        $screenshotted = $this->seed(
            'House Distributed Energy Resources Task Force',
            'https://video.richmondsunlight.com/house/committee/x/20260915-1.mp4',
            '/house/committee/x/20260915-1/'
        );
        $committee = $this->seed('House Labor and Commerce', 'https://sliq.example/lc.mp4');
        $floor = $this->seed('House Session', 'https://sliq.example/floor.mp4', null, null);

        $this->pdo->exec("INSERT INTO video_sources (source, source_id, file_id, date_created) VALUES ('house', 'abc', {$untouched}, '2026-08-17 00:00:00')");
        (new JobLedger($this->pdo))->recordFailure($untouched, JobLedger::STAGE_DOWNLOAD, 'timeout');

        $cleaner = new UnwantedVideoCleaner($this->pdo);
        $plan = $cleaner->plan();

        $this->assertSame([$untouched], array_column($plan['remove'], 'id'));
        $this->assertSame([$screenshotted], array_column($plan['processed'], 'id'));

        $this->assertSame(1, $cleaner->remove(array_column($plan['remove'], 'id')));

        $this->assertFalse($this->exists($untouched));
        foreach (['video_sources', 'video_jobs'] as $table) {
            $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM {$table} WHERE file_id = {$untouched}")->fetchColumn(), $table);
        }
        $this->assertTrue($this->exists($screenshotted));
        $this->assertTrue($this->exists($committee));
        $this->assertTrue($this->exists($floor));
    }

    /**
     * A video counts as worked on once anything has been produced for it, or
     * while a stage is running on it.
     */
    public function testLeavesUnwantedVideosThatHaveBeenWorkedOn(): void
    {
        $indexed = $this->seed('House Public Body Procurement Workgroup', 'https://sliq.example/a.mp4');
        $this->pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, ignored, date_created)
            VALUES ({$indexed}, '00:00:01', '00000001', 'Del. Smith', 'legislator', 'n', '2026-08-17 00:00:00')");

        $transcribed = $this->seed('House Public Body Procurement Workgroup', 'https://sliq.example/b.mp4');
        $this->pdo->exec("INSERT INTO video_transcript (file_id, text, time_start, time_end, new_speaker, date_created)
            VALUES ({$transcribed}, 'hello', '00:00:01', '00:00:02', 'n', '2026-08-17 00:00:00')");

        $downloading = $this->seed('House Public Body Procurement Workgroup', 'https://sliq.example/c.mp4');
        (new JobLedger($this->pdo))->claim($downloading, JobLedger::STAGE_DOWNLOAD);

        $plan = (new UnwantedVideoCleaner($this->pdo))->plan();

        $this->assertSame([], $plan['remove']);
        $this->assertEqualsCanonicalizing([$indexed, $transcribed, $downloading], array_column($plan['processed'], 'id'));
    }

    /**
     * remove() checks again, so a video that was worked on after the plan was
     * made is not deleted.
     */
    public function testRemoveRefusesAVideoThatHasSinceBeenWorkedOn(): void
    {
        $id = $this->seed('House Autism Advisory Council', 'https://sliq.example/autism.mp4');
        $cleaner = new UnwantedVideoCleaner($this->pdo);
        $plan = $cleaner->plan();

        $this->pdo->exec("UPDATE files SET path = 'https://video.richmondsunlight.com/house/committee/x/1.mp4' WHERE id = {$id}");

        $this->assertSame(0, $cleaner->remove(array_column($plan['remove'], 'id')));
        $this->assertTrue($this->exists($id));
    }

    /** Videos from the old site have paths that look undownloaded; they stay. */
    /** File 18537: stored as "Senate Session", already transcribed and archived. */
    public function testRemovesThePagesSessionsHoweverFarAlong(): void
    {
        $mock = $this->seed(
            'Senate Session',
            'https://archive.org/download/x/x.mp4',
            '/senate/floor/20250221-1/',
            0,
            json_encode(['title' => 'Senate of Virginia: Senate Chamber (Page Mock Session) on 2025-02-21 [Finished]'])
        );
        $this->pdo->exec("INSERT INTO video_transcript (file_id, text, time_start, time_end) VALUES ({$mock}, 'Mr. President', '00:00:01', '00:00:02')");
        $debate = $this->seed('House Page Debate', null);
        $session = $this->seed(
            'Senate Session',
            'https://archive.org/download/y/y.mp4',
            '/senate/floor/20250221-2/',
            0,
            json_encode(['title' => 'Senate of Virginia: Senate Chamber on 2025-02-21 [Finished]'])
        );
        $cleaner = new UnwantedVideoCleaner($this->pdo);

        $plan = $cleaner->plan();
        $this->assertEqualsCanonicalizing([$mock, $debate], array_column($plan['pages'], 'id'));
        $this->assertSame([], array_column($plan['remove'], 'id'));
        $this->assertSame([], array_column($plan['processed'], 'id'));

        $this->assertSame(2, $cleaner->removePages([$mock, $debate]));
        $this->assertFalse($this->exists($mock));
        $this->assertFalse($this->exists($debate));
        $this->assertTrue($this->exists($session));
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM video_transcript WHERE file_id = {$mock}")->fetchColumn());
    }

    public function testDoesNotRemoveAPageSessionWhileAStageRuns(): void
    {
        $debate = $this->seed('House Page Debate', null);
        (new JobLedger($this->pdo))->begin($debate, JobLedger::STAGE_DOWNLOAD);

        $this->assertSame(0, (new UnwantedVideoCleaner($this->pdo))->removePages([$debate]));
        $this->assertTrue($this->exists($debate));
    }

    public function testNeverRemovesVideosFromBeforeThisPipeline(): void
    {
        $legacy = $this->seed('House Joint Commission on Health Care', '/video/house/2009/jchc.mp4', null, 0, 'a:1:{s:4:"bill";a:0:{}}');

        $plan = (new UnwantedVideoCleaner($this->pdo))->plan();

        $this->assertSame([], $plan['remove']);
        $this->assertSame([$legacy], array_column($plan['processed'], 'id'));
    }
}
