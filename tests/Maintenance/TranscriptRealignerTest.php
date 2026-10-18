<?php

namespace RichmondSunlight\VideoProcessor\Tests\Maintenance;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Maintenance\CopyDurationCheck;
use RichmondSunlight\VideoProcessor\Maintenance\TranscriptRealigner;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionAligner;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionAlignment;

class TranscriptRealignerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabaseFactory::create();
        $insert = $this->pdo->prepare("INSERT INTO files (id, chamber, type, date, path, webvtt) VALUES (:id, 'senate', 'video', :date, :path, :webvtt)");
        $insert->execute([':id' => 1, ':date' => '2025-04-02', ':path' => 'https://archive.org/download/a/a.mp4',
            ':webvtt' => "WEBVTT\n\n00:00:10.000 --> 00:00:12.000\nHello\n"]);
        // Captions, but no transcript yet.
        $insert->execute([':id' => 2, ':date' => '2026-02-05', ':path' => 'https://archive.org/download/b/b.mp4',
            ':webvtt' => "WEBVTT\n\n00:01:00.000 --> 00:01:02.000\nGood morning\n"]);
        // Never downloaded: there is nothing to listen to.
        $insert->execute([':id' => 3, ':date' => '2026-02-06', ':path' => null, ':webvtt' => "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHi\n"]);
        $this->pdo->exec("INSERT INTO video_transcript (file_id, text, time_start, time_end, new_speaker, legislator_id)
                          VALUES (1, 'Hello', '00:00:10.00', '00:00:12.00', 'y', 42)");
    }

    public function testDryRunReportsWithoutChangingAnything(): void
    {
        $realigner = $this->realigner($this->aligner(7.0));
        $before = $this->snapshot();

        $this->assertSame([2, 1], array_column($realigner->candidates(10), 'id'));
        $this->assertSame(2, $realigner->countCandidates());

        $captions = $realigner->captions(1);
        $this->assertSame('rows', $captions['source']);
        $this->assertSame([['start' => 10.0, 'end' => 12.0, 'text' => 'Hello']], $captions['segments']);
        $this->assertSame('webvtt', $realigner->captions(2)['source']);

        $result = $realigner->measure($captions['segments'], $this->video(1));
        $this->assertSame('aligned', $result->status());

        $this->assertSame($before, $this->snapshot());
    }

    public function testApplyShiftsRowsAndWebVttAndKeepsSpeakers(): void
    {
        $realigner = $this->realigner($this->aligner(7.0));

        $realigner->apply(1, $realigner->measure($realigner->captions(1)['segments'], $this->video(1)));

        $row = $this->pdo->query('SELECT time_start, time_end, new_speaker, legislator_id FROM video_transcript')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['time_start' => '00:00:03.00', 'time_end' => '00:00:05.00', 'new_speaker' => 'y', 'legislator_id' => 42], $row);
        $file = $this->pdo->query('SELECT webvtt, caption_alignment, caption_offset FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertStringContainsString('00:00:03.000 --> 00:00:05.000', $file['webvtt']);
        $this->assertSame('aligned', $file['caption_alignment']);
        $this->assertEquals(7.0, $file['caption_offset']);
        $this->assertSame([2], array_column($realigner->candidates(10), 'id'), 'An aligned file is not measured again.');
    }

    public function testRerunningAnAlignedFileKeepsWhatWasRecorded(): void
    {
        ($this->realigner($this->aligner(7.0)))->apply(1, new CaptionAlignment(true, 7.0, 40, 1.0, [], false, ''));

        // Measured again by ID, it now comes out about zero.
        $realigner = $this->realigner($this->aligner(0.2));
        $this->assertSame([1], array_column($realigner->candidates(10, 1), 'id'));
        $realigner->apply(1, $realigner->measure([], $this->video(1)));
        $this->assertSame(['aligned', 7.0], $this->alignment(1));

        // A further shift adds to the first.
        $realigner->apply(1, new CaptionAlignment(true, -2.0, 40, 1.0, [], false, ''));
        $this->assertSame(['aligned', 5.0], $this->alignment(1));
        $this->assertSame('00:00:05.00', $this->pdo->query('SELECT time_start FROM video_transcript')->fetchColumn());
    }

    public function testRecordsFilesThatNeedNoShiftOrCannotBeAligned(): void
    {
        $realigner = $this->realigner($this->aligner(0.3));
        $realigner->apply(1, $realigner->measure([], $this->video(1)));
        $realigner->apply(2, CaptionAlignment::failed('only 3 matches'));

        $this->assertSame(['not_needed', 0.0], $this->alignment(1));
        $this->assertSame(['unaligned', null], $this->alignment(2));
        $this->assertSame('00:00:10.00', $this->pdo->query('SELECT time_start FROM video_transcript')->fetchColumn());
        $this->assertSame(0, $realigner->countCandidates());
    }

    public function testAWrongVideoIsNotMeasured(): void
    {
        $this->pdo->exec("UPDATE files SET video_index_cache = '{\"duration_seconds\": 6457}' WHERE id = 1");
        $aligner = $this->aligner(7.0);
        $realigner = new TranscriptRealigner($this->pdo, $aligner, new CopyDurationCheck(static fn() => 5730.9));

        $result = $realigner->measure($realigner->captions(1)['segments'], $this->video(1));

        $this->assertSame('unaligned', $result->status());
        $this->assertStringContainsString('wrong video: the copy is 1:35:31 long but the recording is 1:47:37', $result->reason);
        $this->assertSame([], $aligner->urls, 'Nothing is transcribed.');
    }

    /** A realigner whose copies all check out. */
    private function realigner(CaptionAligner $aligner): TranscriptRealigner
    {
        return new TranscriptRealigner($this->pdo, $aligner, new CopyDurationCheck(static fn() => null));
    }

    private function video(int $fileId): array
    {
        foreach ($this->realigner($this->aligner(0))->candidates(10, $fileId) as $video) {
            return $video;
        }
        $this->fail("No candidate #{$fileId}");
    }

    private function aligner(float $offset): CaptionAligner
    {
        return new class ($offset) extends CaptionAligner {
            public array $urls = [];

            public function __construct(private float $offset)
            {
            }

            public function align(array $segments, string $videoUrl): CaptionAlignment
            {
                $this->urls[] = $videoUrl;
                return new CaptionAlignment(true, $this->offset, 40, 1.0, [], false, '');
            }
        };
    }

    private function alignment(int $fileId): array
    {
        $row = $this->pdo->query("SELECT caption_alignment, caption_offset FROM files WHERE id = {$fileId}")->fetch(PDO::FETCH_NUM);

        return [$row[0], $row[1] === null ? null : (float) $row[1]];
    }

    private function snapshot(): array
    {
        return [
            $this->pdo->query('SELECT * FROM files ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $this->pdo->query('SELECT * FROM video_transcript ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
