<?php

namespace RichmondSunlight\VideoProcessor\Tests\Maintenance;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Maintenance\CaptionTextRepairer;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

class CaptionTextRepairerTest extends TestCase
{
    private PDO $pdo;

    /** As the transcript stage stored YouTube's rolling captions before November 2026. */
    private const STORED = [
        [4153.749, 4153.759, '&gt;&gt; Madam President, can that bill go by'],
        [4153.759, 4154.789, '&gt;&gt; Madam President, can that bill go by temporarily, please?'],
        [4154.789, 4154.799, 'temporarily, please?'],
        [4154.799, 4157.829, 'temporarily, please? &gt;&gt; Without objection, House Bill 21 goes by'],
        [4157.829, 4157.839, '&gt;&gt; Without objection, House Bill 21 goes by'],
        [4157.839, 4162.550, '&gt;&gt; Without objection, House Bill 21 goes by temporarily. House Bill 40,'],
    ];

    protected function setUp(): void
    {
        $this->pdo = TestDatabaseFactory::create();
        // Archived, so its captions on the Internet Archive need replacing too.
        $this->store(1, 'https://archive.org/download/a/a.mp4', self::STORED);
        // Granicus captions, which never rolled and have no escaped marks.
        $this->store(2, 'https://video.richmondsunlight.com/senate/floor/20200110-2.mp4', [
            [9.338, 10.706, 'the senate will come to order.'],
            [11.508, 13.374, 'Aye.'],
            [13.374, 14.000, 'Aye.'],
        ]);
    }

    /**
     * @param list<array{0:float,1:float,2:string}> $cues
     */
    private function store(int $fileId, string $path, array $cues): void
    {
        $segments = array_map(static fn (array $cue) => ['start' => $cue[0], 'end' => $cue[1], 'text' => $cue[2]], $cues);
        $this->pdo->prepare("INSERT INTO files (id, chamber, type, date, path) VALUES (:id, 'senate', 'video', '2026-03-02', :path)")
            ->execute([':id' => $fileId, ':path' => $path]);
        (new TranscriptWriter($this->pdo))->write($fileId, $segments, 'aligned', 0.0);
        // The website matched a speaker to the second row.
        $this->pdo->prepare("UPDATE video_transcript SET legislator_id = 42, new_speaker = 'y'
                             WHERE id = (SELECT id FROM video_transcript WHERE file_id = :id ORDER BY id LIMIT 1 OFFSET 1)")
            ->execute([':id' => $fileId]);
        // Sending the captions written above is not what's being tested.
        $this->pdo->exec('DELETE FROM video_jobs');
    }

    public function testInspectChangesNothing(): void
    {
        $before = $this->pdo->query('SELECT * FROM video_transcript ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        $summary = (new CaptionTextRepairer($this->pdo))->inspect(1);

        $this->assertSame(['status' => 'repairable', 'rows' => 6, 'deleted' => 3, 'changed' => 3], $summary);
        $this->assertSame($before, $this->pdo->query('SELECT * FROM video_transcript ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testRepairKeepsEachLineOnceAndTheSpeakerMatches(): void
    {
        (new CaptionTextRepairer($this->pdo))->repair(1);

        $rows = $this->pdo->query('SELECT text, time_start, legislator_id, new_speaker FROM video_transcript WHERE file_id = 1 ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([
            ['text' => 'temporarily, please?', 'time_start' => '01:09:14', 'legislator_id' => 42, 'new_speaker' => 'y'],
            ['text' => 'Without objection, House Bill 21 goes by', 'time_start' => '01:09:15', 'legislator_id' => null, 'new_speaker' => 'n'],
            ['text' => 'temporarily. House Bill 40,', 'time_start' => '01:09:18', 'legislator_id' => null, 'new_speaker' => 'n'],
        ], $rows);

        $file = $this->pdo->query('SELECT transcript, webvtt, caption_alignment FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('temporarily, please? Without objection, House Bill 21 goes by temporarily. House Bill 40,', $file['transcript']);
        $this->assertSame(
            "WEBVTT\n\n01:09:13.759 --> 01:09:14.789\ntemporarily, please?\n\n"
            . "01:09:14.799 --> 01:09:17.829\nWithout objection, House Bill 21 goes by\n\n"
            . "01:09:17.839 --> 01:09:22.550\ntemporarily. House Bill 40,\n",
            $file['webvtt']
        );
        $this->assertSame('aligned', $file['caption_alignment']);
        $this->assertSame(
            'pending',
            $this->pdo->query("SELECT status FROM video_jobs WHERE file_id = 1 AND stage = 'archive_captions'")->fetchColumn()
        );
    }

    public function testRepairingAgainChangesNothing(): void
    {
        $repairer = new CaptionTextRepairer($this->pdo);
        $repairer->repair(1);
        $after = $this->pdo->query('SELECT * FROM video_transcript ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame('clean', $repairer->repair(1)['status']);
        $this->assertSame($after, $this->pdo->query('SELECT * FROM video_transcript ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testCaptionsThatNeverRolledAreLeftAlone(): void
    {
        $summary = (new CaptionTextRepairer($this->pdo))->repair(2);

        $this->assertSame(['status' => 'clean', 'rows' => 3, 'deleted' => 0, 'changed' => 0], $summary);
        $this->assertSame(
            ['the senate will come to order.', 'Aye.', 'Aye.'],
            $this->pdo->query('SELECT text FROM video_transcript WHERE file_id = 2 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
        );
        $this->assertFalse($this->pdo->query("SELECT 1 FROM video_jobs WHERE file_id = 2")->fetchColumn());
    }

    public function testRowsThatDontMatchTheCuesAreLeftAlone(): void
    {
        $this->pdo->exec("INSERT INTO video_transcript (file_id, text, time_start, time_end, new_speaker) VALUES (1, 'Extra', '02:00:00', '02:00:01', 'n')");

        $summary = (new CaptionTextRepairer($this->pdo))->repair(1);

        $this->assertSame('mismatch', $summary['status']);
        $this->assertSame('7 transcript rows, 6 WebVTT cues', $summary['reason']);
        $this->assertSame(7, (int) $this->pdo->query('SELECT COUNT(*) FROM video_transcript WHERE file_id = 1')->fetchColumn());
    }

    public function testCandidatesAreVideosWithTranscriptsInIdOrder(): void
    {
        $this->pdo->exec("INSERT INTO files (id, chamber, type, date, webvtt) VALUES (3, 'house', 'video', '2026-01-01', 'WEBVTT')");
        $repairer = new CaptionTextRepairer($this->pdo);

        $this->assertSame([1, 2], $repairer->candidates(0, 10));
        $this->assertSame([2], $repairer->candidates(1, 10));
        $this->assertSame([1], $repairer->candidates(0, 1));
    }
}
