<?php

namespace RichmondSunlight\VideoProcessor\Tests\Maintenance;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Maintenance\VideoRequeuer;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class VideoRequeuerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabaseFactory::create();
    }

    private function seed(?string $cache): int
    {
        $this->pdo->prepare(
            "INSERT INTO files (chamber, title, date, path, capture_directory, capture_rate, transcript, webvtt, caption_offset, caption_alignment, video_index_cache, date_created)
             VALUES ('house', 'T', '2026-08-17', 'https://video.richmondsunlight.com/house/floor/20260817.mp4',
                     '/house/floor/20260817/', 60, 'words', 'WEBVTT', 7.2, 'aligned', :cache, '2026-08-17 00:00:00')"
        )->execute([':cache' => $cache]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testRequeueReturnsTheVideoToNotDownloaded(): void
    {
        $id = $this->seed(json_encode(['video_url' => 'https://source.example/video.mp4']));
        $other = $this->seed(json_encode(['video_url' => 'https://source.example/other.mp4']));

        foreach ([$id, $other] as $fileId) {
            $this->pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, ignored, date_created)
                VALUES ({$fileId}, '00:00:01', '00000002', 'HB1', 'bill', 'n', '2026-08-17 00:00:00')");
            $this->pdo->exec("INSERT INTO video_transcript (file_id, text, time_start, time_end, new_speaker, date_created)
                VALUES ({$fileId}, 'hello', '00:00:01', '00:00:02', 'n', '2026-08-17 00:00:00')");
            (new JobLedger($this->pdo))->markFailed($fileId, JobLedger::STAGE_BILLS, 'x');
        }

        $url = (new VideoRequeuer($this->pdo))->requeue($id);

        $this->assertSame('https://source.example/video.mp4', $url);

        $row = $this->pdo->query("SELECT path, capture_directory, capture_rate, transcript, webvtt, caption_offset, caption_alignment FROM files WHERE id = {$id}")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('https://source.example/video.mp4', $row['path']);
        $this->assertNull($row['capture_directory']);
        $this->assertNull($row['capture_rate']);
        $this->assertNull($row['transcript']);
        $this->assertNull($row['webvtt']);
        // The new transcript is aligned against the new download.
        $this->assertNull($row['caption_offset']);
        $this->assertNull($row['caption_alignment']);

        foreach (['video_index', 'video_transcript', 'video_jobs'] as $table) {
            $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM {$table} WHERE file_id = {$id}")->fetchColumn());
            $this->assertSame(
                1,
                (int) $this->pdo->query("SELECT COUNT(*) FROM {$table} WHERE file_id = {$other}")->fetchColumn(),
                "Another file's rows in {$table} must be untouched."
            );
        }
    }

    public function testRefusesAVideoWithNoSourceUrl(): void
    {
        $id = $this->seed(null);

        try {
            (new VideoRequeuer($this->pdo))->requeue($id);
            $this->fail('A video that cannot be re-downloaded must not be requeued.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be re-downloaded', $e->getMessage());
        }

        $path = $this->pdo->query("SELECT path FROM files WHERE id = {$id}")->fetchColumn();
        $this->assertSame('https://video.richmondsunlight.com/house/floor/20260817.mp4', $path, 'Nothing may change on refusal.');
    }

    public function testRefusesAnUnknownFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VideoRequeuer($this->pdo))->requeue(999);
    }
}
