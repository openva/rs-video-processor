<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Speakers;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\DiarizerInterface;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\LegislatorDirectory;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\OcrSpeakerExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJob;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerMetadataExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerResultWriter;

class SpeakerDetectionProcessorTest extends TestCase
{
    public function testUsesMetadataBeforeDiarizer(): void
    {
        $pdo = $this->createTestDatabase();

        $metadataExtractor = new SpeakerMetadataExtractor();
        $diarizer = $this->createMock(DiarizerInterface::class);
        $diarizer->expects($this->never())->method('diarize');
        $ocrExtractor = $this->createMock(OcrSpeakerExtractor::class);
        $ocrExtractor->expects($this->never())->method('extract');
        $legislators = new LegislatorDirectory($pdo);
        $writer = new SpeakerResultWriter($pdo);
        $processor = new SpeakerDetectionProcessor($metadataExtractor, $diarizer, $ocrExtractor, $legislators, $writer, null);

        $job = new SpeakerJob(1, 'house', 'file://example', ['Speakers' => [['text' => 'Smith', 'startTime' => '00:00:10']]]);
        $this->assertTrue($processor->process($job));

        $count = $pdo->query('SELECT COUNT(*) FROM video_index')->fetchColumn();
        $this->assertSame(1, (int) $count);
    }

    public function testSkipsCommitteeVideosWhenNoMetadata(): void
    {
        $pdo = $this->createTestDatabase();

        $metadataExtractor = new SpeakerMetadataExtractor();
        $diarizer = $this->createMock(DiarizerInterface::class);
        $diarizer->expects($this->never())->method('diarize');
        $ocrExtractor = $this->createMock(OcrSpeakerExtractor::class);
        $ocrExtractor->expects($this->never())->method('extract');
        $legislators = new LegislatorDirectory($pdo);
        $writer = new SpeakerResultWriter($pdo);
        $processor = new SpeakerDetectionProcessor($metadataExtractor, $diarizer, $ocrExtractor, $legislators, $writer, null);

        $job = new SpeakerJob(
            1,
            'house',
            'file://example',
            ['event_type' => 'committee', 'committee_name' => 'Finance Committee']
        );
        $processor->process($job);

        // Clean-empty (committee video, no metadata, diarization deliberately
        // skipped): a terminal /none sentinel is recorded so the video isn't
        // re-processed on every future pass.
        $rows = $pdo->query("SELECT raw_text, ignored, type FROM video_index WHERE file_id = 1")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('/none', $rows[0]['raw_text']);
        $this->assertSame('y', $rows[0]['ignored']);
        $this->assertSame('legislator', $rows[0]['type']);
    }

    public function testRecordsNoneFoundSentinelWhenCleanlyEmpty(): void
    {
        $metadataExtractor = $this->createMock(SpeakerMetadataExtractor::class);
        $metadataExtractor->method('extract')->willReturn([]);

        $diarizer = $this->createMock(DiarizerInterface::class);
        $diarizer->expects($this->never())->method('diarize');

        $ocrExtractor = $this->createMock(OcrSpeakerExtractor::class);
        $legislators = $this->createMock(LegislatorDirectory::class);

        $writer = $this->createMock(SpeakerResultWriter::class);
        $writer->expects($this->once())->method('recordNoneFound')->with(42);
        $writer->expects($this->never())->method('write');

        $processor = new SpeakerDetectionProcessor(
            $metadataExtractor,
            $diarizer,
            $ocrExtractor,
            $legislators,
            $writer,
            null
        );

        // Committee video (diarization is skipped for committees), no manifest
        // (OCR is skipped), no metadata speakers: cleanly empty.
        $job = new SpeakerJob(42, 'senate', 'https://video.richmondsunlight.com/senate/comm/20250101.mp4', null, 'committee', null, null);
        $this->assertTrue($processor->process($job));
    }

    public function testLeavesClaimWhenDiarizationFails(): void
    {
        $metadataExtractor = $this->createMock(SpeakerMetadataExtractor::class);
        $metadataExtractor->method('extract')->willReturn([]);

        $diarizer = $this->createMock(DiarizerInterface::class);
        $diarizer->method('diarize')->willThrowException(new \RuntimeException('AWS Transcribe unavailable'));

        $ocrExtractor = $this->createMock(OcrSpeakerExtractor::class);
        $legislators = $this->createMock(LegislatorDirectory::class);

        $writer = $this->createMock(SpeakerResultWriter::class);
        // Failure must NOT look like "no speakers": no sentinel, no clearing —
        // the claim placeholder stays and StaleClaimCleaner releases it later.
        $writer->expects($this->never())->method('recordNoneFound');
        $writer->expects($this->never())->method('clearExisting');
        $writer->expects($this->never())->method('write');

        $processor = new SpeakerDetectionProcessor(
            $metadataExtractor,
            $diarizer,
            $ocrExtractor,
            $legislators,
            $writer,
            null
        );

        // Floor video with no metadata speakers and no manifest → diarization runs and throws.
        $job = new SpeakerJob(43, 'house', 'https://video.richmondsunlight.com/house/floor/20250101.mp4', null, 'floor', null, null);
        $this->assertFalse($processor->process($job));
    }

    /**
     * Diarization costs money per audio hour and returns anonymous labels.
     * When it is switched off, a floor video whose screenshots show no names
     * is recorded as "none found" without calling the diarizer.
     */
    public function testSkipsDiarizationWhenDisabled(): void
    {
        $metadataExtractor = $this->createMock(SpeakerMetadataExtractor::class);
        $metadataExtractor->method('extract')->willReturn([]);

        $diarizer = $this->createMock(DiarizerInterface::class);
        $diarizer->expects($this->never())->method('diarize');

        $ocrExtractor = $this->createMock(OcrSpeakerExtractor::class);
        $ocrExtractor->expects($this->once())->method('extract')->willReturn([]);

        $writer = $this->createMock(SpeakerResultWriter::class);
        $writer->expects($this->once())->method('recordNoneFound')->with(44);

        $processor = new SpeakerDetectionProcessor(
            $metadataExtractor,
            $diarizer,
            $ocrExtractor,
            $this->createMock(LegislatorDirectory::class),
            $writer,
            null,
            false
        );

        $job = new SpeakerJob(
            44,
            'senate',
            'https://video.richmondsunlight.com/senate/floor/20250101-44.mp4',
            null,
            'floor',
            '/senate/floor/20250101-44/',
            'https://video.richmondsunlight.com/senate/floor/20250101-44/manifest.json'
        );
        $this->assertTrue($processor->process($job));
    }

    /**
     * With diarization off, on-screen names are the only source left. A video
     * that has no screenshots yet must be tried again later, not written off.
     */
    public function testWaitsForScreenshotsWhenDiarizationDisabled(): void
    {
        $metadataExtractor = $this->createMock(SpeakerMetadataExtractor::class);
        $metadataExtractor->method('extract')->willReturn([]);

        $diarizer = $this->createMock(DiarizerInterface::class);
        $diarizer->expects($this->never())->method('diarize');

        $writer = $this->createMock(SpeakerResultWriter::class);
        $writer->expects($this->never())->method('recordNoneFound');
        $writer->expects($this->never())->method('write');

        $processor = new SpeakerDetectionProcessor(
            $metadataExtractor,
            $diarizer,
            $this->createMock(OcrSpeakerExtractor::class),
            $this->createMock(LegislatorDirectory::class),
            $writer,
            null,
            false
        );

        $job = new SpeakerJob(45, 'senate', 'https://video.richmondsunlight.com/senate/floor/20250102-45.mp4', null, 'floor', null, null);
        $this->assertFalse($processor->process($job));
    }

    /**
     * OCR over a long video runs for many minutes with no database activity, so
     * the connection opened at the start has timed out by the time results are
     * written ("MySQL server has gone away"). The writer must reconnect first.
     */
    public function testReconnectsBeforeWritingResults(): void
    {
        $calls = [];

        $metadataExtractor = $this->createMock(SpeakerMetadataExtractor::class);
        $metadataExtractor->method('extract')->willReturn([]);

        $ocrExtractor = $this->createMock(OcrSpeakerExtractor::class);
        $ocrExtractor->method('extract')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'ocr';
            return [['name' => 'Smith', 'start' => 10.0]];
        });

        $writer = $this->createMock(SpeakerResultWriter::class);
        $writer->method('reconnect')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'reconnect';
        });
        $writer->method('write')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'write';
        });

        $legislators = $this->createMock(LegislatorDirectory::class);
        $legislators->method('matchId')->willReturn(7);

        $processor = new SpeakerDetectionProcessor(
            $metadataExtractor,
            $this->createMock(DiarizerInterface::class),
            $ocrExtractor,
            $legislators,
            $writer,
            null
        );

        $job = new SpeakerJob(
            46,
            'house',
            'https://video.richmondsunlight.com/house/committee/x/20250103-46.mp4',
            null,
            'committee',
            '/house/committee/x/20250103-46/',
            'https://video.richmondsunlight.com/house/committee/x/20250103-46/manifest.json'
        );

        $this->assertTrue($processor->process($job));
        $this->assertSame(['reconnect', 'ocr', 'reconnect', 'write'], $calls);
    }

    public function testReconnectsBeforeRecordingNoneFound(): void
    {
        $calls = [];

        $metadataExtractor = $this->createMock(SpeakerMetadataExtractor::class);
        $metadataExtractor->method('extract')->willReturn([]);

        $ocrExtractor = $this->createMock(OcrSpeakerExtractor::class);
        $ocrExtractor->method('extract')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'ocr';
            return [];
        });

        $writer = $this->createMock(SpeakerResultWriter::class);
        $writer->method('reconnect')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'reconnect';
        });
        $writer->method('recordNoneFound')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'none';
        });

        $processor = new SpeakerDetectionProcessor(
            $metadataExtractor,
            $this->createMock(DiarizerInterface::class),
            $ocrExtractor,
            $this->createMock(LegislatorDirectory::class),
            $writer,
            null
        );

        $job = new SpeakerJob(
            47,
            'house',
            'https://video.richmondsunlight.com/house/committee/x/20250104-47.mp4',
            null,
            'committee',
            '/house/committee/x/20250104-47/',
            'https://video.richmondsunlight.com/house/committee/x/20250104-47/manifest.json'
        );

        $this->assertTrue($processor->process($job));
        $this->assertSame(['reconnect', 'ocr', 'reconnect', 'none'], $calls);
    }

    public function testDiarizesFloorVideosWhenNoMetadata(): void
    {
        $pdo = $this->createTestDatabase();

        $metadataExtractor = new SpeakerMetadataExtractor();
        $diarizer = $this->createMock(DiarizerInterface::class);
        $diarizer->expects($this->once())
            ->method('diarize')
            ->with('file://example')
            ->willReturn([
                ['name' => 'Speaker_00', 'start' => 0.0],
                ['name' => 'Speaker_01', 'start' => 10.0],
            ]);
        $ocrExtractor = $this->createMock(OcrSpeakerExtractor::class);
        $ocrExtractor->expects($this->never())->method('extract');
        $legislators = new LegislatorDirectory($pdo);
        $writer = new SpeakerResultWriter($pdo);
        $processor = new SpeakerDetectionProcessor($metadataExtractor, $diarizer, $ocrExtractor, $legislators, $writer, null);

        $job = new SpeakerJob(
            1,
            'house',
            'file://example',
            ['event_type' => 'floor', 'committee_name' => null]
        );
        $processor->process($job);

        $count = $pdo->query('SELECT COUNT(*) FROM video_index')->fetchColumn();
        $this->assertSame(2, (int) $count);
    }

    public function testOverwritesExistingEntries(): void
    {
        $pdo = $this->createTestDatabase();

        // Seed existing entries (as MetadataIndexer would)
        $pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, ignored, date_created)
                     VALUES (1, '00:00:00', '00000001', 'Old Speaker', 'legislator', 'n', datetime('now'))");

        $metadataExtractor = new SpeakerMetadataExtractor();
        $diarizer = $this->createMock(DiarizerInterface::class);
        $diarizer->expects($this->never())->method('diarize');
        $ocrExtractor = $this->createMock(OcrSpeakerExtractor::class);
        $ocrExtractor->expects($this->never())->method('extract');
        $legislators = new LegislatorDirectory($pdo);
        $writer = new SpeakerResultWriter($pdo);
        $processor = new SpeakerDetectionProcessor($metadataExtractor, $diarizer, $ocrExtractor, $legislators, $writer, null);

        $job = new SpeakerJob(1, 'house', 'file://example', ['Speakers' => [['text' => 'Smith', 'startTime' => '00:00:10']]]);
        $processor->process($job);

        $rows = $pdo->query("SELECT raw_text FROM video_index WHERE file_id = 1 AND type = 'legislator'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(1, $rows);
        $this->assertSame('Smith', $rows[0]);
    }

    private function createTestDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE people (
            id INTEGER PRIMARY KEY,
            shortname TEXT NOT NULL,
            name TEXT NOT NULL,
            name_formal TEXT NOT NULL,
            birthday DATE,
            race TEXT,
            sex TEXT,
            bio TEXT,
            date_created DATETIME NOT NULL,
            date_modified TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec("INSERT INTO people (id, shortname, name, name_formal, date_created)
                    VALUES (1, 'smith-j', 'John Smith', 'John A. Smith Jr.', datetime('now'))");
        $pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            time TEXT,
            screenshot TEXT,
            raw_text TEXT,
            type TEXT,
            linked_id INTEGER,
            ignored TEXT,
            date_created TEXT
        )');
        return $pdo;
    }
}
