# Video Pipeline Improvements — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Tick each box as you finish it.

**Goal:** Make the pipeline carry every video through download, screenshots, transcript, bill detection, speaker detection and archiving without human supervision, and make it obvious when a video cannot be processed.

**Architecture:** Two small new tables, defined in the shared schema that the `richmondsunlight.com` repository owns, carry the state the pipeline currently guesses at. `video_sources` records each video's upstream ID so imports dedupe exactly. `video_jobs` records, per video and stage, who has claimed it, how many attempts it has had and why it last failed. The six existing `*JobQueue` classes keep their current "what is pending" SQL and gain one shared clause that hides claimed, backing-off and given-up videos. One shared `StageRunner` replaces the six hand-written worker loops.

**Tech Stack:** PHP 8.1+, PHPUnit 10.5 (SQLite in-memory for unit tests, MariaDB 10.11 in Docker for one integration test), bash, systemd, MariaDB in production, ffmpeg, Tesseract.

**Spec:** The "Background" section below. There is no separate design document.

## Global Constraints

- Work on the branch named `pipeline-improvements`. **Never run `git commit` or `git push`, in any repository.** Waldo reviews and commits every change himself. Leave your work in the working tree.
- Never run anything against the production database, S3 or the Internet Archive. Steps marked **HUMAN** are for Waldo; skip them and leave their boxes unticked.
- Never edit anything under `includes/`. It is gitignored and copied from another repository.
- Add no Composer dependencies.
- Never create or alter a database table from this repository. The schema belongs to the `richmondsunlight.com` repository (`deploy/mysql/structure.sql`); the copy here is kept identical to it.
- New SQL in `src/` must run on both SQLite and MariaDB: no `NOW()`, no `ON DUPLICATE KEY`, no `INSERT IGNORE`. Compute timestamps in PHP.
- Timestamps in the two new tables are UTC strings (`gmdate('Y-m-d H:i:s')`) written and compared only by PHP.
- Follow PSR-12. Match the comment density of the file you are editing.
- Run one test file with `./includes/vendor/bin/phpunit <file> --display-warnings`. Run everything with `./includes/vendor/bin/phpunit`.
- Lint every PHP file you touch with `php -l <file>` and every shell script with `bash -n <file>`.
- Baseline before this plan, run natively: 297 tests, 1 failure, 1 skipped. The one failure is `SpeakerDetectionFixtureTest::testHouseFloorSpeakers` (an OCR fixture that depends on the Tesseract version). It is not yours to fix. Any other failure is.
- Every code block in Part A was applied to a copy of this repository on 2026-10-11 and the suite then ran natively at 383 tests, 1 failure (the baseline one), 10 skipped. If a step fails for you, first check that you applied it exactly as written. `MariaDbQueueTest` was run against MariaDB 10.11 in Docker on 2026-10-12 and passed (6 tests). Part B was linted only.
- When a task is finished, tick its boxes in this file and stop changing its files. Do not commit.

## Background

Measured against production on 2026-10-11:

- 2,546 videos are catalogued from 2020 onward. 1,023 are downloaded, 725 have screenshots, 393 have transcripts, 37 have had bill detection, and **21 have been through every stage**.
- The pipeline last ran on 2026-08-20. The database log holds four passes in total. Each pass handles about 5 videos per stage.
- The instance is started by `cron/start_video_processor.php` in the `rs-machine` repository. It starts only when a *new* video appears in the listings, never because a backlog exists, and it runs three times a day.
- Several database rows point at the same video and screenshots. Four different file IDs reported exactly 11,607 frames, six reported 1,239. The causes are in the code:
  - `S3KeyBuilder` builds `chamber/floor/DATE.mp4` for every video whose committee is unresolved, so two such videos on one day overwrite each other.
  - `bin/repair_committee_classification.php` runs every pass and *moves* screenshot directories to that shared path.
  - `VideoImporter` dedupes unresolved-committee videos on their source URL, which stops matching once `path` is rewritten to the S3 URL, so the same video is imported again.
- `VideoImporter` also drops real videos: a second meeting of one committee on one day, or a second floor session, is treated as a duplicate.
- No stage records attempts or errors. 28 files produced 64 screenshot failures in 30 hours, retried in batches.
- `ArchiveJobQueue` accepts `capture_directory = '/pending'`. Once archived, a video's `path` is an archive.org URL that the screenshot queue ignores. 280 archived videos have no screenshots.
- `AudioExtractor` produces one MP3 per video at 14.4 MB per hour, and OpenAI rejects uploads over 25 MB. Videos longer than about 1h44m with no captions cannot be transcribed.
- The download, transcript and archive queues lock rows only for the length of the `SELECT`, so parallel workers can pick the same video.
- 1,229 undownloaded videos are Senate Granicus and 104 are House; the server can fetch both. 150 are Senate YouTube and need `scripts/fetch_youtube_uploads.sh` run on a laptop.

Measured timing: bill detection processes about 4.6 frames per second, 16 minutes for a typical video and 43 minutes for a three-hour one. It is not the bottleneck; lack of runtime is.

## File Map

Created:

| File | Purpose |
|---|---|
| `bin/check_schema.php` | Stops the pipeline if `video_sources` or `video_jobs` is missing; backfills `video_sources` once |
| `src/Sync/VideoSourceRegistry.php` | Reads and writes `video_sources` |
| `src/Sync/VideoSourceBackfill.php` | Registers source IDs for existing rows, reports duplicates |
| `src/Jobs/JobLedger.php` | Reads and writes `video_jobs` |
| `src/Jobs/StageRunner.php` | The one worker loop |
| `src/Jobs/PendingWork.php` | Per-stage pending counts, from the queue classes |
| `src/Fetcher/VideoValidator.php` | "Is this file really a video?" |
| `src/Maintenance/VideoRequeuer.php` | Resets one video to "not downloaded" |
| `bin/pending_work.php`, `bin/job_status.php`, `bin/report_shared_media.php`, `bin/requeue_video.php` | Operator tools |
| `tests/Jobs/*`, `tests/Sync/VideoSource*Test.php`, `tests/Integration/MariaDbQueueTest.php`, others named per task | Tests |

Modified: the six `*JobQueue` classes, the six worker scripts in `bin/`, `VideoImporter`, `S3KeyBuilder`, `ScreenshotGenerator`, `VideoDownloadProcessor`, `AudioExtractor`, `TranscriptProcessor`, `TranscriptWriter`, `ArchiveJobProcessor`, `InternetArchiveUploader`, `BillDetectionProcessor`, `SpeakerDetectionProcessor`, `AppBootstrap`, `bin/repair_committee_classification.php`, `bin/process_uploads.php`, `bin/generate_upload_manifest.php`, `deploy/run-pipeline.sh`, `bin/pipeline_parallel.sh`, `deploy/check-pending-work.sh`, `deploy/deploy.sh`, `deploy/services/video-pipeline.service`, `deploy/mysql/structure.sql`, `tests/Support/TestDatabaseFactory.php`, `AGENTS.md`, `CLAUDE.md`.

## Review Focus

Each of these has a test in the task named.

1. **An outage must not write off the backlog.** If S3, the database or the Internet Archive is down, every job fails. Expected: a stage stops after three consecutive failures. Test in Task 5.
2. **A worker killed mid-job.** The instance shuts itself down. Expected: the job becomes available again after three hours, and a job interrupted three times is given up on. Test in Task 4.
3. **Two workers starting together.** Expected: they never receive the same video. Tests in Task 6 and Task 15.
4. **A video that is backing off or given up on must not count as pending.** Otherwise the drain loop never ends and the instance is started forever. Test in Task 6 and Task 9.
5. **Two real meetings that look alike, and one meeting seen twice.** Same committee, same day, different length: both imported. Same source ID after `path` has changed: imported once. Tests in Task 3.

## Progress

- [x] Task 1: Pipeline tables and schema check
- [x] Task 2: Unique S3 keys
- [x] Task 3: Import dedupe by source ID
- [x] Task 4: JobLedger
- [x] Task 5: StageRunner
- [x] Task 6: Queues honour the ledger
- [x] Task 7: Processors report their outcome
- [x] Task 8: Worker scripts use StageRunner
- [x] Task 9: Pending counts and operator tools
- [x] Task 10: Archive gate and stranded videos
- [x] Task 11: Transcribe long videos
- [x] Task 12: Validate downloads
- [x] Task 13: Orchestration
- [x] Task 14: Requeue and shared-media tools
- [x] Task 15: MariaDB integration test
- [x] Task 16: Documentation
- [x] Part B: `rs-machine` trigger
- [ ] Part C: HUMAN rollout

---

### Task 1: Pipeline tables and schema check

Done. The two tables are defined in the shared schema, which the `richmondsunlight.com` repository owns. This repository never creates or alters tables; it only checks that they exist.

**Files:**
- Modified in `richmondsunlight.com` (branch `video-pipeline-tables`): `deploy/mysql/structure.sql`, adding `video_jobs` and `video_sources`
- Modify: `deploy/mysql/structure.sql` (this repository's copy of that dump, kept identical), `tests/Support/TestDatabaseFactory.php`
- Create: `bin/check_schema.php`, `tests/Jobs/PipelineTablesTest.php`

**Interfaces:**
- Produces: tables `video_sources(source, source_id, file_id, date_created)` and `video_jobs(file_id, stage, status, attempts, claimed_at, next_attempt_at, last_error, date_modified)`; `TestDatabaseFactory::addPipelineTables(PDO $pdo): void`, which creates SQLite versions of both for tests.
- `bin/check_schema.php` exits 0 and prints `Pipeline tables are present.` as its last line when both tables exist. Otherwise it logs at level 7 and exits 1.

- [x] **Step 1: Create the branch `pipeline-improvements`**
- [x] **Step 2: Write `tests/Jobs/PipelineTablesTest.php` and watch it fail**
- [x] **Step 3: Add both tables to `deploy/mysql/structure.sql` in `richmondsunlight.com` and in this repository's copy**
- [x] **Step 4: Add `TestDatabaseFactory::addPipelineTables()` and call it from `create()`**
- [x] **Step 5: Create `bin/check_schema.php`**
- [x] **Step 6: Run the tests (3 pass) and lint**

---

### Task 2: Unique S3 keys

Two videos must never share a video key or a screenshot directory. The file ID goes into the key. `capture_directory` becomes write-once: nothing moves a screenshot directory after it is written.

**Files:**
- Modify: `src/Fetcher/S3KeyBuilder.php`, `src/Fetcher/VideoDownloadProcessor.php`, `src/Screenshots/ScreenshotGenerator.php`, `bin/process_uploads.php`, `bin/repair_committee_classification.php`
- Test: `tests/Fetcher/S3KeyBuilderTest.php`, `tests/Fetcher/VideoDownloadProcessorTest.php`, `tests/Screenshots/ScreenshotGeneratorTest.php`

**Interfaces:**
- Produces: `S3KeyBuilder::build(string $chamber, string $date, ?string $committeeShortname = null, ?int $fileId = null): string`. With a file ID the key ends `YYYYMMDD-<id>.mp4`.

- [x] **Step 1: Write the failing tests**

Add to `tests/Fetcher/S3KeyBuilderTest.php`, inside the class:

```php
    public function testAppendsFileIdWhenGiven(): void
    {
        $builder = new S3KeyBuilder();
        $this->assertSame(
            'house/floor/20260817-18797.mp4',
            $builder->build('House', '2026-08-17', null, 18797)
        );
        $this->assertSame(
            'house/committee/finance/20260817-18797.mp4',
            $builder->build('House', '2026-08-17', 'finance', 18797)
        );
    }

    public function testTwoVideosOnOneDayGetDifferentKeys(): void
    {
        $builder = new S3KeyBuilder();
        $this->assertNotSame(
            $builder->build('house', '2026-08-17', null, 18797),
            $builder->build('house', '2026-08-17', null, 18798)
        );
    }
```

Add to `tests/Screenshots/ScreenshotGeneratorTest.php`, inside the class:

```php
    /**
     * Several file rows can point at one legacy S3 object. Each must still get
     * its own screenshot directory, or one video's frames overwrite another's.
     */
    public function testScreenshotPrefixIsUniquePerFile(): void
    {
        $generator = $this->makeGenerator();
        $method = new \ReflectionMethod($generator, 'buildScreenshotPrefix');
        $method->setAccessible(true);

        $shared = 'https://video.richmondsunlight.com/house/floor/20260817.mp4';
        $first = new ScreenshotJob(18797, 'house', null, '2026-08-17', $shared, null, 'A');
        $second = new ScreenshotJob(18798, 'house', null, '2026-08-17', $shared, null, 'B');
        $this->assertSame('house/floor/20260817-18797', $method->invoke($generator, $first));
        $this->assertSame('house/floor/20260817-18798', $method->invoke($generator, $second));

        // A key that already carries the ID is not suffixed twice.
        $unique = new ScreenshotJob(
            18797,
            'house',
            null,
            '2026-08-17',
            'https://video.richmondsunlight.com/house/floor/20260817-18797.mp4',
            null,
            'A'
        );
        $this->assertSame('house/floor/20260817-18797', $method->invoke($generator, $unique));

        // A stale capture_directory value is ignored.
        $stale = new ScreenshotJob(18797, 'house', null, '2026-08-17', $shared, '/house/floor/20260817//', 'A');
        $this->assertSame('house/floor/20260817-18797', $method->invoke($generator, $stale));

        // A video that is not on S3 falls back to the key builder.
        $archived = new ScreenshotJob(5, 'senate', null, '2021-02-03', 'https://archive.org/download/x/y.mp4', null, 'C');
        $this->assertSame('senate/floor/20210203-5', $method->invoke($generator, $archived));
    }
```

In `tests/Fetcher/VideoDownloadProcessorTest.php` change two expected values:

- `'https://example.test/senate/floor/20250201.mp4'` becomes `'https://example.test/senate/floor/20250201-1.mp4'`
- `'https://example.test/house/committee/finance/20250305.mp4'` becomes `'https://example.test/house/committee/finance/20250305-2.mp4'`

- [x] **Step 2: Run them and confirm they fail**

Run: `./includes/vendor/bin/phpunit tests/Fetcher/S3KeyBuilderTest.php tests/Fetcher/VideoDownloadProcessorTest.php tests/Screenshots/ScreenshotGeneratorTest.php --display-warnings`
Expected: the new and changed assertions fail.

- [x] **Step 3: Change `S3KeyBuilder::build`**

In `src/Fetcher/S3KeyBuilder.php` replace the whole `build` method with:

```php
    /**
     * Pass the file ID wherever one is known. Without it, two videos from one
     * chamber, committee and day share a key and overwrite each other.
     */
    public function build(string $chamber, string $date, ?string $committeeShortname = null, ?int $fileId = null): string
    {
        $chamber = strtolower($chamber);
        $dateKey = str_replace('-', '', $date);
        $segment = 'floor';

        if ($committeeShortname !== null) {
            $slug = self::slugify($committeeShortname);
            if ($slug !== '') {
                $segment = 'committee/' . $slug;
            }
        }

        $name = $dateKey;
        if ($fileId !== null && $fileId > 0) {
            $name .= '-' . $fileId;
        }

        return sprintf('%s/%s/%s.mp4', $chamber, $segment, $name);
    }
```

- [x] **Step 4: Pass the file ID from the two callers that upload videos**

In `src/Fetcher/VideoDownloadProcessor.php`, in `buildS3Key`, replace
`return $this->keyBuilder->build($job->chamber, $job->date, $short);`
with
`return $this->keyBuilder->build($job->chamber, $job->date, $short, $job->id);`

In `bin/process_uploads.php` replace
`$finalKey  = $keyBuilder->build($chamber, $date, $shortname);`
with
`$finalKey  = $keyBuilder->build($chamber, $date, $shortname, $fileId);`

- [x] **Step 5: Make the screenshot prefix unique**

In `src/Screenshots/ScreenshotGenerator.php` replace the whole `buildScreenshotPrefix` method with:

```php
    /**
     * The directory screenshots are uploaded to. It always ends in the file ID,
     * because several file rows can point at one legacy S3 object and each
     * needs its own frames. The job's existing capture_directory is ignored:
     * reading it back as a prefix is what produced doubled slashes.
     */
    private function buildScreenshotPrefix(ScreenshotJob $job): string
    {
        $videoKey = $job->videoKey();
        if ($videoKey) {
            $prefix = (string) preg_replace('/\.mp4$/', '', trim($videoKey, '/'));
        } else {
            $shortname = $job->committeeId ? $this->committeeDirectory->getShortnameById($job->committeeId) : null;
            $prefix = (string) preg_replace('/\.mp4$/', '', $this->keyBuilder->build($job->chamber, $job->date, $shortname));
        }

        $suffix = '-' . $job->id;
        if (!str_ends_with($prefix, $suffix)) {
            $prefix .= $suffix;
        }

        return $prefix;
    }
```

- [x] **Step 6: Stop the repair script moving screenshot directories**

`bin/repair_committee_classification.php` must correct `committee_id` and `title` only. Make these edits:

1. Delete the line `use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;`.
2. Delete the line `$keyBuilder = new S3KeyBuilder();`.
3. Delete the two lines `// S3 bucket for file moves` and `const S3_BUCKET = 'video.richmondsunlight.com';`.
4. Replace the `$updateStmt = $pdo->prepare(...)` line with:
   ```php
   $updateStmt = $pdo->prepare('UPDATE files SET committee_id = :committee_id, title = :title WHERE id = :id');
   ```
5. In the `if ($isCommittee) {` branch delete the five lines from `// Build new capture directory` through `$newCaptureDir = '/' . trim($videoKey, '/') . '/';`.
6. In the `else` branch delete the four lines from `// Build new capture directory (floor session)` through `$newCaptureDir = '/' . trim($videoKey, '/') . '/';`.
7. Delete the line `$captureDirChanged = $currentCaptureDir !== $newCaptureDir;`.
8. Delete the whole block that begins `// Skip capture_directory changes if screenshots don't exist yet` and ends at its closing `}`.
9. Replace `if (!$committeeIdChanged && !$titleChanged && !$captureDirChanged) {` with `if (!$committeeIdChanged && !$titleChanged) {`.
10. Delete the whole block `if ($captureDirChanged) { echo sprintf("  Capture Dir: ...` (three lines).
11. Delete the whole block that begins `// Move files in S3 if capture directory changed` and ends at the closing `}` after `echo "  ✓ S3 files moved\n";`.
12. In the `$updateStmt->execute([...])` call delete the line `':capture_directory' => $newCaptureDir,`.
13. Delete the line `$currentCaptureDir = $file['capture_directory'];`.

Then confirm nothing references the removed names:

```bash
grep -n "captureDir\|CaptureDir\|keyBuilder\|S3_BUCKET\|aws s3" bin/repair_committee_classification.php
```

Expected: no output.

- [x] **Step 7: Run the tests and confirm they pass**

Run: `./includes/vendor/bin/phpunit tests/Fetcher tests/Screenshots tests/Integration --display-warnings`
Expected: all pass.

- [x] **Step 8: Lint**

```bash
php -l bin/repair_committee_classification.php && php -l bin/process_uploads.php
```

---

### Task 3: Import dedupe by source ID

**Files:**
- Create: `src/Sync/VideoSourceRegistry.php`, `src/Sync/VideoSourceBackfill.php`, `tests/Sync/VideoSourceRegistryTest.php`, `tests/Sync/VideoSourceBackfillTest.php`
- Modify: `src/Sync/VideoImporter.php`, `bin/check_schema.php`, `tests/Sync/VideoImporterTest.php`

**Interfaces:**
- Consumes: table `video_sources` (Task 1), `TestDatabaseFactory::addPipelineTables` (Task 1).
- Produces:
  - `VideoSourceRegistry::identityOf(array $record): ?array` returning `['source' => string, 'source_id' => string]` or null
  - `VideoSourceRegistry::findFileId(string $source, string $sourceId): ?int`
  - `VideoSourceRegistry::register(int $fileId, string $source, string $sourceId): bool`
  - `VideoSourceBackfill::run(): array` returning `['registered' => int, 'duplicates' => list of ['file_id' => int, 'duplicate_of' => int, 'source' => string, 'source_id' => string]]`

Scraped records carry these IDs: House `source: house` with `content_id`; Senate Granicus `source: senate` with `clip_id`; Senate YouTube `source: senate-youtube` with `youtube_id`.

- [x] **Step 1: Write the failing registry and backfill tests**

Create `tests/Sync/VideoSourceRegistryTest.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Tests\Sync;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Sync\VideoSourceRegistry;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class VideoSourceRegistryTest extends TestCase
{
    public function testIdentityOfEachScraperRecordShape(): void
    {
        $this->assertSame(
            ['source' => 'house', 'source_id' => '12345'],
            VideoSourceRegistry::identityOf(['source' => 'house', 'content_id' => 12345])
        );
        $this->assertSame(
            ['source' => 'senate', 'source_id' => '6789'],
            VideoSourceRegistry::identityOf(['source' => 'senate', 'clip_id' => '6789', 'video_id' => '6789'])
        );
        $this->assertSame(
            ['source' => 'senate-youtube', 'source_id' => 'dQw4w9WgXcQ'],
            VideoSourceRegistry::identityOf([
                'source' => 'senate-youtube',
                'video_id' => 'dQw4w9WgXcQ',
                'youtube_id' => 'dQw4w9WgXcQ',
            ])
        );
    }

    public function testIdentityIsNullWithoutSourceOrId(): void
    {
        $this->assertNull(VideoSourceRegistry::identityOf(['content_id' => 1]));
        $this->assertNull(VideoSourceRegistry::identityOf(['source' => 'house']));
        $this->assertNull(VideoSourceRegistry::identityOf(['source' => 'house', 'content_id' => '']));
    }

    public function testRegisterAndFind(): void
    {
        $pdo = new PDO('sqlite::memory:');
        TestDatabaseFactory::addPipelineTables($pdo);
        $registry = new VideoSourceRegistry($pdo);

        $this->assertNull($registry->findFileId('house', '1'));
        $this->assertTrue($registry->register(10, 'house', '1'));
        $this->assertSame(10, $registry->findFileId('house', '1'));

        // Registering the same pair again for the same file is fine.
        $this->assertTrue($registry->register(10, 'house', '1'));
        // A different file cannot take an ID that is already owned.
        $this->assertFalse($registry->register(11, 'house', '1'));
        $this->assertSame(10, $registry->findFileId('house', '1'));
    }
}
```

Create `tests/Sync/VideoSourceBackfillTest.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Tests\Sync;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Sync\VideoSourceBackfill;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class VideoSourceBackfillTest extends TestCase
{
    public function testRegistersOldestRowAndReportsLaterDuplicates(): void
    {
        $pdo = TestDatabaseFactory::create();
        $insert = $pdo->prepare(
            "INSERT INTO files (chamber, title, date, video_index_cache, date_created)
             VALUES ('house', 'T', '2026-08-17', :cache, '2026-08-17 00:00:00')"
        );
        $insert->execute([':cache' => json_encode(['source' => 'house', 'content_id' => 500])]);
        $insert->execute([':cache' => json_encode(['source' => 'house', 'content_id' => 500])]);
        $insert->execute([':cache' => json_encode(['source' => 'house', 'content_id' => 501])]);
        $insert->execute([':cache' => 'not json']);
        $insert->execute([':cache' => null]);

        $result = (new VideoSourceBackfill($pdo, 2))->run();

        $this->assertSame(2, $result['registered']);
        $this->assertSame(
            [['file_id' => 2, 'duplicate_of' => 1, 'source' => 'house', 'source_id' => '500']],
            $result['duplicates']
        );

        // Running again registers nothing new and reports the same duplicate.
        $again = (new VideoSourceBackfill($pdo, 2))->run();
        $this->assertSame(0, $again['registered']);
        $this->assertCount(1, $again['duplicates']);
    }
}
```

- [x] **Step 2: Run them and confirm they fail**

Run: `./includes/vendor/bin/phpunit tests/Sync/VideoSourceRegistryTest.php tests/Sync/VideoSourceBackfillTest.php --display-warnings`
Expected: errors that the classes do not exist.

- [x] **Step 3: Create `VideoSourceRegistry`**

Create `src/Sync/VideoSourceRegistry.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Sync;

use PDO;

/**
 * Maps a video's upstream identifier to its files row.
 *
 * A source URL changes once the video is copied to S3, and a duration differs
 * by a second between the listing and ffprobe. The upstream ID does neither,
 * so it is the only reliable answer to "have we imported this already?".
 */
class VideoSourceRegistry
{
    private const ID_KEYS = ['youtube_id', 'content_id', 'clip_id', 'video_id'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $record a scraped record, or a decoded video_index_cache
     * @return array{source:string,source_id:string}|null
     */
    public static function identityOf(array $record): ?array
    {
        $source = $record['source'] ?? null;
        if (!is_string($source) || $source === '') {
            return null;
        }

        foreach (self::ID_KEYS as $key) {
            $value = $record[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                return [
                    'source' => substr(strtolower($source), 0, 20),
                    'source_id' => substr((string) $value, 0, 64),
                ];
            }
        }

        return null;
    }

    public function findFileId(string $source, string $sourceId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT file_id FROM video_sources WHERE source = :source AND source_id = :source_id'
        );
        $stmt->execute([':source' => $source, ':source_id' => $sourceId]);
        $fileId = $stmt->fetchColumn();

        return $fileId === false ? null : (int) $fileId;
    }

    /**
     * @return bool false when the ID already belongs to a different file
     */
    public function register(int $fileId, string $source, string $sourceId): bool
    {
        $owner = $this->findFileId($source, $sourceId);
        if ($owner !== null) {
            return $owner === $fileId;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO video_sources (source, source_id, file_id, date_created)
             VALUES (:source, :source_id, :file_id, :created)'
        );
        $stmt->execute([
            ':source' => $source,
            ':source_id' => $sourceId,
            ':file_id' => $fileId,
            ':created' => gmdate('Y-m-d H:i:s'),
        ]);

        return true;
    }
}
```

- [x] **Step 4: Create `VideoSourceBackfill`**

Create `src/Sync/VideoSourceBackfill.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Sync;

use PDO;

/**
 * Registers source IDs for rows imported before video_sources existed.
 *
 * Rows are read oldest first, so the oldest row owns each ID. A later row
 * with the same ID is a duplicate import; it is reported and left alone.
 */
class VideoSourceBackfill
{
    public function __construct(private PDO $pdo, private int $batchSize = 100)
    {
    }

    /**
     * @return array{registered:int,duplicates:array<int,array{file_id:int,duplicate_of:int,source:string,source_id:string}>}
     */
    public function run(): array
    {
        $registry = new VideoSourceRegistry($this->pdo);
        $registered = 0;
        $duplicates = [];
        $lastId = 0;

        // video_index_cache can be large, so read in small batches by id.
        $stmt = $this->pdo->prepare(
            'SELECT id, video_index_cache FROM files
             WHERE id > :last AND video_index_cache IS NOT NULL
             ORDER BY id ASC LIMIT ' . max(1, $this->batchSize)
        );

        do {
            $stmt->execute([':last' => $lastId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $record = json_decode((string) $row['video_index_cache'], true);
                if (!is_array($record)) {
                    continue;
                }
                $identity = VideoSourceRegistry::identityOf($record);
                if ($identity === null) {
                    continue;
                }

                $owner = $registry->findFileId($identity['source'], $identity['source_id']);
                if ($owner === null) {
                    $registry->register($lastId, $identity['source'], $identity['source_id']);
                    $registered++;
                } elseif ($owner !== $lastId) {
                    $duplicates[] = [
                        'file_id' => $lastId,
                        'duplicate_of' => $owner,
                        'source' => $identity['source'],
                        'source_id' => $identity['source_id'],
                    ];
                }
            }
        } while (!empty($rows));

        return ['registered' => $registered, 'duplicates' => $duplicates];
    }
}
```

- [x] **Step 5: Run the registry and backfill tests and confirm they pass**

Run: `./includes/vendor/bin/phpunit tests/Sync/VideoSourceRegistryTest.php tests/Sync/VideoSourceBackfillTest.php --display-warnings`
Expected: 4 tests pass.

- [x] **Step 6: Write the failing importer tests**

In `tests/Sync/VideoImporterTest.php`:

1. Add `use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;` to the imports.
2. At the end of `setUp()` add `TestDatabaseFactory::addPipelineTables($this->pdo);`.
3. Add these tests inside the class:

```php
    public function testSameSourceIdIsNotReimportedAfterPathChanges(): void
    {
        $importer = new VideoImporter($this->pdo, new CommitteeDirectory($this->pdo));
        $record = [
            'source' => 'house',
            'content_id' => 555,
            'chamber' => 'house',
            'meeting_date' => '2026-08-17',
            'duration_seconds' => 3600,
            'committee_name' => 'Joint Subcommittee on Something Unlisted',
            'event_type' => 'committee',
            'video_url' => 'https://sg001-harmony.sliq.net/00304/Harmony/en/x.mp4',
        ];

        $this->assertSame(1, $importer->import([$record]));

        // What the download stage does: path becomes the S3 URL, length comes from ffprobe.
        $this->pdo->exec(
            "UPDATE files SET path = 'https://video.richmondsunlight.com/house/floor/20260817-1.mp4', length = '01:00:07'"
        );

        $this->assertSame(0, $importer->import([$record]));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testSecondMeetingOfOneCommitteeOnOneDayIsImported(): void
    {
        $importer = new VideoImporter($this->pdo, new CommitteeDirectory($this->pdo));
        $base = [
            'source' => 'house',
            'chamber' => 'house',
            'meeting_date' => '2026-02-10',
            'committee_name' => 'Appropriations',
            'event_type' => 'committee',
        ];

        $morning = $base + ['content_id' => 1, 'duration_seconds' => 3600, 'video_url' => 'https://example.com/1.mp4'];
        $evening = $base + ['content_id' => 2, 'duration_seconds' => 9000, 'video_url' => 'https://example.com/2.mp4'];

        $this->assertSame(2, $importer->import([$morning, $evening]));
    }

    public function testSecondFloorSessionOnOneDayIsImported(): void
    {
        $importer = new VideoImporter($this->pdo, new CommitteeDirectory($this->pdo));
        $base = ['source' => 'house', 'chamber' => 'house', 'meeting_date' => '2026-02-10', 'event_type' => 'floor'];

        $first = $base + ['content_id' => 3, 'duration_seconds' => 1800, 'video_url' => 'https://example.com/3.mp4'];
        $second = $base + ['content_id' => 4, 'duration_seconds' => 7200, 'video_url' => 'https://example.com/4.mp4'];

        $this->assertSame(2, $importer->import([$first, $second]));
    }

    public function testSameMeetingFromTwoSourcesIsImportedOnce(): void
    {
        $importer = new VideoImporter($this->pdo, new CommitteeDirectory($this->pdo));
        $base = [
            'chamber' => 'house',
            'meeting_date' => '2026-02-10',
            'committee_name' => 'Appropriations',
            'event_type' => 'committee',
        ];

        $one = $base + ['source' => 'house', 'content_id' => 7, 'duration_seconds' => 3600, 'video_url' => 'https://example.com/7.mp4'];
        $two = $base + ['source' => 'other', 'video_id' => 'abc', 'duration_seconds' => 3700, 'video_url' => 'https://example.com/abc.mp4'];

        $this->assertSame(1, $importer->import([$one]));
        $this->assertSame(0, $importer->import([$two]));

        // Both IDs now point at the one row, so neither is looked up by guesswork again.
        $owners = $this->pdo->query('SELECT file_id FROM video_sources ORDER BY source')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([1, 1], array_map('intval', $owners));
    }

    public function testOneBadRecordDoesNotAbortTheBatch(): void
    {
        $this->pdo->exec(
            "CREATE TRIGGER reject_boom BEFORE INSERT ON files WHEN NEW.description = 'BOOM'
             BEGIN SELECT RAISE(ABORT, 'boom'); END"
        );
        $importer = new VideoImporter($this->pdo, new CommitteeDirectory($this->pdo));
        $base = ['source' => 'house', 'chamber' => 'house', 'event_type' => 'floor', 'duration_seconds' => 600];

        $bad = $base + ['content_id' => 8, 'meeting_date' => '2026-03-01', 'description' => 'BOOM', 'video_url' => 'https://example.com/8.mp4'];
        $good = $base + ['content_id' => 9, 'meeting_date' => '2026-03-02', 'video_url' => 'https://example.com/9.mp4'];

        $this->assertSame(1, $importer->import([$bad, $good]));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }
```

- [x] **Step 7: Run them and confirm they fail**

Run: `./includes/vendor/bin/phpunit tests/Sync/VideoImporterTest.php --display-warnings`
Expected: the five new tests fail; the existing tests still pass.

- [x] **Step 8: Rewrite `VideoImporter::import`**

In `src/Sync/VideoImporter.php`:

1. Add this constant as the first line inside the class:

```php
    /** Two rows for one chamber, date and committee are the same meeting if their lengths are this close. */
    private const DUPLICATE_DURATION_TOLERANCE_SECONDS = 300;
```

2. Replace everything inside `import()` from the line `$count = 0;` to the end of the method (its final `return $count;` and closing brace) with:

```php
        $sources = new VideoSourceRegistry($this->pdo);
        $count = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($records as $record) {
            $payload = $this->buildPayload($record);
            if ($payload === null) {
                $this->logger?->put('Skipping record with insufficient metadata for insertion', 2);
                continue;
            }

            // The upstream ID is exact. Fall back to resemblance only when it is
            // unknown, and remember the match so the fallback is not needed again.
            $identity = VideoSourceRegistry::identityOf($record);
            $existingId = $identity ? $sources->findFileId($identity['source'], $identity['source_id']) : null;
            if ($existingId === null) {
                $existingId = $this->findLikelyDuplicate($payload, VideoRecordNormalizer::deriveDurationSeconds($record));
                if ($existingId !== null && $identity) {
                    $sources->register($existingId, $identity['source'], $identity['source_id']);
                }
            }

            if ($existingId !== null) {
                $skipped++;
                $this->logger?->put(
                    sprintf(
                        'Skipping duplicate of file #%d: %s %s %s',
                        $existingId,
                        $payload['chamber'],
                        $payload['date'],
                        $payload['title']
                    ),
                    1
                );
                continue;
            }

            // One bad record must not stop the rest of the batch from importing.
            try {
                if ($insert->execute($payload) === false) {
                    throw new RuntimeException('Failed to insert video record into files table.');
                }
                $fileId = (int) $this->pdo->lastInsertId();
                if ($identity) {
                    $sources->register($fileId, $identity['source'], $identity['source_id']);
                }
            } catch (\Throwable $e) {
                $failed++;
                // A statement that failed must be reset before it is executed again.
                $insert->closeCursor();
                $this->logger?->put(
                    sprintf(
                        'Insert failed (%s). Title length=%d Title sample=%s | Path length=%d Path sample=%s',
                        $e->getMessage(),
                        strlen((string) ($payload['title'] ?? '')),
                        substr((string) ($payload['title'] ?? ''), 0, 200),
                        strlen((string) ($payload['path'] ?? '')),
                        substr((string) ($payload['path'] ?? ''), 0, 200)
                    ),
                    6
                );
                continue;
            }

            $count++;
        }

        if ($skipped > 0) {
            $this->logger?->put(sprintf('Skipped %d duplicate record(s)', $skipped), 2);
        }
        if ($failed > 0) {
            $this->logger?->put(sprintf('Failed to import %d record(s)', $failed), 5);
        }

        return $count;
    }

    /**
     * Look for an existing row that is probably the same meeting, for records
     * whose upstream ID has not been seen. A committee can meet twice in a day
     * and the floor can sit twice, so chamber, date and committee alone are not
     * enough: the lengths must also be close, or one of them unknown.
     *
     * @param array<string,mixed> $payload
     */
    private function findLikelyDuplicate(array $payload, ?int $durationSeconds): ?int
    {
        if ($payload['committee_id'] === 0) {
            // Unresolved committee: nothing to compare but the source URL.
            $stmt = $this->pdo->prepare('SELECT id FROM files WHERE path = ? LIMIT 1');
            $stmt->execute([$payload['path']]);
            $id = $stmt->fetchColumn();
            return $id === false ? null : (int) $id;
        }

        if ($payload['committee_id'] === null) {
            $stmt = $this->pdo->prepare(
                'SELECT id, length FROM files WHERE chamber = ? AND date = ? AND title = ?
                 AND (committee_id IS NULL OR committee_id = \'\')'
            );
            $stmt->execute([$payload['chamber'], $payload['date'], $payload['title']]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id, length FROM files WHERE chamber = ? AND date = ? AND committee_id = ?'
            );
            $stmt->execute([$payload['chamber'], $payload['date'], $payload['committee_id']]);
        }

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $existingSeconds = $this->parseDuration($row['length'] ?? null);
            if (
                $durationSeconds === null
                || $existingSeconds === null
                || abs($existingSeconds - $durationSeconds) <= self::DUPLICATE_DURATION_TOLERANCE_SECONDS
            ) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    private function parseDuration(?string $length): ?int
    {
        if ($length === null || !preg_match('/^(\d+):(\d{2}):(\d{2})/', $length, $m)) {
            return null;
        }

        return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3];
    }
```

After this edit `import()` must still begin with the `if (empty($records))` guard and the `$insert = $this->pdo->prepare(...)` statement, unchanged.

- [x] **Step 9: Run the importer tests and confirm they pass**

Run: `./includes/vendor/bin/phpunit tests/Sync --display-warnings`
Expected: all pass, including the existing `testSkipsDuplicates`, `testDistinctUnresolvedCommitteesOnSameDayBothInsert` and `testSameUnresolvedCommitteeImportedTwiceIsDeduped`.

- [x] **Step 10: Backfill once from the schema check script**

In `bin/check_schema.php` add `use RichmondSunlight\VideoProcessor\Sync\VideoSourceBackfill;` below the existing `use` line. Then replace the final line `echo "Pipeline tables are present.\n";` with:

```php
// Register the source IDs of videos imported before video_sources existed.
// Runs once: after it, the table is never empty again.
if ((int) $pdo->query('SELECT COUNT(*) FROM video_sources')->fetchColumn() === 0) {
    $result = (new VideoSourceBackfill($pdo))->run();
    $log->put(
        sprintf(
            'Registered source IDs for %d existing video(s); %d row(s) duplicate an earlier one.',
            $result['registered'],
            count($result['duplicates'])
        ),
        3
    );
}

echo "Pipeline tables are present.\n";
```

- [x] **Step 11: Lint**

```bash
php -l bin/check_schema.php
```

---

### Task 4: JobLedger

**Files:**
- Create: `src/Jobs/JobLedger.php`, `tests/Jobs/JobLedgerTest.php`

**Interfaces:**
- Consumes: table `video_jobs` (Task 1).
- Produces, on `RichmondSunlight\VideoProcessor\Jobs\JobLedger`:
  - constants `STAGE_DOWNLOAD='download'`, `STAGE_SCREENSHOTS='screenshots'`, `STAGE_TRANSCRIPT='transcript'`, `STAGE_BILLS='bills'`, `STAGE_SPEAKERS='speakers'`, `STAGE_ARCHIVE='archive'`, `STAGES` (all six), `STATUS_RUNNING`, `STATUS_RETRY`, `STATUS_FAILED`, `STATUS_DONE`, `MAX_ATTEMPTS=3`, `RETRY_DELAY_HOURS=6`, `STALE_CLAIM_HOURS=3`
  - `__construct(PDO $pdo, ?\Closure $pdoFactory = null, ?\Closure $clock = null)`
  - `static eligibleSql(string $stage, string $fileIdColumn = 'f.id'): string`
  - `static eligibleParams(?int $now = null): array` with keys `:ledger_now`, `:ledger_stale`
  - `claim(int $fileId, string $stage): void`
  - `begin(int $fileId, string $stage): bool`
  - `recordSuccess(int $fileId, string $stage): void`
  - `recordFailure(int $fileId, string $stage, string $error): string` returning the new status
  - `markFailed(int $fileId, string $stage, string $error): void`
  - `reset(?int $fileId = null, ?string $stage = null, bool $failedOnly = false): int`
  - `failedJobs(): array`
  - `lastActivity(): ?int`

How the statuses behave:

| Status | Meaning | Hidden from its queue? |
|---|---|---|
| (no row) | Never attempted | No |
| `running` | Claimed by a worker | Yes, for 3 hours after `claimed_at` |
| `retry` | Failed, will be tried again | Yes, until `next_attempt_at` |
| `failed` | Given up after 3 attempts | Yes, until a person resets it |
| `done` | Succeeded | No; the queue's own SQL decides |

`claim` marks a job taken at fetch time. `begin` counts an attempt when work actually starts. An attempt is counted at the start so that a worker killed mid-job still uses one up.

- [x] **Step 1: Write the failing test**

Create `tests/Jobs/JobLedgerTest.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Tests\Jobs;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;

class JobLedgerTest extends TestCase
{
    private PDO $pdo;
    private int $now;
    private JobLedger $ledger;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE files (id INTEGER PRIMARY KEY)');
        $this->pdo->exec('INSERT INTO files (id) VALUES (1)');
        TestDatabaseFactory::addPipelineTables($this->pdo);

        $this->now = 1_700_000_000;
        $this->ledger = new JobLedger($this->pdo, null, fn(): int => $this->now);
    }

    private function eligible(string $stage = JobLedger::STAGE_DOWNLOAD): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files f WHERE f.id = 1 AND ' . JobLedger::eligibleSql($stage));
        foreach (JobLedger::eligibleParams($this->now) as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn() === 1;
    }

    private function row(string $stage = JobLedger::STAGE_DOWNLOAD): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_jobs WHERE file_id = 1 AND stage = :stage');
        $stmt->execute([':stage' => $stage]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function testUnknownJobIsEligible(): void
    {
        $this->assertTrue($this->eligible());
    }

    public function testClaimHidesJobUntilTheClaimGoesStale(): void
    {
        $this->ledger->claim(1, JobLedger::STAGE_DOWNLOAD);
        $this->assertFalse($this->eligible());
        $this->assertSame(0, (int) $this->row()['attempts'], 'Claiming is not an attempt.');

        $this->now += JobLedger::STALE_CLAIM_HOURS * 3600 + 1;
        $this->assertTrue($this->eligible(), 'A claim older than the stale window is released.');
    }

    public function testClaimOnOneStageDoesNotHideAnother(): void
    {
        $this->ledger->claim(1, JobLedger::STAGE_DOWNLOAD);
        $this->assertTrue($this->eligible(JobLedger::STAGE_TRANSCRIPT));
    }

    public function testFailuresBackOffThenGiveUp(): void
    {
        $this->assertTrue($this->ledger->begin(1, JobLedger::STAGE_DOWNLOAD));
        $this->assertSame(JobLedger::STATUS_RETRY, $this->ledger->recordFailure(1, JobLedger::STAGE_DOWNLOAD, 'first'));
        $this->assertFalse($this->eligible());

        $this->now += 6 * 3600 + 1;
        $this->assertTrue($this->eligible(), 'First retry is allowed after 6 hours.');

        $this->assertTrue($this->ledger->begin(1, JobLedger::STAGE_DOWNLOAD));
        $this->assertSame(JobLedger::STATUS_RETRY, $this->ledger->recordFailure(1, JobLedger::STAGE_DOWNLOAD, 'second'));
        $this->now += 6 * 3600 + 1;
        $this->assertFalse($this->eligible(), 'Second retry waits 12 hours.');
        $this->now += 6 * 3600;
        $this->assertTrue($this->eligible());

        $this->assertTrue($this->ledger->begin(1, JobLedger::STAGE_DOWNLOAD));
        $this->assertSame(JobLedger::STATUS_FAILED, $this->ledger->recordFailure(1, JobLedger::STAGE_DOWNLOAD, 'third'));

        $this->now += 365 * 86400;
        $this->assertFalse($this->eligible(), 'A given-up job stays hidden.');
        $this->assertSame('third', $this->row()['last_error']);
        $this->assertSame(3, (int) $this->row()['attempts']);
    }

    public function testJobInterruptedOnEveryAttemptIsGivenUpOn(): void
    {
        for ($i = 0; $i < JobLedger::MAX_ATTEMPTS; $i++) {
            $this->assertTrue($this->ledger->begin(1, JobLedger::STAGE_SCREENSHOTS));
            // The worker dies here: no success or failure is recorded.
            $this->now += JobLedger::STALE_CLAIM_HOURS * 3600 + 1;
            $this->assertTrue($this->eligible(JobLedger::STAGE_SCREENSHOTS));
        }

        $this->assertFalse($this->ledger->begin(1, JobLedger::STAGE_SCREENSHOTS));
        $this->assertSame(JobLedger::STATUS_FAILED, $this->row(JobLedger::STAGE_SCREENSHOTS)['status']);
        $this->assertFalse($this->eligible(JobLedger::STAGE_SCREENSHOTS));
    }

    public function testSuccessClearsAttemptsAndDoesNotHideTheJob(): void
    {
        $this->ledger->begin(1, JobLedger::STAGE_DOWNLOAD);
        $this->ledger->recordFailure(1, JobLedger::STAGE_DOWNLOAD, 'oops');
        $this->ledger->begin(1, JobLedger::STAGE_DOWNLOAD);
        $this->ledger->recordSuccess(1, JobLedger::STAGE_DOWNLOAD);

        $row = $this->row();
        $this->assertSame(JobLedger::STATUS_DONE, $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNull($row['last_error']);
        $this->assertTrue($this->eligible(), 'The queue SQL, not the ledger, decides whether done work is pending.');
    }

    public function testMarkFailedHidesImmediately(): void
    {
        $this->ledger->markFailed(1, JobLedger::STAGE_DOWNLOAD, 'no source URL');
        $this->assertFalse($this->eligible());
        $this->assertSame([['file_id' => 1, 'stage' => 'download']], array_map(
            fn(array $job) => ['file_id' => (int) $job['file_id'], 'stage' => $job['stage']],
            $this->ledger->failedJobs()
        ));
    }

    public function testResetMakesAFailedJobEligibleAgain(): void
    {
        $this->ledger->markFailed(1, JobLedger::STAGE_DOWNLOAD, 'x');
        $this->ledger->claim(1, JobLedger::STAGE_TRANSCRIPT);

        $this->assertSame(1, $this->ledger->reset(null, null, true), 'failedOnly leaves the running claim alone.');
        $this->assertTrue($this->eligible());
        $this->assertFalse($this->eligible(JobLedger::STAGE_TRANSCRIPT));

        $this->assertSame(1, $this->ledger->reset(1));
        $this->assertTrue($this->eligible(JobLedger::STAGE_TRANSCRIPT));
    }

    public function testErrorTextIsMadeSafeToStore(): void
    {
        $this->ledger->begin(1, JobLedger::STAGE_DOWNLOAD);
        $this->ledger->recordFailure(1, JobLedger::STAGE_DOWNLOAD, str_repeat('x', 5000) . "\xC3\x28");

        $stored = $this->row()['last_error'];
        $this->assertLessThanOrEqual(2000, mb_strlen($stored));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
    }

    public function testLastActivity(): void
    {
        $this->assertNull($this->ledger->lastActivity());
        $this->ledger->claim(1, JobLedger::STAGE_DOWNLOAD);
        $this->assertSame($this->now, $this->ledger->lastActivity());
    }

    public function testUnknownStageIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->claim(1, 'nonsense');
    }
}
```

- [x] **Step 2: Run it and confirm it fails**

Run: `./includes/vendor/bin/phpunit tests/Jobs/JobLedgerTest.php --display-warnings`
Expected: error that `JobLedger` does not exist.

- [x] **Step 3: Create `JobLedger`**

Create `src/Jobs/JobLedger.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Jobs;

use InvalidArgumentException;
use PDO;

/**
 * Per-video, per-stage job state in the video_jobs table: who has claimed a
 * job, how many attempts it has had, and why it last failed.
 *
 * Each *JobQueue class decides what work exists from the files table, as it
 * always has. The ledger only hides work that should not be handed out right
 * now: claimed by another worker, waiting out a retry delay, or given up on.
 *
 * All timestamps are UTC strings produced here, so they never depend on the
 * database server's clock or timezone.
 */
class JobLedger
{
    public const STAGE_DOWNLOAD = 'download';
    public const STAGE_SCREENSHOTS = 'screenshots';
    public const STAGE_TRANSCRIPT = 'transcript';
    public const STAGE_BILLS = 'bills';
    public const STAGE_SPEAKERS = 'speakers';
    public const STAGE_ARCHIVE = 'archive';

    public const STAGES = [
        self::STAGE_DOWNLOAD,
        self::STAGE_SCREENSHOTS,
        self::STAGE_TRANSCRIPT,
        self::STAGE_BILLS,
        self::STAGE_SPEAKERS,
        self::STAGE_ARCHIVE,
    ];

    public const STATUS_RUNNING = 'running';
    public const STATUS_RETRY = 'retry';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DONE = 'done';

    /** A job is given up on after this many attempts. */
    public const MAX_ATTEMPTS = 3;
    /** Wait this long after the first failure, twice this after the second. */
    public const RETRY_DELAY_HOURS = 6;
    /** A claim this old belongs to a worker that died; the job is handed out again. */
    public const STALE_CLAIM_HOURS = 3;

    private const MAX_ERROR_LENGTH = 2000;

    /** @var (\Closure(): PDO)|null */
    private ?\Closure $pdoFactory;

    /** @var (\Closure(): int)|null */
    private ?\Closure $clock;

    /**
     * @param (\Closure(): PDO)|null $pdoFactory supply this when writes happen after long
     *        work, when the original connection may have timed out
     * @param (\Closure(): int)|null $clock unix time source, for tests
     */
    public function __construct(private PDO $pdo, ?\Closure $pdoFactory = null, ?\Closure $clock = null)
    {
        $this->pdoFactory = $pdoFactory;
        $this->clock = $clock;
    }

    /**
     * SQL condition that is true when the ledger allows this file's job to be
     * handed out. Append it to a queue's WHERE clause, then bind
     * eligibleParams() on the statement.
     */
    public static function eligibleSql(string $stage, string $fileIdColumn = 'f.id'): string
    {
        self::assertStage($stage);

        return "NOT EXISTS (
            SELECT 1 FROM video_jobs vj
            WHERE vj.file_id = {$fileIdColumn} AND vj.stage = '{$stage}'
              AND (vj.status = 'failed'
                   OR (vj.status = 'retry' AND vj.next_attempt_at > :ledger_now)
                   OR (vj.status = 'running' AND vj.claimed_at > :ledger_stale))
        )";
    }

    /**
     * @return array<string,string>
     */
    public static function eligibleParams(?int $now = null): array
    {
        $now = $now ?? time();

        return [
            ':ledger_now' => self::format($now),
            ':ledger_stale' => self::format($now - self::STALE_CLAIM_HOURS * 3600),
        ];
    }

    /**
     * Mark a job as taken, at the moment a queue hands it out. Not an attempt.
     */
    public function claim(int $fileId, string $stage): void
    {
        self::assertStage($stage);
        $pdo = $this->connection();
        $row = $this->row($pdo, $fileId, $stage);

        $this->write(
            $pdo,
            $row !== null,
            $fileId,
            $stage,
            self::STATUS_RUNNING,
            (int) ($row['attempts'] ?? 0),
            self::format($this->now()),
            null,
            $row['last_error'] ?? null
        );
    }

    /**
     * Count an attempt, immediately before work starts. Counting here rather
     * than on failure means a worker killed mid-job still uses an attempt up.
     *
     * @return bool false when the job has no attempts left; it is then marked failed
     */
    public function begin(int $fileId, string $stage): bool
    {
        self::assertStage($stage);
        $pdo = $this->connection();
        $row = $this->row($pdo, $fileId, $stage);
        $attempts = (int) ($row['attempts'] ?? 0);

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->write(
                $pdo,
                true,
                $fileId,
                $stage,
                self::STATUS_FAILED,
                $attempts,
                null,
                null,
                $row['last_error'] ?? 'Interrupted before finishing on every attempt.'
            );
            return false;
        }

        $this->write(
            $pdo,
            $row !== null,
            $fileId,
            $stage,
            self::STATUS_RUNNING,
            $attempts + 1,
            self::format($this->now()),
            null,
            $row['last_error'] ?? null
        );

        return true;
    }

    public function recordSuccess(int $fileId, string $stage): void
    {
        self::assertStage($stage);
        $pdo = $this->connection();
        $row = $this->row($pdo, $fileId, $stage);

        $this->write($pdo, $row !== null, $fileId, $stage, self::STATUS_DONE, 0, null, null, null);
    }

    /**
     * @return string the job's new status: STATUS_RETRY or STATUS_FAILED
     */
    public function recordFailure(int $fileId, string $stage, string $error): string
    {
        self::assertStage($stage);
        $pdo = $this->connection();
        $row = $this->row($pdo, $fileId, $stage);
        $attempts = max(1, (int) ($row['attempts'] ?? 0));

        if ($attempts >= self::MAX_ATTEMPTS) {
            $status = self::STATUS_FAILED;
            $nextAttemptAt = null;
        } else {
            $status = self::STATUS_RETRY;
            $nextAttemptAt = self::format($this->now() + self::RETRY_DELAY_HOURS * 3600 * $attempts);
        }

        $this->write($pdo, $row !== null, $fileId, $stage, $status, $attempts, null, $nextAttemptAt, self::cleanError($error));

        return $status;
    }

    /**
     * Give up on a job at once, for a problem no retry can fix.
     */
    public function markFailed(int $fileId, string $stage, string $error): void
    {
        self::assertStage($stage);
        $pdo = $this->connection();
        $row = $this->row($pdo, $fileId, $stage);

        $this->write(
            $pdo,
            $row !== null,
            $fileId,
            $stage,
            self::STATUS_FAILED,
            (int) ($row['attempts'] ?? 0),
            null,
            null,
            self::cleanError($error)
        );
    }

    /**
     * Forget ledger rows so their jobs are handed out again.
     *
     * @return int number of rows removed
     */
    public function reset(?int $fileId = null, ?string $stage = null, bool $failedOnly = false): int
    {
        $sql = 'DELETE FROM video_jobs WHERE 1 = 1';
        $params = [];
        if ($fileId !== null) {
            $sql .= ' AND file_id = :file_id';
            $params[':file_id'] = $fileId;
        }
        if ($stage !== null) {
            self::assertStage($stage);
            $sql .= ' AND stage = :stage';
            $params[':stage'] = $stage;
        }
        if ($failedOnly) {
            $sql .= " AND status = 'failed'";
        }

        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * @return array<int,array{file_id:int|string,stage:string,attempts:int|string,last_error:?string,date_modified:string}>
     */
    public function failedJobs(): array
    {
        $stmt = $this->connection()->query(
            "SELECT file_id, stage, attempts, last_error, date_modified
             FROM video_jobs WHERE status = 'failed'
             ORDER BY date_modified DESC, file_id DESC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Unix time of the most recent ledger write, or null if there has never been one.
     */
    public function lastActivity(): ?int
    {
        $latest = $this->connection()->query('SELECT MAX(date_modified) FROM video_jobs')->fetchColumn();
        if ($latest === false || $latest === null) {
            return null;
        }
        $timestamp = strtotime($latest . ' UTC');

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function row(PDO $pdo, int $fileId, string $stage): ?array
    {
        $stmt = $pdo->prepare('SELECT attempts, last_error FROM video_jobs WHERE file_id = :file_id AND stage = :stage');
        $stmt->execute([':file_id' => $fileId, ':stage' => $stage]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function write(
        PDO $pdo,
        bool $exists,
        int $fileId,
        string $stage,
        string $status,
        int $attempts,
        ?string $claimedAt,
        ?string $nextAttemptAt,
        ?string $lastError
    ): void {
        $sql = $exists
            ? 'UPDATE video_jobs
               SET status = :status, attempts = :attempts, claimed_at = :claimed_at,
                   next_attempt_at = :next_attempt_at, last_error = :last_error, date_modified = :date_modified
               WHERE file_id = :file_id AND stage = :stage'
            : 'INSERT INTO video_jobs
                   (file_id, stage, status, attempts, claimed_at, next_attempt_at, last_error, date_modified)
               VALUES
                   (:file_id, :stage, :status, :attempts, :claimed_at, :next_attempt_at, :last_error, :date_modified)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':file_id' => $fileId,
            ':stage' => $stage,
            ':status' => $status,
            ':attempts' => $attempts,
            ':claimed_at' => $claimedAt,
            ':next_attempt_at' => $nextAttemptAt,
            ':last_error' => $lastError,
            ':date_modified' => self::format($this->now()),
        ]);
    }

    private function connection(): PDO
    {
        return $this->pdoFactory ? ($this->pdoFactory)() : $this->pdo;
    }

    private function now(): int
    {
        return $this->clock ? ($this->clock)() : time();
    }

    private static function format(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private static function assertStage(string $stage): void
    {
        if (!in_array($stage, self::STAGES, true)) {
            throw new InvalidArgumentException('Unknown pipeline stage: ' . $stage);
        }
    }

    private static function cleanError(string $error): string
    {
        // Command output can contain bytes that are not valid UTF-8.
        $error = mb_convert_encoding($error, 'UTF-8', 'UTF-8');

        return mb_substr($error, 0, self::MAX_ERROR_LENGTH);
    }
}
```

- [x] **Step 4: Run the test and confirm it passes**

Run: `./includes/vendor/bin/phpunit tests/Jobs/JobLedgerTest.php --display-warnings`
Expected: 11 tests pass.

---

### Task 5: StageRunner

**Files:**
- Create: `src/Jobs/StageRunner.php`, `tests/Jobs/StageRunnerTest.php`

**Interfaces:**
- Consumes: `JobLedger` (Task 4).
- Produces: `StageRunner::__construct(JobLedger $ledger, string $stage, ?\Log $log = null, int $maxConsecutiveFailures = 3)` and `run(int $limit, callable $fetchOne, callable $fileIdOf, callable $process): int`.
  - `$fetchOne(): ?object` returns the next job, or null when there is none.
  - `$fileIdOf(object $job): int` returns the job's file ID.
  - `$process(object $job): mixed` does the work. It signals failure by throwing, or by returning exactly `false`. Any other return value, including null, is success.
  - `run` returns the number of jobs that succeeded.

- [x] **Step 1: Write the failing test**

Create `tests/Jobs/StageRunnerTest.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Tests\Jobs;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;
use RichmondSunlight\VideoProcessor\Tests\Support\TestLogger;
use RuntimeException;

class StageRunnerTest extends TestCase
{
    private PDO $pdo;
    private JobLedger $ledger;
    private TestLogger $log;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        TestDatabaseFactory::addPipelineTables($this->pdo);
        $this->ledger = new JobLedger($this->pdo);
        $this->log = new TestLogger();
    }

    /**
     * @param int[] $ids
     * @return callable(): ?object
     */
    private function jobs(array $ids): callable
    {
        return function () use (&$ids): ?object {
            $id = array_shift($ids);
            return $id === null ? null : (object) ['id' => $id];
        };
    }

    private function jobStatus(int $fileId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT status FROM video_jobs WHERE file_id = :id AND stage = 'download'");
        $stmt->execute([':id' => $fileId]);
        $status = $stmt->fetchColumn();
        return $status === false ? null : $status;
    }

    private function runner(): StageRunner
    {
        return new StageRunner($this->ledger, JobLedger::STAGE_DOWNLOAD, $this->log);
    }

    public function testRecordsSuccessesAndStopsWhenTheQueueIsEmpty(): void
    {
        $seen = [];
        $count = $this->runner()->run(
            10,
            $this->jobs([1, 2]),
            fn(object $job): int => $job->id,
            function (object $job) use (&$seen): void {
                $seen[] = $job->id;
            }
        );

        $this->assertSame(2, $count);
        $this->assertSame([1, 2], $seen);
        $this->assertSame(JobLedger::STATUS_DONE, $this->jobStatus(1));
        $this->assertSame(JobLedger::STATUS_DONE, $this->jobStatus(2));
    }

    public function testRespectsTheLimit(): void
    {
        $count = $this->runner()->run(1, $this->jobs([1, 2]), fn(object $job): int => $job->id, fn() => null);
        $this->assertSame(1, $count);
        $this->assertNull($this->jobStatus(2));
    }

    public function testAnExceptionIsAFailureAndTheRunContinues(): void
    {
        $count = $this->runner()->run(
            10,
            $this->jobs([1, 2]),
            fn(object $job): int => $job->id,
            function (object $job): void {
                if ($job->id === 1) {
                    throw new RuntimeException('download exploded');
                }
            }
        );

        $this->assertSame(1, $count);
        $this->assertSame(JobLedger::STATUS_RETRY, $this->jobStatus(1));
        $this->assertSame(JobLedger::STATUS_DONE, $this->jobStatus(2));

        $messages = array_column($this->log->entries, 'message');
        $this->assertContains('Download failed for file #1 (will retry later): download exploded', $messages);
    }

    public function testReturningFalseIsAFailure(): void
    {
        $count = $this->runner()->run(10, $this->jobs([1]), fn(object $job): int => $job->id, fn() => false);

        $this->assertSame(0, $count);
        $this->assertSame(JobLedger::STATUS_RETRY, $this->jobStatus(1));
    }

    /**
     * If S3 or the database is down, every job fails. Stop early so an outage
     * costs three jobs one attempt each, not the whole backlog.
     */
    public function testStopsAfterThreeConsecutiveFailures(): void
    {
        $attempted = [];
        $count = $this->runner()->run(
            10,
            $this->jobs([1, 2, 3, 4, 5]),
            fn(object $job): int => $job->id,
            function (object $job) use (&$attempted): void {
                $attempted[] = $job->id;
                throw new RuntimeException('S3 is down');
            }
        );

        $this->assertSame(0, $count);
        $this->assertSame([1, 2, 3], $attempted);
        $this->assertNull($this->jobStatus(4));
    }

    public function testASuccessResetsTheConsecutiveFailureCount(): void
    {
        $attempted = [];
        $this->runner()->run(
            10,
            $this->jobs([1, 2, 3, 4, 5]),
            fn(object $job): int => $job->id,
            function (object $job) use (&$attempted): void {
                $attempted[] = $job->id;
                if ($job->id !== 3) {
                    throw new RuntimeException('bad video');
                }
            }
        );

        $this->assertSame([1, 2, 3, 4, 5], $attempted);
    }

    public function testSkipsAJobThatHasNoAttemptsLeft(): void
    {
        for ($i = 0; $i < JobLedger::MAX_ATTEMPTS; $i++) {
            $this->ledger->begin(1, JobLedger::STAGE_DOWNLOAD);
        }

        $attempted = [];
        $count = $this->runner()->run(
            10,
            $this->jobs([1, 2]),
            fn(object $job): int => $job->id,
            function (object $job) use (&$attempted): void {
                $attempted[] = $job->id;
            }
        );

        $this->assertSame(1, $count);
        $this->assertSame([2], $attempted);
        $this->assertSame(JobLedger::STATUS_FAILED, $this->jobStatus(1));
    }
}
```

- [x] **Step 2: Run it and confirm it fails**

Run: `./includes/vendor/bin/phpunit tests/Jobs/StageRunnerTest.php --display-warnings`
Expected: error that `StageRunner` does not exist.

- [x] **Step 3: Create `StageRunner`**

Create `src/Jobs/StageRunner.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Jobs;

use Log;
use Throwable;

/**
 * The worker loop shared by every pipeline stage: take one job, count the
 * attempt, do the work, record the outcome, repeat.
 *
 * Jobs are taken one at a time, so a worker that dies holds one claim rather
 * than a whole batch.
 */
class StageRunner
{
    public function __construct(
        private JobLedger $ledger,
        private string $stage,
        private ?Log $log = null,
        private int $maxConsecutiveFailures = 3
    ) {
    }

    /**
     * @param callable(): ?object $fetchOne the next job, or null when none is left
     * @param callable(object): int $fileIdOf the job's file ID
     * @param callable(object): mixed $process does the work; throw, or return exactly
     *        false, to report a failure
     * @return int number of jobs that succeeded
     */
    public function run(int $limit, callable $fetchOne, callable $fileIdOf, callable $process): int
    {
        $succeeded = 0;
        $consecutiveFailures = 0;

        for ($i = 0; $i < $limit; $i++) {
            $job = $fetchOne();
            if ($job === null) {
                break;
            }
            $fileId = (int) $fileIdOf($job);

            if (!$this->ledger->begin($fileId, $this->stage)) {
                $this->log?->put(
                    sprintf(
                        'Giving up on %s for file #%d: interrupted %d times without finishing.',
                        $this->stage,
                        $fileId,
                        JobLedger::MAX_ATTEMPTS
                    ),
                    6
                );
                continue;
            }

            $error = null;
            try {
                if ($process($job) === false) {
                    $error = 'The stage finished without a usable result; see earlier log lines for this file.';
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            if ($error === null) {
                $this->ledger->recordSuccess($fileId, $this->stage);
                $succeeded++;
                $consecutiveFailures = 0;
                continue;
            }

            $givingUp = $this->ledger->recordFailure($fileId, $this->stage, $error) === JobLedger::STATUS_FAILED;
            $this->log?->put(
                sprintf(
                    '%s failed for file #%d (%s): %s',
                    ucfirst($this->stage),
                    $fileId,
                    $givingUp ? 'giving up' : 'will retry later',
                    $error
                ),
                $givingUp ? 6 : 5
            );

            // Several failures in a row usually mean an outage, not bad videos.
            // Stop, so an outage does not spend an attempt on every pending job.
            $consecutiveFailures++;
            if ($consecutiveFailures >= $this->maxConsecutiveFailures) {
                $this->log?->put(
                    sprintf('Stopping %s after %d consecutive failures.', $this->stage, $consecutiveFailures),
                    6
                );
                break;
            }
        }

        return $succeeded;
    }
}
```

- [x] **Step 4: Run the test and confirm it passes**

Run: `./includes/vendor/bin/phpunit tests/Jobs/StageRunnerTest.php --display-warnings`
Expected: 7 tests pass.

---

### Task 6: Queues honour the ledger

Every queue gains three things: the ledger clause in its `WHERE`, a `countPending()` method that uses the same `WHERE`, and (for download, transcript and archive, which have no claim today) a ledger claim on every job it hands out.

**Files:**
- Modify: `src/Fetcher/VideoDownloadQueue.php`, `src/Screenshots/ScreenshotJobQueue.php`, `src/Transcripts/TranscriptJobQueue.php`, `src/Analysis/Bills/BillDetectionJobQueue.php`, `src/Analysis/Speakers/SpeakerJobQueue.php`, `src/Archive/ArchiveJobQueue.php`
- Modify (add the tables to hand-built schemas): `tests/Fetcher/VideoDownloadQueueTest.php`, `tests/Screenshots/ScreenshotJobQueueTest.php`, `tests/Transcripts/TranscriptJobQueueTest.php`, `tests/Analysis/Bills/BillDetectionJobQueueTest.php`, `tests/Analysis/Speakers/SpeakerJobQueueTest.php`, `tests/Archive/ArchiveJobQueueTest.php`
- Modify (it fetches an archive job twice): `tests/Integration/PipelineEndToEndTest.php`
- Create: `tests/Jobs/QueueLedgerTest.php`

**Interfaces:**
- Consumes: `JobLedger::eligibleSql`, `JobLedger::eligibleParams`, `JobLedger::claim`, `JobLedger::markFailed` (Task 4).
- Produces: `countPending(): int` on all six queue classes. `fetch(int $limit): array` keeps its signature.

- [x] **Step 1: Write the failing test**

Create `tests/Jobs/QueueLedgerTest.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Tests\Jobs;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJobQueue;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadQueue;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJobQueue;
use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobQueue;

class QueueLedgerTest extends TestCase
{
    private const S3 = 'https://video.richmondsunlight.com/house/floor/20250101-1.mp4';
    private const SHOTS = '/house/floor/20250101-1/';

    /**
     * @return array<string,array{0:string,1:\Closure,2:array<string,mixed>}>
     */
    public static function queues(): array
    {
        $cache = json_encode(['video_url' => 'https://example.test/a.mp4']);

        return [
            'download' => [
                JobLedger::STAGE_DOWNLOAD,
                fn(PDO $pdo) => new VideoDownloadQueue($pdo),
                ['path' => '', 'video_index_cache' => $cache],
            ],
            'screenshots' => [
                JobLedger::STAGE_SCREENSHOTS,
                fn(PDO $pdo) => new ScreenshotJobQueue($pdo),
                ['path' => self::S3],
            ],
            'transcript' => [
                JobLedger::STAGE_TRANSCRIPT,
                fn(PDO $pdo) => new TranscriptJobQueue($pdo),
                ['path' => self::S3],
            ],
            'bills' => [
                JobLedger::STAGE_BILLS,
                fn(PDO $pdo) => new BillDetectionJobQueue($pdo),
                ['capture_directory' => self::SHOTS],
            ],
            'speakers' => [
                JobLedger::STAGE_SPEAKERS,
                fn(PDO $pdo) => new SpeakerJobQueue($pdo),
                ['path' => self::S3],
            ],
            'archive' => [
                JobLedger::STAGE_ARCHIVE,
                fn(PDO $pdo) => new ArchiveJobQueue($pdo),
                ['path' => self::S3, 'webvtt' => 'WEBVTT', 'capture_directory' => self::SHOTS, 'transcript' => 'text'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function seed(PDO $pdo, array $overrides, string $date = '2025-01-01'): int
    {
        $row = array_merge([
            'chamber' => 'house',
            'title' => 'Test',
            'date' => $date,
            'path' => null,
            'html' => null,
            'webvtt' => null,
            'transcript' => null,
            'capture_directory' => null,
            'capture_rate' => null,
            'video_index_cache' => null,
            'date_created' => '2025-01-01 00:00:00',
        ], $overrides);

        $columns = array_keys($row);
        $stmt = $pdo->prepare(sprintf(
            'INSERT INTO files (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(fn(string $c) => ':' . $c, $columns))
        ));
        $stmt->execute(array_combine(array_map(fn(string $c) => ':' . $c, $columns), array_values($row)));

        return (int) $pdo->lastInsertId();
    }

    /**
     * @dataProvider queues
     */
    public function testGivenUpJobIsNeitherFetchedNorCounted(string $stage, \Closure $makeQueue, array $row): void
    {
        $pdo = TestDatabaseFactory::create();
        $fileId = $this->seed($pdo, $row);
        $queue = $makeQueue($pdo);

        $this->assertSame(1, $queue->countPending());

        (new JobLedger($pdo))->markFailed($fileId, $stage, 'test');

        $this->assertSame(0, $queue->countPending());
        $this->assertSame([], $queue->fetch(5));
    }

    /**
     * @dataProvider queues
     */
    public function testJobWaitingToRetryIsNeitherFetchedNorCounted(string $stage, \Closure $makeQueue, array $row): void
    {
        $pdo = TestDatabaseFactory::create();
        $fileId = $this->seed($pdo, $row);
        $queue = $makeQueue($pdo);

        $ledger = new JobLedger($pdo);
        $ledger->begin($fileId, $stage);
        $ledger->recordFailure($fileId, $stage, 'transient');

        $this->assertSame(0, $queue->countPending());
        $this->assertSame([], $queue->fetch(5));
    }

    /**
     * @dataProvider queues
     */
    public function testJobClaimedByAnotherWorkerIsNotFetched(string $stage, \Closure $makeQueue, array $row): void
    {
        $pdo = TestDatabaseFactory::create();
        $fileId = $this->seed($pdo, $row);

        (new JobLedger($pdo))->claim($fileId, $stage);

        $this->assertSame([], $makeQueue($pdo)->fetch(5));
    }

    /**
     * These three stages had no claim of their own: two workers starting
     * together could both take the same video.
     *
     * @dataProvider queues
     */
    public function testFetchClaimsWhatItReturns(string $stage, \Closure $makeQueue, array $row): void
    {
        if (!in_array($stage, [JobLedger::STAGE_DOWNLOAD, JobLedger::STAGE_TRANSCRIPT, JobLedger::STAGE_ARCHIVE], true)) {
            $this->markTestSkipped('This stage claims through the files or video_index tables, on MariaDB only.');
        }

        $pdo = TestDatabaseFactory::create();
        $this->seed($pdo, $row);

        $this->assertCount(1, $makeQueue($pdo)->fetch(5));
        $this->assertSame([], $makeQueue($pdo)->fetch(5), 'A second worker must not receive the same video.');
        $this->assertSame(0, $makeQueue($pdo)->countPending());
    }

    /**
     * A row the download queue cannot use must not sit at the head of the
     * queue forever, hiding the usable rows behind it.
     */
    public function testUnusableDownloadRowIsGivenUpOnAndSkipped(): void
    {
        $pdo = TestDatabaseFactory::create();
        $bad = $this->seed($pdo, ['path' => '', 'video_index_cache' => '{"title": "no url here"}'], '2025-06-01');
        $good = $this->seed($pdo, [
            'path' => '',
            'video_index_cache' => json_encode(['video_url' => 'https://example.test/good.mp4']),
        ], '2025-01-01');

        $jobs = (new VideoDownloadQueue($pdo))->fetch(1);

        $this->assertCount(1, $jobs);
        $this->assertSame($good, $jobs[0]->id);

        $failed = (new JobLedger($pdo))->failedJobs();
        $this->assertCount(1, $failed);
        $this->assertSame($bad, (int) $failed[0]['file_id']);
    }
}
```

- [x] **Step 2: Run it and confirm it fails**

Run: `./includes/vendor/bin/phpunit tests/Jobs/QueueLedgerTest.php --display-warnings`
Expected: errors that `countPending` is undefined.

- [x] **Step 3: Replace `VideoDownloadQueue`**

Replace the whole of `src/Fetcher/VideoDownloadQueue.php` with:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use PDO;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;

class VideoDownloadQueue
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * Videos with scraped metadata that are not yet on S3 or archive.org.
     * YouTube sources are excluded: they are staged by hand with
     * scripts/fetch_youtube_uploads.sh. The ledger clause hides videos another
     * worker has claimed, that are waiting to be retried, or that were given up on.
     */
    private function pendingWhere(): string
    {
        return "(f.path IS NULL OR f.path = '' OR (
                f.path NOT LIKE 'https://video.richmondsunlight.com/%'
                AND f.path NOT LIKE 'https://archive.org/%'
            ))
            AND (f.html IS NULL OR f.html = '')
            AND f.video_index_cache IS NOT NULL
            AND f.video_index_cache LIKE '{%'
            AND f.video_index_cache NOT LIKE '%youtube.com%'
            AND f.video_index_cache NOT LIKE '%youtu.be%'
            AND " . JobLedger::eligibleSql(JobLedger::STAGE_DOWNLOAD);
    }

    public function countPending(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files f WHERE ' . $this->pendingWhere());
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return VideoDownloadJob[]
     */
    public function fetch(int $limit = 5): array
    {
        $sql = 'SELECT f.id, f.chamber, f.committee_id, f.title, f.date, f.path, f.video_index_cache
            FROM files f
            WHERE ' . $this->pendingWhere() . '
            ORDER BY f.date DESC
            LIMIT :limit';

        // Row locks keep two workers from reading the same rows at the same
        // moment. Only supported on MySQL/PostgreSQL, not SQLite (used in tests).
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $locking = in_array($driver, ['mysql', 'pgsql'], true);
        if ($locking) {
            $sql .= ' FOR UPDATE SKIP LOCKED';
            $this->pdo->beginTransaction();
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        $ledger = new JobLedger($this->pdo);
        $jobs = [];
        $unusable = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $metadata = json_decode($row['video_index_cache'], true);
            $remote = is_array($metadata) ? ($metadata['video_url'] ?? $row['path'] ?? null) : null;

            // A row with nothing to download would otherwise be selected again
            // on every pass, ahead of the rows that can be downloaded.
            if (!$remote || preg_match('#https?://(www\.)?(youtube\.com|youtu\.be)/#', $remote)) {
                $ledger->markFailed(
                    (int) $row['id'],
                    JobLedger::STAGE_DOWNLOAD,
                    'No downloadable video_url in video_index_cache.'
                );
                $unusable++;
                continue;
            }

            $jobs[] = new VideoDownloadJob(
                (int) $row['id'],
                (string) $row['chamber'],
                isset($row['committee_id']) ? (int) $row['committee_id'] : null,
                (string) $row['date'],
                $remote,
                $metadata,
                $row['title'] ?? null
            );
        }

        // The lock above lasts only until commit. The claim is what keeps
        // other workers away while this one downloads.
        foreach ($jobs as $job) {
            $ledger->claim($job->id, JobLedger::STAGE_DOWNLOAD);
        }

        if ($locking) {
            $this->pdo->commit();
        }

        // Every row read was unusable and is now hidden; look again for usable ones.
        if (empty($jobs) && $unusable > 0) {
            return $this->fetch($limit);
        }

        return $jobs;
    }
}
```

- [x] **Step 4: Replace `ScreenshotJobQueue`**

Replace the whole of `src/Screenshots/ScreenshotJobQueue.php` with:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Screenshots;

use PDO;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;

class ScreenshotJobQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    private function pendingWhere(): string
    {
        return "f.path LIKE 'https://video.richmondsunlight.com/%'
            AND (f.capture_directory IS NULL OR f.capture_directory = ''
                 OR (f.capture_directory NOT LIKE '/%' AND f.capture_directory NOT LIKE 'https://%')
                 OR f.capture_rate IS NULL)
            AND " . JobLedger::eligibleSql(JobLedger::STAGE_SCREENSHOTS);
    }

    public function countPending(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files f WHERE ' . $this->pendingWhere());
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return ScreenshotJob[]
     */
    public function fetch(int $limit = 5): array
    {
        $sql = 'SELECT f.id, f.chamber, f.committee_id, f.title, f.date, f.path, f.capture_directory
            FROM files f
            WHERE ' . $this->pendingWhere() . '
            ORDER BY f.date DESC
            LIMIT :limit';

        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $sql .= ' FOR UPDATE SKIP LOCKED';
            $this->pdo->beginTransaction();
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        $jobs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $jobs[] = new ScreenshotJob(
                (int) $row['id'],
                (string) $row['chamber'],
                $row['committee_id'] !== null ? (int) $row['committee_id'] : null,
                (string) $row['date'],
                (string) $row['path'],
                $row['capture_directory'] ?? null,
                $row['title'] ?? null
            );
        }

        // Mark fetched rows as claimed before releasing locks, so other
        // workers' SKIP LOCKED queries won't pick up the same rows.
        if (!empty($jobs) && in_array($driver, ['mysql', 'pgsql'], true)) {
            $ids = array_map(fn(ScreenshotJob $j) => $j->id, $jobs);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $this->pdo->prepare(
                "UPDATE files SET capture_rate = 0, capture_directory = '/pending', date_modified = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})"
            );
            $update->execute($ids);
        }

        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->pdo->commit();
        }

        return $jobs;
    }
}
```

- [x] **Step 5: Replace `TranscriptJobQueue`**

Replace the whole of `src/Transcripts/TranscriptJobQueue.php` with:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Transcripts;

use PDO;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;

class TranscriptJobQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    private function pendingWhere(): string
    {
        return "f.path LIKE 'https://video.richmondsunlight.com/%'
            AND NOT EXISTS (SELECT 1 FROM video_transcript vt WHERE vt.file_id = f.id)
            AND " . JobLedger::eligibleSql(JobLedger::STAGE_TRANSCRIPT);
    }

    public function countPending(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files f WHERE ' . $this->pendingWhere());
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return TranscriptJob[]
     */
    public function fetch(int $limit = 5): array
    {
        $sql = 'SELECT f.id, f.chamber, f.path, f.webvtt, f.srt, f.title
            FROM files f
            WHERE ' . $this->pendingWhere() . '
            ORDER BY f.date DESC
            LIMIT :limit';

        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $locking = in_array($driver, ['mysql', 'pgsql'], true);
        if ($locking) {
            $sql .= ' FOR UPDATE SKIP LOCKED';
            $this->pdo->beginTransaction();
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        $jobs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $jobs[] = new TranscriptJob(
                (int) $row['id'],
                (string) $row['chamber'],
                (string) $row['path'],
                $row['webvtt'] ?? null,
                $row['srt'] ?? null,
                $row['title'] ?? null
            );
        }

        // The lock lasts only until commit. The claim keeps other workers from
        // transcribing (and paying for) the same video.
        $ledger = new JobLedger($this->pdo);
        foreach ($jobs as $job) {
            $ledger->claim($job->fileId, JobLedger::STAGE_TRANSCRIPT);
        }

        if ($locking) {
            $this->pdo->commit();
        }

        return $jobs;
    }
}
```

- [x] **Step 6: Replace `ArchiveJobQueue`**

Replace the whole of `src/Archive/ArchiveJobQueue.php` with:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Archive;

use PDO;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;

class ArchiveJobQueue
{
    public function __construct(private PDO $pdo)
    {
    }

    private function pendingWhere(): string
    {
        return "f.path LIKE 'https://video.richmondsunlight.com/%'
            AND (f.webvtt IS NOT NULL OR f.srt IS NOT NULL)
            AND f.capture_directory IS NOT NULL AND f.capture_directory != ''
            AND f.transcript IS NOT NULL
            AND " . JobLedger::eligibleSql(JobLedger::STAGE_ARCHIVE);
    }

    public function countPending(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files f WHERE ' . $this->pendingWhere());
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return ArchiveJob[]
     */
    public function fetch(int $limit = 3): array
    {
        $sql = 'SELECT f.id, f.chamber, f.title, f.date, f.path, f.webvtt, f.srt,
                       f.capture_directory, f.video_index_cache, f.committee_id, c.name as committee_name
            FROM files f
            LEFT JOIN committees c ON f.committee_id = c.id
            WHERE ' . $this->pendingWhere() . '
            ORDER BY f.date DESC
            LIMIT :limit';

        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $locking = in_array($driver, ['mysql', 'pgsql'], true);
        if ($locking) {
            $sql .= ' FOR UPDATE SKIP LOCKED';
            $this->pdo->beginTransaction();
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        $jobs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $jobs[] = new ArchiveJob(
                (int) $row['id'],
                (string) $row['chamber'],
                (string) $row['title'],
                (string) $row['date'],
                (string) $row['path'],
                $row['webvtt'] ?? null,
                $row['srt'] ?? null,
                $row['capture_directory'] ?? null,
                $row['video_index_cache'] ?? null,
                $row['committee_id'] !== null ? (int) $row['committee_id'] : null,
                $row['committee_name'] ?? null
            );
        }

        // The lock lasts only until commit. The claim keeps two workers from
        // uploading the same video to the Internet Archive.
        $ledger = new JobLedger($this->pdo);
        foreach ($jobs as $job) {
            $ledger->claim($job->fileId, JobLedger::STAGE_ARCHIVE);
        }

        if ($locking) {
            $this->pdo->commit();
        }

        return $jobs;
    }
}
```

- [x] **Step 7: Edit `BillDetectionJobQueue`**

In `src/Analysis/Bills/BillDetectionJobQueue.php`:

1. Add `use RichmondSunlight\VideoProcessor\Jobs\JobLedger;` below `use PDO;`.
2. Add these two methods directly above `fetch()`:

```php
    private function pendingWhere(): string
    {
        return "f.capture_directory IS NOT NULL AND f.capture_directory != ''
            AND (f.capture_directory LIKE '/%' OR f.capture_directory LIKE 'https://%')
            AND f.capture_directory != '/pending'
            AND f.date >= '2020-01-01'
            AND NOT EXISTS (
                SELECT 1 FROM video_index vi
                WHERE vi.file_id = f.id AND vi.type = 'bill'
            )
            AND " . JobLedger::eligibleSql(JobLedger::STAGE_BILLS);
    }

    public function countPending(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files f WHERE ' . $this->pendingWhere());
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
```

3. Inside `fetch()`, replace the whole `$sql = "SELECT f.id, ... LIMIT :limit";` statement (from `$sql = "SELECT` through `LIMIT :limit";`) with:

```php
        $sql = 'SELECT f.id, f.chamber, f.committee_id, f.capture_directory, f.video_index_cache, f.date
            FROM files f
            WHERE ' . $this->pendingWhere() . '
            ORDER BY f.date DESC
            LIMIT :limit';
```

4. Directly after the line `$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);` add:

```php
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
```

- [x] **Step 8: Edit `SpeakerJobQueue`**

In `src/Analysis/Speakers/SpeakerJobQueue.php`:

1. Add `use RichmondSunlight\VideoProcessor\Jobs\JobLedger;` below `use PDO;`.
2. Add these two methods directly above `fetch()`:

```php
    private function pendingWhere(): string
    {
        return "(f.path LIKE 'https://video.richmondsunlight.com/%'
              OR f.path LIKE 'https://archive.org/%')
            AND f.date >= '2020-01-01'
            AND (f.capture_directory IS NULL OR f.capture_directory != '/pending')
            AND NOT EXISTS (
                SELECT 1 FROM video_index vi
                WHERE vi.file_id = f.id AND vi.type = 'legislator'
            )
            AND " . JobLedger::eligibleSql(JobLedger::STAGE_SPEAKERS);
    }

    public function countPending(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files f WHERE ' . $this->pendingWhere());
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
```

3. Inside `fetch()`, replace the whole `$sql = "SELECT f.id, ... LIMIT :limit";` statement with:

```php
        $sql = 'SELECT f.id, f.chamber, f.path, f.capture_directory, f.video_index_cache, f.date
            FROM files f
            WHERE ' . $this->pendingWhere() . '
            ORDER BY f.date DESC
            LIMIT :limit';
```

4. Directly after the line `$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);` add:

```php
        foreach (JobLedger::eligibleParams() as $name => $value) {
            $stmt->bindValue($name, $value);
        }
```

- [x] **Step 9: Give the existing queue tests the new tables**

Each of these six test files builds its own SQLite schema. In each, add `use RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory;` to the imports, and add the line `TestDatabaseFactory::addPipelineTables($pdo);` immediately after **every** `CREATE TABLE files` statement in the file. Use `$this->pdo` where the file uses a property.

- `tests/Fetcher/VideoDownloadQueueTest.php` (in `setUp`, use `$this->pdo`)
- `tests/Screenshots/ScreenshotJobQueueTest.php`
- `tests/Transcripts/TranscriptJobQueueTest.php`
- `tests/Analysis/Bills/BillDetectionJobQueueTest.php` (there are two test methods; do both)
- `tests/Analysis/Speakers/SpeakerJobQueueTest.php`
- `tests/Archive/ArchiveJobQueueTest.php` (in `createTestDatabase`)

One more test needs a change. `tests/Integration/PipelineEndToEndTest.php` fetches an archive job from the queue itself and then lets `ArchiveJobProcessor` fetch it again. The first fetch now claims the job, so the second finds nothing. In that file:

1. Add `use RichmondSunlight\VideoProcessor\Jobs\JobLedger;` to the imports.
2. Replace the line `        $archiveProcessor->run(1);` with:

```php
        // The fetch above claimed the job. Release it so the processor can fetch it itself.
        (new JobLedger($this->pdo))->reset(1, JobLedger::STAGE_ARCHIVE);
        $archiveProcessor->run(1);
```

- [x] **Step 10: Run the whole suite**

Run: `./includes/vendor/bin/phpunit --display-warnings`
Expected: `tests/Jobs/QueueLedgerTest.php` passes (3 of its cases report as skipped by design), and nothing else fails apart from the known baseline failure. If any test fails with `no such table: video_jobs`, that test builds its own schema and uses a queue: add `TestDatabaseFactory::addPipelineTables(...)` to it as in Step 9.

---

### Task 7: Processors report their outcome

`StageRunner` can only record a failure it is told about. Bill and speaker detection currently log a problem and return normally. Archiving has no single-job entry point.

**Files:**
- Modify: `src/Analysis/Bills/BillDetectionProcessor.php`, `src/Analysis/Speakers/SpeakerDetectionProcessor.php`, `src/Archive/ArchiveJobProcessor.php`
- Test: `tests/Analysis/Bills/BillDetectionProcessorTest.php`, `tests/Analysis/Speakers/SpeakerDetectionProcessorTest.php`, `tests/Archive/ArchiveJobProcessorTest.php`

**Interfaces:**
- Produces:
  - `BillDetectionProcessor::process(BillDetectionJob $job): bool`
  - `SpeakerDetectionProcessor::process(SpeakerJob $job): bool`
  - In both, `true` means the stage finished (results or a none-found sentinel were written) and `false` means it could not finish and should be retried.
  - `ArchiveJobProcessor::processJob(ArchiveJob $job): void`, which throws on failure.

- [x] **Step 1: Make the existing tests assert the return value**

In `tests/Analysis/Bills/BillDetectionProcessorTest.php`, replace the line `$processor->process($job);` as follows:

| Test method | Replace with |
|---|---|
| `testProcessesManifest` | `$this->assertTrue($processor->process($job));` |
| `testRecordsNoneFoundSentinelWhenNoBillsDetected` | `$this->assertTrue($processor->process($job));` |
| `testKeepsPartialResultsWhenSomeScreenshotsFail` | `$this->assertTrue($processor->process($job));` |
| `testLeavesClaimWhenAllScreenshotsFailWithNoBills` | `$this->assertFalse($processor->process($job));` |
| `testLeavesClaimPlaceholderWhenManifestMissing` | `$this->assertFalse($processor->process($job));` |

In `tests/Analysis/Speakers/SpeakerDetectionProcessorTest.php`:

| Test method | Replace with |
|---|---|
| `testUsesMetadataBeforeDiarizer` | `$this->assertTrue($processor->process($job));` |
| `testRecordsNoneFoundSentinelWhenCleanlyEmpty` | `$this->assertTrue($processor->process($job));` |
| `testLeavesClaimWhenDiarizationFails` | `$this->assertFalse($processor->process($job));` |

Add this test to `tests/Archive/ArchiveJobProcessorTest.php`, inside the class:

```php
    public function testProcessJobThrowsWhenUploadReturnsNothing(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec("INSERT INTO files (id, path) VALUES (1, 'https://video.richmondsunlight.com/house/floor/file.mp4')");

        $job = new ArchiveJob(
            1,
            'house',
            'Floor Session',
            '2025-01-01',
            'https://video.richmondsunlight.com/house/floor/file.mp4',
            'WEBVTT',
            null,
            null,
            null
        );

        $uploader = new class extends InternetArchiveUploader {
            public function __construct()
            {
                parent::__construct(null);
            }

            public function upload(ArchiveJob $job, array $metadata): ?string
            {
                return null;
            }
        };

        $processor = new ArchiveJobProcessor(
            $this->queue([]),
            new MetadataBuilder(),
            $uploader,
            $pdo,
            new ArchiveJobProcessorTestLogger()
        );

        try {
            $processor->processJob($job);
            $this->fail('processJob must throw when the upload fails.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Internet Archive', $e->getMessage());
        }

        $this->assertSame(
            'https://video.richmondsunlight.com/house/floor/file.mp4',
            $pdo->query('SELECT path FROM files WHERE id = 1')->fetchColumn(),
            'A failed upload must not change the path.'
        );
    }
```

- [x] **Step 2: Run them and confirm they fail**

Run: `./includes/vendor/bin/phpunit tests/Analysis/Bills/BillDetectionProcessorTest.php tests/Analysis/Speakers/SpeakerDetectionProcessorTest.php tests/Archive/ArchiveJobProcessorTest.php --display-warnings`
Expected: the changed assertions fail because `process()` returns null; the new archive test errors because `processJob` is undefined.

- [x] **Step 3: Edit `BillDetectionProcessor::process`**

In `src/Analysis/Bills/BillDetectionProcessor.php`:

1. Change the signature `public function process(BillDetectionJob $job): void` to `public function process(BillDetectionJob $job): bool`.
2. Add this docblock directly above that signature:

```php
    /**
     * @return bool true when detection finished (bills or a none-found sentinel
     *         were written); false when it could not finish and should be retried
     */
```

3. There are six `return` points. Change each one:

| The line directly above the `return;` contains | Change `return;` to |
|---|---|
| `'No manifest available for file #'` | `return false;` |
| `'Manifest load failed for file #'` | `return false;` |
| `'No crop configuration for chamber '` | `return false;` |
| the closing `);` of the `'Bill detection incomplete for file #'` log call | `return false;` |
| `'No bills detected for file #'` | `return true;` |

4. The method ends with a `$this->logger?->put('Finished bill detection for file #' ...);` call followed by the method's closing brace. Add `return true;` on a new line between that call and the closing brace.

- [x] **Step 4: Edit `SpeakerDetectionProcessor::process`**

In `src/Analysis/Speakers/SpeakerDetectionProcessor.php`:

1. Change the signature `public function process(SpeakerJob $job): void` to `public function process(SpeakerJob $job): bool`.
2. Add this docblock directly above that signature:

```php
    /**
     * @return bool true when detection finished (speakers or a none-found
     *         sentinel were written); false when it could not finish and should be retried
     */
```

3. Change the `return` points:

| The line directly above the `return;` contains | Change `return;` to |
|---|---|
| `'Speaker extraction errored for file #'` | `return false;` |
| `$this->writer->recordNoneFound($job->fileId);` | `return true;` |

4. The method ends with `$this->logger?->put('Stored speaker data for file #' . $job->fileId, 3);`. Add `return true;` on a new line after it, before the closing brace.

- [x] **Step 5: Edit `ArchiveJobProcessor`**

In `src/Archive/ArchiveJobProcessor.php` replace the whole `run` method with these two methods:

```php
    /**
     * Upload one video and point its files row at the Internet Archive.
     *
     * @throws \RuntimeException when the upload does not produce a URL
     */
    public function processJob(ArchiveJob $job): void
    {
        $metadata = $this->metadataBuilder->build($job);
        $url = $this->uploader->upload($job, $metadata);
        if (!$url) {
            throw new \RuntimeException('The Internet Archive upload did not return a URL; see the log.');
        }

        // Get a fresh connection — uploads take minutes (video download,
        // ia upload, metadata polling with 90s sleeps) and the original
        // connection will have timed out.
        $pdo = $this->pdoFactory ? ($this->pdoFactory)() : $this->pdo;
        $stmt = $pdo->prepare('UPDATE files SET path = :path WHERE id = :id');
        $stmt->execute([':path' => $url, ':id' => $job->fileId]);
        $this->logger->put('Uploaded file #' . $job->fileId . ' to ' . $url, 3);
    }

    public function run(int $limit = 2): void
    {
        $jobs = $this->queue->fetch($limit);
        if (empty($jobs)) {
            $this->logger->put('No files pending Internet Archive upload.', 3);
            return;
        }

        foreach ($jobs as $job) {
            try {
                $this->processJob($job);
            } catch (Throwable $e) {
                $this->logger->put('IA upload failed for file #' . $job->fileId . ': ' . $e->getMessage(), 5);
            }
        }
    }
```

- [x] **Step 6: Run the tests and confirm they pass**

Run: `./includes/vendor/bin/phpunit tests/Analysis tests/Archive tests/Integration --display-warnings`
Expected: all pass apart from the known baseline failure.

---

### Task 8: Worker scripts use StageRunner

Each of the six worker scripts is replaced in full. The pattern is the same in all six: build a `StageRunner`, give it a closure that fetches one job on a fresh database connection, and a closure that processes one job.

A fresh connection for every fetch matters. A job can run for 40 minutes, and the connection that fetched it will have timed out before the next fetch.

**Files:**
- Modify: `src/Bootstrap/AppBootstrap.php`, `bin/fetch_videos.php`, `bin/generate_screenshots.php`, `bin/generate_transcripts.php`, `bin/detect_bills.php`, `bin/detect_speakers.php`, `bin/upload_archive.php`
- Test: `tests/Jobs/WorkerScriptsTest.php`

**Interfaces:**
- Consumes: `StageRunner` (Task 5), `JobLedger` (Task 4), `countPending`/`fetch` on the queues (Task 6), bool-returning processors and `ArchiveJobProcessor::processJob` (Task 7).
- Produces: `AppBootstrap::connectionFactory(): \Closure` returning `\Closure(): \PDO`. It throws `RuntimeException` when no connection can be made.

- [x] **Step 1: Write the failing test**

These scripts need a real database and AWS, so the test checks only that each one parses and is wired to the shared loop. Create `tests/Jobs/WorkerScriptsTest.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Tests\Jobs;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;

class WorkerScriptsTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function scripts(): array
    {
        return [
            'download' => ['fetch_videos.php', 'JobLedger::STAGE_DOWNLOAD'],
            'screenshots' => ['generate_screenshots.php', 'JobLedger::STAGE_SCREENSHOTS'],
            'transcript' => ['generate_transcripts.php', 'JobLedger::STAGE_TRANSCRIPT'],
            'bills' => ['detect_bills.php', 'JobLedger::STAGE_BILLS'],
            'speakers' => ['detect_speakers.php', 'JobLedger::STAGE_SPEAKERS'],
            'archive' => ['upload_archive.php', 'JobLedger::STAGE_ARCHIVE'],
        ];
    }

    /**
     * @dataProvider scripts
     */
    public function testScriptUsesTheSharedWorkerLoop(string $script, string $stage): void
    {
        $path = __DIR__ . '/../../bin/' . $script;
        $source = file_get_contents($path);

        $this->assertStringContainsString('new StageRunner(', $source);
        $this->assertStringContainsString($stage, $source);
        $this->assertStringContainsString('->fetch(1)', $source, 'Jobs must be claimed one at a time.');
        $this->assertStringContainsString('AppBootstrap::connectionFactory()', $source);

        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }

    public function testConnectionFactoryIsAClosure(): void
    {
        $this->assertInstanceOf(\Closure::class, AppBootstrap::connectionFactory());
    }
}
```

- [x] **Step 2: Run it and confirm it fails**

Run: `./includes/vendor/bin/phpunit tests/Jobs/WorkerScriptsTest.php --display-warnings`
Expected: 7 failures.

- [x] **Step 3: Add `AppBootstrap::connectionFactory`**

In `src/Bootstrap/AppBootstrap.php` add this method directly after `createFreshConnection()`:

```php
    /**
     * A closure that opens a new connection each time it is called, for work
     * that outlasts the database's idle timeout.
     *
     * @return \Closure(): \PDO
     */
    public static function connectionFactory(): \Closure
    {
        return static function (): \PDO {
            $pdo = self::createFreshConnection();
            if (!$pdo instanceof \PDO) {
                throw new RuntimeException('Unable to connect to database.');
            }
            return $pdo;
        };
    }
```

- [x] **Step 4: Replace `bin/fetch_videos.php`**

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\S3Storage;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadJob;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadProcessor;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadQueue;
use RichmondSunlight\VideoProcessor\Fetcher\VideoMetadataExtractor;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

$limit = 20;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (is_numeric($arg)) {
        $limit = (int) $arg;
    }
}

$s3Client = S3ClientFactory::create(AWS_ACCESS_KEY, AWS_SECRET_KEY, AWS_REGION);
$storage = new S3Storage($s3Client, 'video.richmondsunlight.com');

$pdoFactory = AppBootstrap::connectionFactory();
$runner = new StageRunner(new JobLedger($app->pdo, $pdoFactory), JobLedger::STAGE_DOWNLOAD, $log);

$downloaded = $runner->run(
    $limit,
    static fn() => (new VideoDownloadQueue($pdoFactory()))->fetch(1)[0] ?? null,
    static fn(VideoDownloadJob $job): int => $job->id,
    static function (VideoDownloadJob $job) use ($pdoFactory, $storage, $log): void {
        $pdo = $pdoFactory();
        $processor = new VideoDownloadProcessor(
            $pdo,
            $storage,
            new CommitteeDirectory($pdo),
            new VideoMetadataExtractor(),
            new S3KeyBuilder(),
            null,
            $log
        );
        $processor->process($job);
    }
);

$log->put("Downloaded {$downloaded} video(s).", 3);
```

- [x] **Step 5: Replace `bin/generate_screenshots.php`**

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\S3Storage;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotGenerator;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJob;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJobQueue;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

$options = getopt('', ['limit::', 'enqueue']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 3;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--')) {
        continue;
    }
    if (is_numeric($arg)) {
        $limit = (int) $arg;
        break;
    }
}

$s3Client = S3ClientFactory::create(AWS_ACCESS_KEY, AWS_SECRET_KEY, AWS_REGION);
$storage = new S3Storage($s3Client, 'video.richmondsunlight.com');
$keyBuilder = new S3KeyBuilder();

$pdoFactory = AppBootstrap::connectionFactory();
$runner = new StageRunner(new JobLedger($app->pdo, $pdoFactory), JobLedger::STAGE_SCREENSHOTS, $log);

$processed = $runner->run(
    $limit,
    static fn() => (new ScreenshotJobQueue($pdoFactory()))->fetch(1)[0] ?? null,
    static fn(ScreenshotJob $job): int => $job->id,
    // A failed job keeps its '/pending' claim on the files row; StaleClaimCleaner
    // releases it, and the ledger decides when the job may be tried again.
    static function (ScreenshotJob $job) use ($pdoFactory, $storage, $keyBuilder, $log): void {
        $pdo = $pdoFactory();
        $generator = new ScreenshotGenerator(
            $pdo,
            $storage,
            new CommitteeDirectory($pdo),
            $keyBuilder,
            $log,
            null,
            null,
            $pdoFactory
        );
        $generator->process($job);
    }
);

$log->put("Processed {$processed} screenshot job(s).", 3);
```

- [x] **Step 6: Replace `bin/generate_transcripts.php`**

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Contract\ContractValidator;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;
use RichmondSunlight\VideoProcessor\Transcripts\CaptionParser;
use RichmondSunlight\VideoProcessor\Transcripts\OpenAITranscriber;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJob;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobQueue;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptProcessor;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

if (!defined('OPENAI_KEY') || OPENAI_KEY === '') {
    fwrite(STDERR, "OPENAI_KEY is not defined.\n");
    exit(1);
}

$options = getopt('', ['limit::', 'enqueue']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 3;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--')) {
        continue;
    }
    if (is_numeric($arg)) {
        $limit = (int) $arg;
        break;
    }
}

$pdoFactory = AppBootstrap::connectionFactory();
$writer = new TranscriptWriter($app->pdo, $pdoFactory);
$transcriber = new OpenAITranscriber(new Client(['timeout' => 1800]), OPENAI_KEY);
$processor = new TranscriptProcessor($writer, $transcriber, new CaptionParser(), null, $log);

$runner = new StageRunner(new JobLedger($app->pdo, $pdoFactory), JobLedger::STAGE_TRANSCRIPT, $log);

$processed = $runner->run(
    $limit,
    static fn() => (new TranscriptJobQueue($pdoFactory()))->fetch(1)[0] ?? null,
    static fn(TranscriptJob $job): int => $job->fileId,
    static function (TranscriptJob $job) use ($processor, $pdoFactory, $log): void {
        $processor->process($job);

        $issues = (new ContractValidator($pdoFactory()))->validateFile($job->fileId);
        foreach ($issues as $issue) {
            if ($issue['level'] !== 'error' && $issue['level'] !== 'warning') {
                continue;
            }
            $log->put(
                sprintf('Contract %s for file #%d: %s', strtoupper($issue['level']), $job->fileId, $issue['message']),
                $issue['level'] === 'error' ? 5 : 2
            );
        }
    }
);

$log->put("Generated {$processed} transcript(s).", 3);
```

- [x] **Step 7: Replace `bin/detect_bills.php`**

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Analysis\Bills\AgendaExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJob;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillParser;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillResultWriter;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillTextExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ChamberConfig;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;
use RichmondSunlight\VideoProcessor\Analysis\Bills\TesseractOcrEngine;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

$options = getopt('', ['limit::', 'enqueue']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 2;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--')) {
        continue;
    }
    if (is_numeric($arg)) {
        $limit = (int) $arg;
        break;
    }
}

$pdoFactory = AppBootstrap::connectionFactory();

$processor = new BillDetectionProcessor(
    new ScreenshotManifestLoader(),
    new ScreenshotFetcher(),
    new BillTextExtractor(new TesseractOcrEngine()),
    new BillParser(),
    new BillResultWriter($app->pdo, $pdoFactory),
    new ChamberConfig(),
    new AgendaExtractor(),
    $log
);

$runner = new StageRunner(new JobLedger($app->pdo, $pdoFactory), JobLedger::STAGE_BILLS, $log);

$processed = $runner->run(
    $limit,
    static fn() => (new BillDetectionJobQueue($pdoFactory()))->fetch(1)[0] ?? null,
    static fn(BillDetectionJob $job): int => $job->fileId,
    // process() returns false when it could not finish; StageRunner counts that as a failure.
    static fn(BillDetectionJob $job): bool => $processor->process($job)
);

$log->put("Finished bill detection for {$processed} file(s).", 3);
```

- [x] **Step 8: Replace `bin/detect_speakers.php`**

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

use Aws\TranscribeService\TranscribeServiceClient;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotFetcher;
use RichmondSunlight\VideoProcessor\Analysis\Bills\ScreenshotManifestLoader;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\AwsTranscribeDiarizer;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\LegislatorDirectory;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\OcrSpeakerExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerChamberConfig;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerDetectionProcessor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJob;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerMetadataExtractor;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerNameOcrEngine;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerNameParser;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerResultWriter;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerTextExtractor;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Fetcher\S3ClientFactory;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

$options = getopt('', ['limit::', 'enqueue']);
$limit = isset($options['limit']) ? (int) $options['limit'] : 3;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--')) {
        continue;
    }
    if (is_numeric($arg)) {
        $limit = (int) $arg;
        break;
    }
}

$pdoFactory = AppBootstrap::connectionFactory();

$minSegmentSeconds = getenv('SPEAKER_OCR_MIN_SEGMENT_SECONDS');
$minSegmentSeconds = is_numeric($minSegmentSeconds) ? (int) $minSegmentSeconds : 3;

$s3Client = S3ClientFactory::create(AWS_ACCESS_KEY, AWS_SECRET_KEY, AWS_REGION);
$transcribeClient = new TranscribeServiceClient([
    'credentials' => [
        'key' => AWS_ACCESS_KEY,
        'secret' => AWS_SECRET_KEY,
    ],
    'region' => AWS_REGION,
    'version' => '2017-10-26',
]);

$diarizer = new AwsTranscribeDiarizer($transcribeClient, $s3Client, 'video.richmondsunlight.com', new AudioExtractor());
$ocrExtractor = new OcrSpeakerExtractor(
    new ScreenshotManifestLoader(),
    new ScreenshotFetcher(),
    new SpeakerTextExtractor(new SpeakerNameOcrEngine()),
    new SpeakerNameParser(),
    new SpeakerChamberConfig(),
    max(1, $minSegmentSeconds),
    $log
);

$processor = new SpeakerDetectionProcessor(
    new SpeakerMetadataExtractor(),
    $diarizer,
    $ocrExtractor,
    new LegislatorDirectory($app->pdo),
    new SpeakerResultWriter($app->pdo, $pdoFactory),
    $log
);

$runner = new StageRunner(new JobLedger($app->pdo, $pdoFactory), JobLedger::STAGE_SPEAKERS, $log);

$processed = $runner->run(
    $limit,
    static fn() => (new SpeakerJobQueue($pdoFactory()))->fetch(1)[0] ?? null,
    static fn(SpeakerJob $job): int => $job->fileId,
    // process() returns false when it could not finish; StageRunner counts that as a failure.
    static fn(SpeakerJob $job): bool => $processor->process($job)
);

$log->put("Finished speaker detection for {$processed} file(s).", 3);
```

- [x] **Step 9: Replace `bin/upload_archive.php`**

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Archive\ArchiveJob;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobProcessor;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;
use RichmondSunlight\VideoProcessor\Archive\InternetArchiveUploader;
use RichmondSunlight\VideoProcessor\Archive\MetadataBuilder;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\StageRunner;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;

$limit = 10;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (is_numeric($arg)) {
        $limit = (int) $arg;
    }
}

// Without credentials every upload fails. Stop here, so a missing config file
// does not use up the attempts of every video waiting to be archived.
$iaConfig = getenv('HOME') . '/.config/internetarchive/ia.ini';
if (!file_exists($iaConfig)) {
    $log->put('Internet Archive configuration missing at ' . $iaConfig . '. Run `ia configure`.', 6);
    exit(1);
}

$pdoFactory = AppBootstrap::connectionFactory();
$processor = new ArchiveJobProcessor(
    new ArchiveJobQueue($app->pdo),
    new MetadataBuilder(),
    new InternetArchiveUploader($log),
    $app->pdo,
    $log,
    $pdoFactory
);

$runner = new StageRunner(new JobLedger($app->pdo, $pdoFactory), JobLedger::STAGE_ARCHIVE, $log);

$uploaded = $runner->run(
    $limit,
    static fn() => (new ArchiveJobQueue($pdoFactory()))->fetch(1)[0] ?? null,
    static fn(ArchiveJob $job): int => $job->fileId,
    static function (ArchiveJob $job) use ($processor): void {
        $processor->processJob($job);
    }
);

$log->put("Uploaded {$uploaded} video(s) to the Internet Archive.", 3);

// After uploads complete, check once for any videos that processed quickly.
// Videos that take longer are caught at the start of the next session.
require_once __DIR__ . '/repair_archive_urls_helper.php';

$log->put('Checking for Archive.org videos that finished processing...', 3);
$pendingCount = 0;
$repairCount = repairArchiveUrls($pdoFactory(), $log, $pendingCount);

if ($repairCount > 0) {
    $log->put("Resolved $repairCount Archive.org URL(s) that finished processing quickly", 3);
}

if ($pendingCount > 0) {
    $log->put(sprintf('%d video(s) still processing on Archive.org. These will be resolved at the start of the next processing session.', $pendingCount), 3);
}
```

- [x] **Step 10: Run the tests and confirm they pass**

Run: `./includes/vendor/bin/phpunit tests/Jobs/WorkerScriptsTest.php --display-warnings`
Expected: 7 tests pass.

Run: `./includes/vendor/bin/phpunit`
Expected: only the known baseline failure.

---

### Task 9: Pending counts and operator tools

The pending-work SQL in `deploy/check-pending-work.sh` is a hand copy of six `WHERE` clauses. Replace it with a call into the queue classes, so there is one definition of "pending".

**Files:**
- Create: `src/Jobs/PendingWork.php`, `bin/pending_work.php`, `bin/job_status.php`, `tests/Jobs/PendingWorkTest.php`
- Modify: `deploy/check-pending-work.sh`

**Interfaces:**
- Consumes: `countPending()` on the six queues (Task 6), `JobLedger::lastActivity`, `JobLedger::failedJobs`, `JobLedger::reset` (Task 4).
- Produces: `PendingWork::__construct(PDO $pdo)`, `counts(): array<string,int>` keyed by the six stage names in pipeline order, `total(): int`, `lastActivity(): ?int`.
- `bin/pending_work.php` prints one line per non-empty stage to stderr and the total alone on the last line of stdout. `deploy/check-pending-work.sh` keeps its contract: the total is the last line of its output.

- [x] **Step 1: Write the failing test**

Create `tests/Jobs/PendingWorkTest.php`:

```php
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

        // File 2: on S3 with nothing else done. Pending screenshots, transcript and speakers.
        $pdo->exec(
            "INSERT INTO files (chamber, title, date, path, date_created)
             VALUES ('house', 'B', '2025-01-02', 'https://video.richmondsunlight.com/house/floor/20250102-2.mp4', '2025-01-02 00:00:00')"
        );

        $pending = new PendingWork($pdo);

        $this->assertSame(
            ['download' => 1, 'screenshots' => 1, 'transcript' => 1, 'bills' => 0, 'speakers' => 1, 'archive' => 0],
            $pending->counts()
        );
        $this->assertSame(4, $pending->total());

        // A video that has been given up on is not work the pipeline can do.
        (new JobLedger($pdo))->markFailed(1, JobLedger::STAGE_DOWNLOAD, 'dead link');
        $this->assertSame(3, $pending->total());
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
```

- [x] **Step 2: Run it and confirm it fails**

Run: `./includes/vendor/bin/phpunit tests/Jobs/PendingWorkTest.php --display-warnings`
Expected: error that `PendingWork` does not exist.

- [x] **Step 3: Create `PendingWork`**

Create `src/Jobs/PendingWork.php`:

```php
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
```

- [x] **Step 4: Run the test and confirm it passes**

Run: `./includes/vendor/bin/phpunit tests/Jobs/PendingWorkTest.php --display-warnings`
Expected: 2 tests pass.

- [x] **Step 5: Create `bin/pending_work.php`**

Create it and make it executable (`chmod +x bin/pending_work.php`). It does not use `bin/bootstrap.php`, because that prints log lines, and the last line of output must be the total.

```php
#!/usr/bin/env php
<?php

/**
 * Prints how much work each pipeline stage could do right now.
 *
 * The per-stage breakdown goes to stderr. The total goes to stdout, alone on
 * the last line, which is what deploy/check-pending-work.sh's callers read.
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Jobs\PendingWork;

require_once __DIR__ . '/../includes/settings.inc.php';
require_once __DIR__ . '/../includes/vendor/autoload.php';
require_once __DIR__ . '/../includes/class.Database.php';

$pdo = AppBootstrap::createFreshConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Unable to connect to the database.\n");
    exit(1);
}

$counts = (new PendingWork($pdo))->counts();
foreach ($counts as $stage => $count) {
    if ($count > 0) {
        fwrite(STDERR, "  - {$count} videos pending {$stage}\n");
    }
}

echo array_sum($counts) . "\n";
```

- [x] **Step 6: Replace the body of `deploy/check-pending-work.sh`**

Replace the whole file with:

```bash
#!/usr/bin/env bash
set -euo pipefail

# Check for pending work in the video processing pipeline.
# Prints the per-stage breakdown, then the total count alone on the last line.
#
# The counts come from the job queue classes (via bin/pending_work.php), so
# this always agrees with what the workers would actually fetch.
#
# Usage:
#   PENDING_OUTPUT=$(./deploy/check-pending-work.sh)
#   PENDING_COUNT=$(echo "$PENDING_OUTPUT" | tail -1)

APP_DIR="${APP_DIR:-/home/ubuntu/video-processor}"

cd "$APP_DIR"

php bin/pending_work.php 2>&1
```

- [x] **Step 7: Create `bin/job_status.php`**

Create it and make it executable (`chmod +x bin/job_status.php`):

```php
#!/usr/bin/env php
<?php

/**
 * Shows what the pipeline has pending and what it has given up on.
 *
 * Usage:
 *   php bin/job_status.php                      # pending counts and given-up jobs
 *   php bin/job_status.php --reset=18797        # forget all ledger state for one file
 *   php bin/job_status.php --reset-stage=archive  # retry every given-up job in one stage
 *   php bin/job_status.php --reset-failed       # retry every given-up job
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Jobs\JobLedger;
use RichmondSunlight\VideoProcessor\Jobs\PendingWork;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;
$ledger = new JobLedger($pdo);

$options = getopt('', ['reset:', 'reset-stage:', 'reset-failed']);

if (isset($options['reset'])) {
    $removed = $ledger->reset((int) $options['reset']);
    echo "Cleared {$removed} ledger row(s) for file #" . (int) $options['reset'] . ".\n";
    exit(0);
}

if (isset($options['reset-stage'])) {
    $stage = (string) $options['reset-stage'];
    if (!in_array($stage, JobLedger::STAGES, true)) {
        fwrite(STDERR, 'Unknown stage. Choose one of: ' . implode(', ', JobLedger::STAGES) . "\n");
        exit(1);
    }
    $removed = $ledger->reset(null, $stage, true);
    echo "Cleared {$removed} given-up {$stage} job(s).\n";
    exit(0);
}

if (isset($options['reset-failed'])) {
    $removed = $ledger->reset(null, null, true);
    echo "Cleared {$removed} given-up job(s).\n";
    exit(0);
}

echo "\nPending now:\n";
foreach ((new PendingWork($pdo))->counts() as $stage => $count) {
    echo sprintf("  %-12s %d\n", $stage, $count);
}

$failed = $ledger->failedJobs();
echo sprintf("\nGiven up on (%d):\n", count($failed));
foreach ($failed as $job) {
    echo sprintf(
        "  file #%-6d %-12s %s UTC  %s\n",
        (int) $job['file_id'],
        $job['stage'],
        $job['date_modified'],
        substr(str_replace("\n", ' ', (string) $job['last_error']), 0, 140)
    );
}
echo "\n";
```

- [x] **Step 8: Lint**

```bash
php -l bin/pending_work.php && php -l bin/job_status.php && bash -n deploy/check-pending-work.sh
```

---

### Task 10: Archive gate and stranded videos

Three predicate fixes:

1. Archiving must wait for real screenshots. `'/pending'` and `'/deleted-source'` are not screenshots.
2. Bill detection must not pick up `'/deleted-source'` rows.
3. A video that is already on archive.org and has no screenshots must be able to get them.

A real screenshot directory looks like `/house/floor/20260817-18797/`: it matches `LIKE '/%/%'`. The markers `/pending` and `/deleted-source` contain one slash and do not.

**Files:**
- Modify: `src/Archive/ArchiveJobQueue.php`, `src/Analysis/Bills/BillDetectionJobQueue.php`, `src/Screenshots/ScreenshotJobQueue.php`, `src/Screenshots/ScreenshotGenerator.php`
- Test: `tests/Archive/ArchiveJobQueueTest.php`, `tests/Analysis/Bills/BillDetectionJobQueueTest.php`, `tests/Screenshots/ScreenshotJobQueueTest.php`

- [x] **Step 1: Write the failing tests**

Add to `tests/Archive/ArchiveJobQueueTest.php`, inside the class:

```php
    /**
     * '/pending' means a screenshot job is in flight or has failed. Archiving
     * then would move the video to archive.org, out of the screenshot queue's reach.
     */
    public function testExcludesRowsWhoseScreenshotsAreNotFinished(): void
    {
        $pdo = $this->createTestDatabase();

        foreach (['/pending', '/deleted-source'] as $marker) {
            $pdo->prepare(
                'INSERT INTO files (chamber,title,date,path,webvtt,capture_directory,transcript,date_created)
                 VALUES ("house","Floor","2025-01-01","https://video.richmondsunlight.com/house/floor/20250101.mp4","WEBVTT",:dir,"Some transcript","2025-01-01")'
            )->execute([':dir' => $marker]);
        }

        $queue = new ArchiveJobQueue($pdo);
        $this->assertCount(0, $queue->fetch());
        $this->assertSame(0, $queue->countPending());
    }
```

Add to `tests/Analysis/Bills/BillDetectionJobQueueTest.php`, inside the class:

```php
    public function testSkipsDeletedSourceMarker(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            committee_id INTEGER,
            capture_directory TEXT,
            video_index_cache TEXT,
            date TEXT,
            date_created TEXT
        )');
        \RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory::addPipelineTables($pdo);
        $pdo->exec('CREATE TABLE video_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            type TEXT
        )');
        $pdo->exec('INSERT INTO files (chamber, committee_id, capture_directory, date, date_created)
            VALUES ("house", NULL, "/deleted-source", "2020-01-10", "2020-01-10")');

        $this->assertCount(0, (new BillDetectionJobQueue($pdo))->fetch());
    }
```

Add to `tests/Screenshots/ScreenshotJobQueueTest.php`, inside the class:

```php
    /**
     * Videos archived before their screenshots were made must still be reachable.
     */
    public function testFetchesArchivedVideosThatHaveNoScreenshots(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chamber TEXT,
            committee_id INTEGER,
            title TEXT,
            date TEXT,
            path TEXT,
            html TEXT,
            capture_directory TEXT,
            capture_rate INTEGER,
            date_created TEXT
        )');
        \RichmondSunlight\VideoProcessor\Tests\Support\TestDatabaseFactory::addPipelineTables($pdo);

        $stmt = $pdo->prepare('INSERT INTO files (chamber, title, date, path, date_created)
            VALUES ("senate", "Floor", :date, :path, "2021-01-01 00:00:00")');
        // Eligible: a direct download URL, from 2020 onward.
        $stmt->execute([':date' => '2021-02-03', ':path' => 'https://archive.org/download/rs-senate-20210203/video.mp4']);
        // Not eligible: the details page is HTML, not a video.
        $stmt->execute([':date' => '2021-02-04', ':path' => 'https://archive.org/details/rs-senate-20210204']);
        // Not eligible: before 2020 there is no bill or speaker detection to feed.
        $stmt->execute([':date' => '2015-02-03', ':path' => 'https://archive.org/download/rs-senate-20150203/video.mp4']);

        $jobs = (new ScreenshotJobQueue($pdo))->fetch(10);

        $this->assertCount(1, $jobs);
        $this->assertSame('https://archive.org/download/rs-senate-20210203/video.mp4', $jobs[0]->videoPath);
    }
```

- [x] **Step 2: Run them and confirm they fail**

Run: `./includes/vendor/bin/phpunit tests/Archive/ArchiveJobQueueTest.php tests/Analysis/Bills/BillDetectionJobQueueTest.php tests/Screenshots/ScreenshotJobQueueTest.php --display-warnings`
Expected: the three new tests fail.

- [x] **Step 3: Fix the archive gate**

In `src/Archive/ArchiveJobQueue.php`, in `pendingWhere()`, replace the line

```php
            AND f.capture_directory IS NOT NULL AND f.capture_directory != ''
```

with

```php
            AND (f.capture_directory LIKE '/%/%' OR f.capture_directory LIKE 'https://%')
```

Then add this comment directly above the `return` in `pendingWhere()`:

```php
        // A real screenshot directory has at least two slashes. The markers
        // '/pending' and '/deleted-source' have one and must not pass: archiving
        // moves the video to archive.org, after which it cannot be screenshotted.
```

- [x] **Step 4: Fix the bill-detection predicate**

In `src/Analysis/Bills/BillDetectionJobQueue.php`, in `pendingWhere()`, replace these three lines

```php
        return "f.capture_directory IS NOT NULL AND f.capture_directory != ''
            AND (f.capture_directory LIKE '/%' OR f.capture_directory LIKE 'https://%')
            AND f.capture_directory != '/pending'
```

with

```php
        // '/%/%' matches real directories and excludes the one-slash markers
        // '/pending' and '/deleted-source'.
        return "(f.capture_directory LIKE '/%/%' OR f.capture_directory LIKE 'https://%')
```

- [x] **Step 5: Let the screenshot queue reach archived videos**

In `src/Screenshots/ScreenshotJobQueue.php`, in `pendingWhere()`, replace the line

```php
        return "f.path LIKE 'https://video.richmondsunlight.com/%'
```

with

```php
        // Videos archived before their screenshots existed are still wanted,
        // from 2020 on (the earliest date bill and speaker detection cover).
        return "(f.path LIKE 'https://video.richmondsunlight.com/%'
                 OR (f.path LIKE 'https://archive.org/download/%' AND f.date >= '2020-01-01'))
```

- [x] **Step 6: Let `ScreenshotGenerator` download from archive.org**

In `src/Screenshots/ScreenshotGenerator.php`:

1. In the constructor, replace `$this->http = $http ?? new Client(['timeout' => 600]);` with:

```php
        // Videos run to several GB and archive.org is slow; ten minutes was not enough.
        $this->http = $http ?? new Client(['timeout' => 3600, 'connect_timeout' => 30]);
```

2. In `downloadVideo()`, replace the line

```php
        $response = $this->http->get($this->encodeUrlPath($url), ['sink' => $destination]);
```

with

```php
        // Only our own bucket holds keys with literal '+' and '%' that need
        // escaping again. archive.org URLs are already correctly encoded.
        $fetchUrl = str_contains($url, '://video.richmondsunlight.com/') ? $this->encodeUrlPath($url) : $url;
        $response = $this->http->get($fetchUrl, ['sink' => $destination]);
```

- [x] **Step 7: Run the tests and confirm they pass**

Run: `./includes/vendor/bin/phpunit tests/Archive tests/Analysis/Bills tests/Screenshots tests/Jobs tests/Integration --display-warnings`
Expected: all pass.

---

### Task 11: Transcribe long videos

OpenAI's transcription endpoint rejects files over 25 MB. At 32 kbps mono an MP3 is 14.4 MB per hour, so anything over about 1h44m fails. Split the audio into 20-minute pieces (4.8 MB each) and add each piece's start time to its segments.

**Files:**
- Modify: `src/Transcripts/AudioExtractor.php`, `src/Transcripts/TranscriptProcessor.php`, `src/Transcripts/TranscriptWriter.php`
- Test: `tests/Transcripts/TranscriptProcessorTest.php`, `tests/Transcripts/AudioExtractorTest.php` (new), `tests/Transcripts/TranscriptWriterTest.php`

**Interfaces:**
- Produces: `AudioExtractor::extractChunks(string $videoUrl, int $segmentSeconds = 1200): array`, a list of `['path' => string, 'offset' => float]` in order. `offset` is the chunk's start time in the original video, in seconds. The caller deletes the files.
- `AudioExtractor::extract()` is unchanged; the diarizers still use it.

- [x] **Step 1: Write the failing tests**

Add to `tests/Transcripts/TranscriptProcessorTest.php`, inside the class:

```php
    public function testChunkOffsetsAreAddedToSegmentTimes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE video_transcript (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            text TEXT,
            time_start TEXT,
            time_end TEXT,
            new_speaker TEXT,
            legislator_id INTEGER,
            date_created TEXT
        )');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transcript TEXT,
            webvtt TEXT,
            date_modified TIMESTAMP
        )');
        $pdo->exec('INSERT INTO files (id) VALUES (1)');

        $first = tempnam(sys_get_temp_dir(), 'chunk_');
        $second = tempnam(sys_get_temp_dir(), 'chunk_');

        $extractor = new class ($first, $second) extends AudioExtractor {
            public function __construct(private string $first, private string $second)
            {
                parent::__construct();
            }

            public function extractChunks(string $videoUrl, int $segmentSeconds = 1200): array
            {
                return [
                    ['path' => $this->first, 'offset' => 0.0],
                    ['path' => $this->second, 'offset' => 1200.0],
                ];
            }
        };

        $transcriber = $this->createMock(OpenAITranscriber::class);
        $transcriber->expects($this->exactly(2))->method('transcribe')->willReturn([
            ['start' => 5.0, 'end' => 9.0, 'text' => 'Hello'],
        ]);

        $processor = new TranscriptProcessor(new TranscriptWriter($pdo), $transcriber, new CaptionParser(), $extractor, null);
        $processor->process(new TranscriptJob(1, 'house', 'file://unused', '', '', null));

        $starts = $pdo->query('SELECT time_start FROM video_transcript ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['00:00:05', '00:20:05'], $starts);
        $this->assertFileDoesNotExist($first, 'Chunk files must be deleted after use.');
        $this->assertFileDoesNotExist($second);
    }
```

Create `tests/Transcripts/AudioExtractorTest.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Tests\Transcripts;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Transcripts\AudioExtractor;

class AudioExtractorTest extends TestCase
{
    public function testSplitsAudioIntoChunksWithOffsets(): void
    {
        exec('ffmpeg -version > /dev/null 2>&1', $output, $status);
        if ($status !== 0) {
            $this->markTestSkipped('ffmpeg is required for audio extraction tests.');
        }
        $fixture = __DIR__ . '/../fixtures/house-floor.mp4';
        if (!file_exists($fixture)) {
            $this->markTestSkipped('Missing video fixture house-floor.mp4. Run bin/fetch_test_fixtures.php.');
        }

        $chunks = (new AudioExtractor())->extractChunks('file://' . $fixture, 5);

        try {
            $this->assertGreaterThanOrEqual(2, count($chunks), 'A fixture longer than 5 seconds must produce several chunks.');
            $this->assertSame(0.0, $chunks[0]['offset']);
            $this->assertSame(5.0, $chunks[1]['offset']);
            foreach ($chunks as $chunk) {
                $this->assertFileExists($chunk['path']);
                $this->assertGreaterThan(0, filesize($chunk['path']));
            }
        } finally {
            foreach ($chunks as $chunk) {
                @unlink($chunk['path']);
            }
            if (!empty($chunks)) {
                @rmdir(dirname($chunks[0]['path']));
            }
        }
    }
}
```

Add to `tests/Transcripts/TranscriptWriterTest.php`, inside the class. If that file has a helper that builds its database, use the helper; otherwise this test is self-contained:

```php
    /**
     * video_transcript.text is TINYTEXT (255 bytes) in production. A longer
     * segment must be cut on a character boundary, not rejected.
     */
    public function testLongSegmentIsCutToFitTheColumn(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE video_transcript (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_id INTEGER,
            text TEXT,
            time_start TEXT,
            time_end TEXT,
            new_speaker TEXT,
            legislator_id INTEGER,
            date_created TEXT
        )');
        $pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transcript TEXT,
            webvtt TEXT,
            date_modified TIMESTAMP
        )');
        $pdo->exec('INSERT INTO files (id) VALUES (1)');

        $long = str_repeat('é', 300); // 600 bytes
        (new \RichmondSunlight\VideoProcessor\Transcripts\TranscriptWriter($pdo))->write(1, [
            ['start' => 0.0, 'end' => 1.0, 'text' => $long],
        ]);

        $stored = $pdo->query('SELECT text FROM video_transcript')->fetchColumn();
        $this->assertLessThanOrEqual(255, strlen($stored));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));

        $full = $pdo->query('SELECT transcript FROM files WHERE id = 1')->fetchColumn();
        $this->assertSame($long, $full, 'The full text is kept in files.transcript.');
    }
```

- [x] **Step 2: Run them and confirm they fail**

Run: `./includes/vendor/bin/phpunit tests/Transcripts --display-warnings`
Expected: the three new tests fail (or the AudioExtractor one is skipped if the fixture is missing).

- [x] **Step 3: Add `AudioExtractor::extractChunks`**

In `src/Transcripts/AudioExtractor.php`:

1. In the constructor replace `$this->http = new Client(['timeout' => 600]);` with:

```php
        // Videos run to several GB; ten minutes was not always enough.
        $this->http = new Client(['timeout' => 3600, 'connect_timeout' => 30]);
```

2. Add this method directly after `extract()`:

```php
    /**
     * Extract the audio as a series of MP3 files of at most $segmentSeconds each.
     *
     * OpenAI's transcription endpoint rejects files over 25 MB. At 32 kbps a
     * whole meeting passes that after about 1h44m; a 20-minute piece is under 5 MB.
     *
     * @return array<int,array{path:string,offset:float}> in order; offset is the piece's
     *         start time in the original video, in seconds. The caller deletes the files.
     */
    public function extractChunks(string $videoUrl, int $segmentSeconds = 1200): array
    {
        $videoPath = $this->downloadVideo($videoUrl);
        $dir = $this->workingDir . '/transcript_chunks_' . uniqid('', true);
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            @unlink($videoPath);
            throw new RuntimeException('Unable to create a working directory for audio chunks.');
        }

        try {
            $cmd = sprintf(
                'ffmpeg -y -loglevel error -i %s -vn -ar 16000 -ac 1 -b:a 32k '
                . '-f segment -segment_time %d -reset_timestamps 1 %s',
                escapeshellarg($videoPath),
                $segmentSeconds,
                escapeshellarg($dir . '/chunk_%05d.mp3')
            );
            $this->runCommand($cmd, 'Failed to convert audio for transcription.');
        } finally {
            @unlink($videoPath);
        }

        $files = glob($dir . '/chunk_*.mp3') ?: [];
        sort($files);
        if (empty($files)) {
            @rmdir($dir);
            throw new RuntimeException('ffmpeg produced no audio for transcription.');
        }

        $chunks = [];
        foreach ($files as $index => $file) {
            $chunks[] = ['path' => $file, 'offset' => (float) ($index * $segmentSeconds)];
        }

        return $chunks;
    }
```

- [x] **Step 4: Transcribe chunk by chunk**

In `src/Transcripts/TranscriptProcessor.php` replace the whole private `transcribe` method with:

```php
    /**
     * @return array<int,array{start:float,end:float,text:string}>
     */
    private function transcribe(string $videoUrl): array
    {
        $chunks = $this->audioExtractor->extractChunks($videoUrl);
        $segments = [];

        try {
            foreach ($chunks as $chunk) {
                // Each piece is timed from zero; shift it to its place in the video.
                foreach ($this->transcriber->transcribe($chunk['path']) as $segment) {
                    $segments[] = [
                        'start' => $segment['start'] + $chunk['offset'],
                        'end' => $segment['end'] + $chunk['offset'],
                        'text' => $segment['text'],
                    ];
                }
            }
        } finally {
            foreach ($chunks as $chunk) {
                @unlink($chunk['path']);
            }
            if (!empty($chunks)) {
                @rmdir(dirname($chunks[0]['path']));
            }
        }

        return $segments;
    }
```

- [x] **Step 5: Keep a long segment from failing the insert**

In `src/Transcripts/TranscriptWriter.php`, inside `write()`, in the `$stmt->execute([...])` call for `video_transcript`, replace

```php
                    ':text' => $segment['text'],
```

with

```php
                    // The column is TINYTEXT: 255 bytes. Cut on a character boundary.
                    ':text' => mb_strcut($segment['text'], 0, 255, 'UTF-8'),
```

- [x] **Step 6: Run the tests and confirm they pass**

Run: `./includes/vendor/bin/phpunit tests/Transcripts tests/Integration --display-warnings`
Expected: all pass. The existing `testFallsBackToOpenAi` still expects exactly one `transcribe` call; it passes because the fixture is shorter than 20 minutes.

---

### Task 12: Validate downloads

A file that is not a video should be rejected when it is downloaded, not three stages later.

**Files:**
- Create: `src/Fetcher/VideoValidator.php`, `tests/Fetcher/VideoValidatorTest.php`
- Modify: `src/Screenshots/ScreenshotGenerator.php`, `src/Fetcher/VideoDownloadProcessor.php`, `src/Archive/InternetArchiveUploader.php`, `bin/fetch_videos.php`, `bin/process_uploads.php`, `bin/generate_upload_manifest.php`
- Test: `tests/Fetcher/VideoDownloadProcessorTest.php`

**Interfaces:**
- Produces: `VideoValidator::assertValid(string $path): void`, which throws `RuntimeException` with a message containing `not a valid video`. `VideoDownloadProcessor::__construct` gains a ninth, optional parameter `?VideoValidator $validator = null`; null means "do not validate", which keeps existing tests working.

- [x] **Step 1: Write the failing tests**

Create `tests/Fetcher/VideoValidatorTest.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Tests\Fetcher;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Fetcher\VideoValidator;

class VideoValidatorTest extends TestCase
{
    public function testRejectsAFileTooSmallToBeAVideo(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tiny');
        file_put_contents($path, '<html>502 Bad Gateway</html>');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/not a valid video/i');
            (new VideoValidator())->assertValid($path);
        } finally {
            @unlink($path);
        }
    }

    public function testAcceptsARealVideo(): void
    {
        exec('ffprobe -version > /dev/null 2>&1', $output, $status);
        $fixture = __DIR__ . '/../fixtures/house-floor.mp4';
        if ($status !== 0 || !file_exists($fixture)) {
            $this->markTestSkipped('Needs ffprobe and the house-floor.mp4 fixture.');
        }

        (new VideoValidator())->assertValid($fixture);
        $this->addToAssertionCount(1);
    }
}
```

In `tests/Fetcher/VideoDownloadProcessorTest.php`:

1. Add `use RichmondSunlight\VideoProcessor\Fetcher\VideoValidator;` to the imports.
2. In the `TestableVideoDownloadProcessor` class at the bottom of the file, replace the constructor with:

```php
    public function __construct(
        PDO $pdo,
        StorageInterface $storage,
        CommitteeDirectory $committeeDirectory,
        VideoMetadataExtractor $metadataExtractor,
        S3KeyBuilder $keyBuilder,
        ?\Log $logger = null,
        ?string $downloadDir = null,
        ?VideoValidator $validator = null
    ) {
        parent::__construct(
            $pdo,
            $storage,
            $committeeDirectory,
            $metadataExtractor,
            $keyBuilder,
            null,
            $logger,
            $downloadDir,
            $validator
        );
        $this->dummyVideo = $this->createDummyVideo();
    }
```

3. Add this test inside `VideoDownloadProcessorTest`:

```php
    public function testAFileThatIsNotAVideoIsNeverUploaded(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec("INSERT INTO files (id, chamber, committee_id, path, capture_directory, date_created, date_modified) VALUES (4, 'house', NULL, '', '', '2025-01-01', '2025-01-01')");

        $validator = new class extends VideoValidator {
            public function assertValid(string $path): void
            {
                throw new \RuntimeException('Downloaded file is not a valid video: test.');
            }
        };

        $storage = new InMemoryStorage();
        $processor = new TestableVideoDownloadProcessor(
            $pdo,
            $storage,
            new CommitteeDirectory($pdo),
            new StubMetadataExtractor(),
            new S3KeyBuilder(),
            null,
            null,
            $validator
        );

        $job = new VideoDownloadJob(4, 'house', null, '2025-04-10', 'https://example.com/error-page.mp4', [], 'Test');

        try {
            $processor->process($job);
            $this->fail('An invalid download must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not a valid video', $e->getMessage());
        }

        $this->assertSame([], $storage->uploads);
        $this->assertSame('', $pdo->query('SELECT path FROM files WHERE id = 4')->fetchColumn());
    }
```

- [x] **Step 2: Run them and confirm they fail**

Run: `./includes/vendor/bin/phpunit tests/Fetcher --display-warnings`
Expected: errors that `VideoValidator` does not exist.

- [x] **Step 3: Create `VideoValidator`**

Create `src/Fetcher/VideoValidator.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Fetcher;

use RuntimeException;

/**
 * Decides whether a downloaded file is genuinely a video.
 *
 * Three things have been stored as videos here: IIS "502 Bad Gateway" pages
 * saved as .mp4, caption and manifest files fetched from a wrong key, and
 * truncated uploads with no moov atom.
 */
class VideoValidator
{
    /** A real session video is never smaller than this; anything less is an error page. */
    public const MIN_VIDEO_BYTES = 3 * 1024 * 1024;

    /**
     * @throws RuntimeException with a message containing "not a valid video"
     */
    public function assertValid(string $path): void
    {
        $size = @filesize($path);
        if ($size === false || $size < self::MIN_VIDEO_BYTES) {
            throw new RuntimeException(sprintf(
                'Downloaded file is not a valid video: %s bytes is below the %d-byte minimum '
                . '(source is likely an error page rather than a video).',
                $size === false ? '0' : (string) $size,
                self::MIN_VIDEO_BYTES
            ));
        }

        $cmd = sprintf(
            'ffprobe -v error -show_entries stream=codec_type -of csv=p=0 %s 2>&1',
            escapeshellarg($path)
        );
        exec($cmd, $output, $status);
        $detail = trim(implode(' ', $output));

        if ($status !== 0) {
            throw new RuntimeException(sprintf(
                'Downloaded file is not a valid video (ffprobe exit %d): %s',
                $status,
                $detail ?: 'no output'
            ));
        }

        // ffprobe exits 0 on files it can open but that hold no video stream,
        // such as a caption file. Require an actual video stream.
        if (!in_array('video', array_map('trim', $output), true)) {
            throw new RuntimeException(sprintf(
                'Downloaded file is not a valid video: no video stream found (ffprobe reported: %s).',
                $detail !== '' ? $detail : 'no streams'
            ));
        }
    }
}
```

- [x] **Step 4: Make `ScreenshotGenerator` use it**

In `src/Screenshots/ScreenshotGenerator.php`:

1. Add `use RichmondSunlight\VideoProcessor\Fetcher\VideoValidator;` to the imports.
2. Delete the two lines that define the constant: the docblock line `/** A real session video is never smaller than this; anything less is an error page. */` and `private const MIN_VIDEO_BYTES = 3 * 1024 * 1024;`.
3. Replace the whole body of the private `validateVideo` method (keep its docblock and its signature `private function validateVideo(string $path): void`) with one line:

```php
        (new VideoValidator())->assertValid($path);
```

The existing tests call `validateVideo` by reflection, so the method must keep its name.

- [x] **Step 5: Validate in `VideoDownloadProcessor`**

In `src/Fetcher/VideoDownloadProcessor.php`:

1. Add a ninth constructor parameter. Replace

```php
        ?string $downloadDir = null
    ) {
```

with

```php
        ?string $downloadDir = null,
        private ?VideoValidator $validator = null
    ) {
```

2. In `process()`, replace the line `$localVideo = $this->downloadVideo($job);` with:

```php
            $localVideo = $this->downloadVideo($job);

            // Reject error pages and truncated files here, before they are
            // stored on S3 as if they were the video.
            if ($this->validator) {
                try {
                    $this->validator->assertValid($localVideo);
                } catch (\Throwable $e) {
                    @unlink($localVideo);
                    throw $e;
                }
            }
```

- [x] **Step 6: Turn validation on in production**

In `bin/fetch_videos.php`:

1. Add `use RichmondSunlight\VideoProcessor\Fetcher\VideoValidator;` to the imports, in alphabetical position.
2. In the `new VideoDownloadProcessor(...)` call, replace the last two arguments

```php
            null,
            $log
        );
```

with

```php
            null,
            $log,
            null,
            new VideoValidator()
        );
```

In `bin/process_uploads.php`:

1. Add `use RichmondSunlight\VideoProcessor\Fetcher\VideoValidator;` to the imports.
2. Directly after the line `$s3Client->getObject(['Bucket' => $bucket, 'Key' => $uploadKey, 'SaveAs' => $localVideo]);` add:

```php

        // A bad manual upload stays in uploads/ and is reported each pass until removed.
        (new VideoValidator())->assertValid($localVideo);
```

- [x] **Step 7: Stop the archive uploader accepting error pages**

In `src/Archive/InternetArchiveUploader.php`, in the default `$this->downloader` closure, replace

```php
            $cmd = sprintf('curl -L %s -o %s', escapeshellarg($url), escapeshellarg($tempWithExt));
```

with

```php
            // -f: fail on an HTTP error instead of saving the error page as the video.
            $cmd = sprintf('curl -fL --retry 3 --silent --show-error %s -o %s', escapeshellarg($url), escapeshellarg($tempWithExt));
```

- [x] **Step 8: Stop re-listing archived Senate videos for manual download**

In `bin/generate_upload_manifest.php` replace the line

```php
      AND (path IS NULL OR path = '' OR path NOT LIKE 'https://video.richmondsunlight.com/%')
```

with

```php
      AND (path IS NULL OR path = '' OR (
            path NOT LIKE 'https://video.richmondsunlight.com/%'
            AND path NOT LIKE 'https://archive.org/%'
      ))
```

In `bin/process_uploads.php` replace the line

```php
          AND (path IS NULL OR path = '' OR path NOT LIKE 'https://video.richmondsunlight.com/%')
```

with

```php
          AND (path IS NULL OR path = '' OR (
                path NOT LIKE 'https://video.richmondsunlight.com/%'
                AND path NOT LIKE 'https://archive.org/%'
          ))
```

- [x] **Step 9: Run the tests and confirm they pass**

Run: `./includes/vendor/bin/phpunit tests/Fetcher tests/Screenshots tests/Archive tests/Jobs tests/Integration --display-warnings`
Expected: all pass.

- [x] **Step 10: Lint**

```bash
php -l bin/fetch_videos.php && php -l bin/process_uploads.php && php -l bin/generate_upload_manifest.php
```

---

### Task 13: Orchestration

The instance should work until the backlog is gone, not for one pass. Drain mode already exists; make it the default on boot, and make it stop when it is no longer making progress.

**Files:**
- Modify: `deploy/run-pipeline.sh`, `bin/pipeline_parallel.sh`, `deploy/services/video-pipeline.service`, `deploy/deploy.sh`

There is no PHPUnit test for this task. Verification is `bash -n` plus the greps in Step 6.

- [x] **Step 1: Check the tables exist before anything else**

In `deploy/run-pipeline.sh`, directly after the line `SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"`, add:

```bash

# Every queue reads the video_jobs table and the importer reads video_sources.
# Without them nothing below can work, so stop here if either is missing.
if ! php bin/check_schema.php; then
  echo "ERROR: pipeline tables are missing; not running the pipeline." >&2
  exit 1
fi
```

In `bin/pipeline_parallel.sh`, directly after the line `JOBS_PER_WORKER=${JOBS_PER_WORKER:-5}`, add:

```bash

# The queues need the video_jobs and video_sources tables; stop if either is missing.
if ! php "$SCRIPT_DIR/check_schema.php"; then
  echo "ERROR: pipeline tables are missing; not running the pipeline." >&2
  exit 1
fi
```

- [x] **Step 2: Stop a drain that is not getting anywhere**

In `deploy/run-pipeline.sh`:

1. Directly after the line `IDLE_CONFIRM_SECONDS="${IDLE_CONFIRM_SECONDS:-60}"  # pause between the two idle checks`, add:

```bash
MAX_STALLED_PASSES="${MAX_STALLED_PASSES:-3}"  # drain passes allowed without the pending count dropping
```

2. Directly after the line `pass_count=0`, add:

```bash
prev_pending=-1
stalled_passes=0
```

3. In the drain-mode loop, find this block:

```bash
    if [[ $ELAPSED -ge $MAX_DRAIN_RUNTIME_SECONDS ]]; then
      echo "Drain time limit reached ($((MAX_DRAIN_RUNTIME_SECONDS / 60)) minutes) with $PENDING_COUNT items still pending."
      break
    fi
```

   Directly after it, add:

```bash

    # If the count is not dropping, more passes will not help: what is left is
    # failing or waiting on something outside this machine. Stop and shut down.
    if [[ $prev_pending -ge 0 && $PENDING_COUNT -ge $prev_pending ]]; then
      stalled_passes=$((stalled_passes + 1))
    else
      stalled_passes=0
    fi
    prev_pending=$PENDING_COUNT
    if [[ $stalled_passes -ge $MAX_STALLED_PASSES ]]; then
      echo "Pending count has not dropped for ${stalled_passes} passes (${PENDING_COUNT} pending); stopping."
      break
    fi
```

- [x] **Step 3: Boot into drain mode**

In `deploy/services/video-pipeline.service` replace the line

```ini
ExecStart=/home/ubuntu/video-processor/deploy/run-pipeline.sh
```

with

```ini
# Worker counts, deployed with the code. One VAR=value per line.
EnvironmentFile=-/home/ubuntu/video-processor/deploy/video-processor.env
# Optional per-machine overrides of the same variables, plus JOBS_PER_WORKER and
# MAX_DRAIN_RUNTIME_SECONDS. Read second, so it wins. The leading "-" means a
# file may be absent.
EnvironmentFile=-/home/ubuntu/video-processor.env
# --drain: keep making passes until nothing is pending, the time limit is
# reached, or the pending count stops dropping.
ExecStart=/home/ubuntu/video-processor/deploy/run-pipeline.sh --drain
```

- [x] **Step 4: Retire the standalone screenshot worker**

It runs alongside the pipeline's own screenshot step with `Restart=always`, and is killed mid-job at every shutdown.

In `deploy/deploy.sh`:

1. Delete these two lines:

```bash
  # Install the screenshot worker service (optional, for continuous processing)
  sudo cp deploy/services/screenshot-worker.service /etc/systemd/system/screenshot-worker.service
```

2. Replace the line `  sudo systemctl enable screenshot-worker.service` with:

```bash
  # The pipeline generates screenshots itself. The standalone worker competed
  # with it and was killed mid-job at every shutdown, so make sure it is off.
  sudo systemctl disable --now screenshot-worker.service 2>/dev/null || true
```

Leave `deploy/services/screenshot-worker.service` and `bin/screenshot_worker.sh` in the repository.

- [x] **Step 5: Update the comment at the top of `deploy/run-pipeline.sh`**

Replace the two comment lines

```bash
# Default mode: Runs in a loop for up to 110 minutes, then exits to allow auto-shutdown.
# Drain mode (--drain): Runs the parallel pipeline in a loop until all queues are empty.
```

with

```bash
# Default mode: Runs in a loop for up to 110 minutes, then exits to allow auto-shutdown.
# Drain mode (--drain): Runs the parallel pipeline in a loop until all queues are
#   empty, the time limit is reached, or the pending count stops dropping. This is
#   what video-pipeline.service runs on boot.
```

- [x] **Step 6: Verify**

```bash
bash -n deploy/run-pipeline.sh && bash -n bin/pipeline_parallel.sh && bash -n deploy/deploy.sh
grep -c "check_schema.php" deploy/run-pipeline.sh bin/pipeline_parallel.sh
grep -n "screenshot-worker" deploy/deploy.sh
grep -n "ExecStart" deploy/services/video-pipeline.service
```

Expected: no syntax errors; each of the two scripts reports `1`; `deploy.sh` shows only the `disable --now` line; `ExecStart` ends in `--drain`.

---

### Task 14: Requeue and shared-media tools

Rows that already share a video or a screenshot directory cannot be untangled automatically: nobody knows which meeting the shared object really is. These tools let a person find such rows and send them through the pipeline again under unique keys.

**Files:**
- Create: `src/Maintenance/VideoRequeuer.php`, `bin/requeue_video.php`, `bin/report_shared_media.php`, `tests/Maintenance/VideoRequeuerTest.php`

**Interfaces:**
- Produces: `VideoRequeuer::__construct(PDO $pdo)`, `sourceUrl(int $fileId): string` (throws `InvalidArgumentException` if the file does not exist, `RuntimeException` if it has no source URL), `requeue(int $fileId): string` returning the source URL.

- [x] **Step 1: Write the failing test**

Create `tests/Maintenance/VideoRequeuerTest.php`:

```php
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
            "INSERT INTO files (chamber, title, date, path, capture_directory, capture_rate, transcript, webvtt, video_index_cache, date_created)
             VALUES ('house', 'T', '2026-08-17', 'https://video.richmondsunlight.com/house/floor/20260817.mp4',
                     '/house/floor/20260817/', 60, 'words', 'WEBVTT', :cache, '2026-08-17 00:00:00')"
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

        $row = $this->pdo->query("SELECT path, capture_directory, capture_rate, transcript, webvtt FROM files WHERE id = {$id}")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('https://source.example/video.mp4', $row['path']);
        $this->assertNull($row['capture_directory']);
        $this->assertNull($row['capture_rate']);
        $this->assertNull($row['transcript']);
        $this->assertNull($row['webvtt']);

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
```

- [x] **Step 2: Run it and confirm it fails**

Run: `./includes/vendor/bin/phpunit tests/Maintenance/VideoRequeuerTest.php --display-warnings`
Expected: error that `VideoRequeuer` does not exist.

- [x] **Step 3: Create `VideoRequeuer`**

Create `src/Maintenance/VideoRequeuer.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Maintenance;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Returns one video to the "not downloaded" state, so it goes through every
 * stage again.
 *
 * This is the remedy for rows whose video or screenshots were shared with, or
 * overwritten by, another row. It discards the row's derived data. It does not
 * delete anything from S3 or the Internet Archive.
 */
class VideoRequeuer
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * The URL the video was originally scraped from.
     *
     * @throws InvalidArgumentException when the file does not exist
     * @throws RuntimeException when no source URL was recorded
     */
    public function sourceUrl(int $fileId): string
    {
        $stmt = $this->pdo->prepare('SELECT video_index_cache FROM files WHERE id = :id');
        $stmt->execute([':id' => $fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new InvalidArgumentException("File #{$fileId} does not exist.");
        }

        $cache = json_decode((string) $row['video_index_cache'], true);
        $url = is_array($cache) ? ($cache['video_url'] ?? null) : null;
        if (!is_string($url) || $url === '') {
            throw new RuntimeException(
                "File #{$fileId} has no source video_url in video_index_cache; it cannot be re-downloaded."
            );
        }

        return $url;
    }

    /**
     * @return string the source URL the video will be downloaded from again
     */
    public function requeue(int $fileId): string
    {
        $url = $this->sourceUrl($fileId);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE files
                 SET path = :path, capture_directory = NULL, capture_rate = NULL, transcript = NULL, webvtt = NULL
                 WHERE id = :id'
            )->execute([':path' => $url, ':id' => $fileId]);

            foreach (['video_index', 'video_transcript', 'video_jobs'] as $table) {
                $this->pdo->prepare("DELETE FROM {$table} WHERE file_id = :id")->execute([':id' => $fileId]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $url;
    }
}
```

- [x] **Step 4: Run the test and confirm it passes**

Run: `./includes/vendor/bin/phpunit tests/Maintenance/VideoRequeuerTest.php --display-warnings`
Expected: 3 tests pass.

- [x] **Step 5: Create `bin/requeue_video.php`**

Create it and make it executable (`chmod +x bin/requeue_video.php`):

```php
#!/usr/bin/env php
<?php

/**
 * Send one or more videos through the whole pipeline again.
 *
 * Usage:
 *   php bin/requeue_video.php --id=18797 --id=18798           # show what would happen
 *   php bin/requeue_video.php --id=18797 --id=18798 --apply   # do it
 *
 * Requeueing discards the video's screenshots record, transcript, bill and
 * speaker results, and job history. Nothing is deleted from S3 or the
 * Internet Archive.
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Maintenance\VideoRequeuer;

$app = require __DIR__ . '/bootstrap.php';

$options = getopt('', ['id:', 'apply']);
$ids = array_map('intval', (array) ($options['id'] ?? []));
$apply = isset($options['apply']);

if (empty($ids)) {
    fwrite(STDERR, "Usage: php bin/requeue_video.php --id=N [--id=N ...] [--apply]\n");
    exit(1);
}

$requeuer = new VideoRequeuer($app->pdo);
$failures = 0;

foreach ($ids as $id) {
    try {
        if ($apply) {
            $url = $requeuer->requeue($id);
            echo "File #{$id}: requeued; will be downloaded again from {$url}\n";
        } else {
            $url = $requeuer->sourceUrl($id);
            echo "File #{$id}: would be requeued and downloaded again from {$url}\n";
        }
    } catch (Throwable $e) {
        $failures++;
        echo "File #{$id}: NOT requeued. " . $e->getMessage() . "\n";
    }
}

if (!$apply) {
    echo "\nNothing was changed. Add --apply to requeue.\n";
}

exit($failures > 0 ? 1 : 0);
```

- [x] **Step 6: Create `bin/report_shared_media.php`**

Create it and make it executable (`chmod +x bin/report_shared_media.php`). It only reads.

```php
#!/usr/bin/env php
<?php

/**
 * Lists files rows that share a video, share a screenshot directory, or were
 * imported twice. Read-only.
 *
 * Rows that share media were produced before every video had a unique S3 key.
 * At most one row in each group shows the right video. Send the rest (or all
 * of them, if unsure) through the pipeline again with bin/requeue_video.php.
 *
 * Usage:
 *   php bin/report_shared_media.php
 */

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Sync\VideoSourceBackfill;

$app = require __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;

$checks = [
    'Rows sharing one screenshot directory' => [
        'column' => 'capture_directory',
        'where' => "capture_directory LIKE '/%/%'",
    ],
    'Rows sharing one video on S3' => [
        'column' => 'path',
        'where' => "path LIKE 'https://video.richmondsunlight.com/%'",
    ],
];

foreach ($checks as $heading => $check) {
    $column = $check['column'];
    $groups = $pdo->query(
        "SELECT {$column} AS shared, COUNT(*) AS n, COUNT(DISTINCT title) AS titles
         FROM files
         WHERE {$check['where']}
         GROUP BY {$column}
         HAVING COUNT(*) > 1
         ORDER BY n DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo sprintf("\n%s: %d group(s)\n", $heading, count($groups));

    $members = $pdo->prepare("SELECT id, date, title, length FROM files WHERE {$column} = :shared ORDER BY id");
    foreach ($groups as $group) {
        echo sprintf(
            "  %s  (%d rows, %s)\n",
            $group['shared'],
            (int) $group['n'],
            (int) $group['titles'] > 1 ? 'DIFFERENT meetings' : 'same title'
        );
        $members->execute([':shared' => $group['shared']]);
        foreach ($members->fetchAll(PDO::FETCH_ASSOC) as $row) {
            echo sprintf("      #%-6d %s  %-9s %s\n", (int) $row['id'], $row['date'], $row['length'] ?? '-', $row['title']);
        }
    }
}

// Safe to run repeatedly: it only adds registry rows that are missing.
$duplicates = (new VideoSourceBackfill($pdo))->run()['duplicates'];
echo sprintf("\nRows imported more than once: %d\n", count($duplicates));
foreach ($duplicates as $duplicate) {
    echo sprintf(
        "  #%d duplicates #%d (%s %s)\n",
        $duplicate['file_id'],
        $duplicate['duplicate_of'],
        $duplicate['source'],
        $duplicate['source_id']
    );
}
echo "\n";
```

- [x] **Step 7: Lint**

```bash
php -l bin/requeue_video.php && php -l bin/report_shared_media.php
```

---

### Task 15: MariaDB integration test

Every other queue test runs on SQLite, where `FOR UPDATE SKIP LOCKED` and the `/pending` claims are skipped. This test runs the six queues against the Docker MariaDB with two connections.

It runs only inside Docker. Natively it reports as skipped.

**Files:**
- Create: `tests/Integration/MariaDbQueueTest.php`

- [x] **Step 1: Write the test**

Create `tests/Integration/MariaDbQueueTest.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillDetectionJobQueue;
use RichmondSunlight\VideoProcessor\Analysis\Speakers\SpeakerJobQueue;
use RichmondSunlight\VideoProcessor\Archive\ArchiveJobQueue;
use RichmondSunlight\VideoProcessor\Fetcher\VideoDownloadQueue;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotJobQueue;
use RichmondSunlight\VideoProcessor\Transcripts\TranscriptJobQueue;

/**
 * Runs the six job queues against a real MariaDB, where the row locking and
 * claim code that SQLite skips actually executes.
 *
 * Runs only in the Docker test environment (./docker-tests.sh), which sets
 * DB_HOST=db. It is skipped everywhere else, and refuses any other host so it
 * can never touch a production database.
 */
class MariaDbQueueTest extends TestCase
{
    private const MARKER = 'MariaDbQueueTest';
    private const S3 = 'https://video.richmondsunlight.com/house/floor/20990101-test.mp4';
    private const SHOTS = '/house/floor/20990101-test/';

    private function connect(): PDO
    {
        $host = getenv('DB_HOST');
        if ($host !== 'db') {
            $this->markTestSkipped('Runs only in the Docker test environment (DB_HOST=db).');
        }

        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s', $host, getenv('DB_NAME') ?: 'richmondsunlight'),
            getenv('DB_USER') ?: 'ricsun',
            getenv('DB_PASS') ?: 'password',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return $pdo;
    }

    protected function setUp(): void
    {
        // video_jobs and video_sources come from deploy/mysql/structure.sql, which
        // the Docker database loads when it is first created.
        $this->removeTestRows($this->connect());
    }

    protected function tearDown(): void
    {
        if (getenv('DB_HOST') === 'db') {
            $this->removeTestRows($this->connect());
        }
    }

    private function removeTestRows(PDO $pdo): void
    {
        $pdo->prepare(
            'DELETE FROM video_transcript WHERE file_id IN (SELECT id FROM files WHERE title = :marker)'
        )->execute([':marker' => self::MARKER]);
        // video_index, video_jobs and video_sources rows go with the file (ON DELETE CASCADE).
        $pdo->prepare('DELETE FROM files WHERE title = :marker')->execute([':marker' => self::MARKER]);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function seed(PDO $pdo, array $overrides): int
    {
        $row = array_merge([
            'chamber' => 'house',
            'title' => self::MARKER,
            'description' => '',
            'license' => 'public-domain',
            'type' => 'video',
            // Far in the future, so these rows sort first in every date-ordered queue.
            'date' => '2099-01-01',
            'path' => null,
            'html' => null,
            'webvtt' => null,
            'transcript' => null,
            'capture_directory' => null,
            'capture_rate' => null,
            'video_index_cache' => null,
            'date_created' => '2099-01-01 00:00:00',
        ], $overrides);

        $columns = array_keys($row);
        $stmt = $pdo->prepare(sprintf(
            'INSERT INTO files (%s) VALUES (%s)',
            implode(', ', array_map(fn(string $c) => '`' . $c . '`', $columns)),
            implode(', ', array_map(fn(string $c) => ':' . $c, $columns))
        ));
        $stmt->execute(array_combine(array_map(fn(string $c) => ':' . $c, $columns), array_values($row)));

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array<string,array{0:\Closure,1:\Closure,2:array<string,mixed>}>
     */
    public static function queues(): array
    {
        $cache = json_encode(['video_url' => 'https://example.test/a.mp4']);

        return [
            'download' => [
                fn(PDO $pdo) => new VideoDownloadQueue($pdo),
                fn(object $job): int => $job->id,
                ['path' => '', 'video_index_cache' => $cache],
            ],
            'screenshots' => [
                fn(PDO $pdo) => new ScreenshotJobQueue($pdo),
                fn(object $job): int => $job->id,
                ['path' => self::S3],
            ],
            'transcript' => [
                fn(PDO $pdo) => new TranscriptJobQueue($pdo),
                fn(object $job): int => $job->fileId,
                ['path' => self::S3],
            ],
            'bills' => [
                fn(PDO $pdo) => new BillDetectionJobQueue($pdo),
                fn(object $job): int => $job->fileId,
                ['capture_directory' => self::SHOTS],
            ],
            'speakers' => [
                fn(PDO $pdo) => new SpeakerJobQueue($pdo),
                fn(object $job): int => $job->fileId,
                ['path' => self::S3],
            ],
            'archive' => [
                fn(PDO $pdo) => new ArchiveJobQueue($pdo),
                fn(object $job): int => $job->fileId,
                ['path' => self::S3, 'webvtt' => 'WEBVTT', 'capture_directory' => self::SHOTS, 'transcript' => 'text'],
            ],
        ];
    }

    /**
     * @dataProvider queues
     */
    public function testTwoWorkersNeverReceiveTheSameVideo(\Closure $makeQueue, \Closure $idOf, array $row): void
    {
        $workerA = $this->connect();
        $workerB = $this->connect();

        $seeded = [$this->seed($workerA, $row), $this->seed($workerA, $row)];

        $this->assertGreaterThanOrEqual(2, $makeQueue($workerA)->countPending());

        $first = $makeQueue($workerA)->fetch(1);
        $second = $makeQueue($workerB)->fetch(1);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);

        $firstId = $idOf($first[0]);
        $secondId = $idOf($second[0]);
        $this->assertContains($firstId, $seeded);
        $this->assertContains($secondId, $seeded);
        $this->assertNotSame($firstId, $secondId, 'Two workers received the same video.');

        // Both are now claimed; neither may be handed out again.
        foreach ($makeQueue($workerA)->fetch(5) as $job) {
            $this->assertNotContains($idOf($job), $seeded);
        }
    }
}
```

- [x] **Step 2: Confirm it is skipped natively**

Run: `./includes/vendor/bin/phpunit tests/Integration/MariaDbQueueTest.php --display-skipped`
Expected: 6 tests, all skipped with "Runs only in the Docker test environment".

- [x] **Step 3: Run it in Docker if Docker is available**

```bash
./docker-run.sh && ./docker-tests.sh
```

The Docker database loads `deploy/mysql/structure.sql` only when its volume is first created. If the tests fail with `Table 'richmondsunlight.video_jobs' doesn't exist`, the volume predates the new tables: run `docker compose down -v`, then the two commands above again.

Expected: `MariaDbQueueTest` runs 6 tests and all pass. If Docker is not available in your environment, leave this box unticked, say so in your final report, and continue.

If a case fails with an SQL error, the SQL in that queue class is not valid MariaDB. Fix the queue class, not the test.

---

### Task 16: Documentation

**Files:**
- Modify: `AGENTS.md`, `CLAUDE.md`

- [x] **Step 1: Correct `AGENTS.md`**

Make these edits:

1. In "Running CLI Scripts", add to the code block, after the `reset_stale_claims.php` entry:

```bash
# Show pending work and the jobs the pipeline has given up on
php bin/job_status.php

# List rows that share a video or screenshot directory (read-only)
php bin/report_shared_media.php

# Send a video through the whole pipeline again
php bin/requeue_video.php --id=N --apply
```

2. Replace the whole "Queue System" section (from the heading `### Queue System` up to, but not including, `### Directory Structure`) with:

```markdown
### Queue System

The pipeline is database-driven. Each stage's `*JobQueue` class decides what is pending from the `files`, `video_index` and `video_transcript` tables. Two tables used only by this repository add the state those cannot express.

Both are defined with the rest of the shared schema, in the `richmondsunlight.com` repository. This repository never creates tables; `bin/check_schema.php` stops the pipeline if either is missing.

- **`video_jobs`** — one row per video and stage, managed by `JobLedger`. Statuses: `running` (claimed; released after 3 hours), `retry` (failed; hidden until `next_attempt_at`), `failed` (given up after 3 attempts; hidden until reset), `done`.
- **`video_sources`** — the upstream ID of each video, managed by `VideoSourceRegistry`. Imports dedupe on it.

Every queue appends `JobLedger::eligibleSql()` to its `WHERE` clause and exposes `countPending()`. `PendingWork` sums those counts; it is the only definition of "pending", used by `deploy/check-pending-work.sh` and by the `rs-machine` trigger.

Every worker script runs through `StageRunner`: fetch one job, count the attempt, do the work, record the outcome. A stage stops after three consecutive failures, so an outage does not spend an attempt on every pending video.

Two older claim markers remain alongside the ledger:

- **Screenshots:** claimed files get `capture_directory='/pending'`, `capture_rate=0`.
- **Bills/speakers:** a placeholder row is inserted into `video_index` with `ignored='y'`, `raw_text='/pending'`.

`bin/reset_stale_claims.php` releases those after `STALE_CLAIM_MAX_AGE_HOURS` (default 3). A terminal row with `raw_text='/none'`, `ignored='y'` means "the stage ran and found nothing — do not retry."

`php bin/job_status.php` lists what is pending and what has been given up on, with the last error. `--reset-failed` retries everything given up on.

The `src/Queue/` classes (SQS/in-memory `QueueFactory`, `JobDispatcher`) are legacy and are no longer used by the pipeline workers.
```

3. In "Database Integration", replace the line `**No schema migrations required** - works with existing richmondsunlight.com definitions.` with:

```markdown
The shared tables above are used as richmondsunlight.com defines them. Two of the shared schema's tables, `video_sources` and `video_jobs`, are used only by this repository. Like every other table they are defined in the `richmondsunlight.com` repository; `bin/check_schema.php` confirms they exist at the start of every pipeline run.
```

4. In "S3 Bucket Structure", replace the tree in the code block with:

```
video.richmondsunlight.com/
├── house/
│   ├── floor/
│   │   ├── YYYYMMDD-<file id>.mp4
│   │   └── YYYYMMDD-<file id>/        (screenshots and manifest.json)
│   └── committee/{shortname}/
│       ├── YYYYMMDD-<file id>.mp4
│       └── YYYYMMDD-<file id>/
└── senate/
    ├── floor/
    └── committee/{shortname}/
```

   and add this sentence after the code block:

```markdown
The file ID in the key is what keeps two videos from one chamber, committee and day from overwriting each other. Older objects without an ID still exist. `capture_directory` is written once and never moved.
```

5. In "Transcript Processing", replace the line `Audio is resampled to MP3 (mono, 32 Kbps, 16 kHz, <25 MB chunks) before API submission.` with:

```markdown
Audio is resampled to MP3 (mono, 32 kbps, 16 kHz) and split into 20-minute pieces before API submission, because the API rejects files over 25 MB. Each piece's start time is added to its segments.
```

6. In "EC2 Instance Management", replace the numbered list and the sentence after it with:

```markdown
1. Scrapes the House and Senate listings and compares them with its last snapshot
2. Counts pending work with `PendingWork` (the same counts the workers use)
3. Starts the instance if there is a new video or any pending work, and it is not already running
4. Once a day, warns if work is pending and no job has been touched for three days

On boot the instance runs `deploy/run-pipeline.sh --drain`: it makes passes until nothing is pending, the 6-hour limit is reached, or the pending count stops dropping, then shuts itself down.
```

7. In "Known Design Decisions", replace the whole "Duplicate Video Detection" subsection with:

```markdown
### Duplicate Video Detection

A video is identified by its upstream ID: `content_id` for House, `clip_id` for Senate Granicus, `youtube_id` for Senate YouTube, stored in `video_sources`. `VideoImporter` checks that first.

Only when the ID has not been seen does it fall back to resemblance: same chamber, date and committee, with lengths within five minutes of each other. A committee can meet twice in one day, so chamber, date and committee alone are not treated as a match.

`MissingVideoFilter` still pre-filters on `chamber|date|duration_seconds` to avoid re-examining videos that are obviously present.
```

- [x] **Step 2: Correct `CLAUDE.md`**

1. In "S3KeyBuilder and NULL committee_id", replace the whole paragraph under the heading with:

```markdown
`S3KeyBuilder::build()` takes the file ID as its fourth argument and puts it in the key (`chamber/floor/YYYYMMDD-<id>.mp4`). Always pass it. Without it, every video with an unresolved committee on one day gets the same key, and they overwrite each other; that happened in production. `capture_directory` is written once by `ScreenshotGenerator` and must never be moved or recomputed.
```

2. Add this section directly before "## Manual YouTube Upload Pipeline":

```markdown
### Job state lives in video_jobs

Do not add a new "is this pending?" query anywhere. Ask the queue class (`countPending()`) or `PendingWork`. Do not write a worker loop by hand; use `StageRunner`, which counts attempts and records failures in `video_jobs` through `JobLedger`.

New SQL in `src/` must run on SQLite as well as MariaDB, because the unit tests use SQLite: no `NOW()`, no `ON DUPLICATE KEY`. `tests/Integration/MariaDbQueueTest.php` runs the queues on MariaDB, inside Docker only.
```

---

### Final verification for Part A

- [x] Run the whole suite: `./includes/vendor/bin/phpunit`. Result on 2026-10-12: 385 tests (two more than planned, from the diarization setting), 1 failure (the known baseline one, `SpeakerDetectionFixtureTest::testHouseFloorSpeakers`), 10 skipped (6 MariaDB cases, 3 by design in `QueueLedgerTest`, 1 missing fixture).
- [x] Lint every PHP file changed on the branch:

```bash
git diff --name-only master...HEAD -- '*.php' | xargs -n1 php -l
```

Expected: "No syntax errors detected" for every file.

- [x] Check the shell scripts: `bash -n deploy/run-pipeline.sh bin/pipeline_parallel.sh deploy/check-pending-work.sh deploy/deploy.sh`. Expected: silence.
- [ ] Confirm nothing was committed or pushed by you: `git log -1` must show the same commit as when you started.
- [ ] Report to Waldo: which tasks are done, whether Task 15 ran in Docker, and anything that did not match this plan.

---

## Part B: `rs-machine` trigger

This part changes a different repository: `/Users/waldo/Documents/Git/rs-machine`. Do it only after Part A has been merged to `master` by Waldo, because `rs-machine` installs this repository from `dev-master` through Composer and needs the `PendingWork` class.

**Files (in `rs-machine`):**
- Modify: `cron/start_video_processor.php`, `deploy/crontab.txt`

- [x] **Step 1: Update the dependency** (done on `master`, without creating a branch)

```bash
cd /Users/waldo/Documents/Git/rs-machine
git checkout -b video-trigger-on-pending-work
composer update openva/rs-video-processor
```

Confirm the class arrived:

```bash
find includes/vendor -name PendingWork.php
```

Expected: one path ending `src/Jobs/PendingWork.php`. If nothing is printed, Part A is not on `master` yet. Stop and tell Waldo.

- [x] **Step 2: Start on pending work, and save snapshots only after a successful start**

In `cron/start_video_processor.php`:

1. Add `use RichmondSunlight\VideoProcessor\Jobs\PendingWork;` to the `use` statements at the top.

2. Replace everything from the line `$has_new_house = has_new_records($house_records, $cached_house);` to the end of the file with:

```php
$has_new_house = has_new_records($house_records, $cached_house);
$has_new_senate = has_new_records($senate_records, $cached_senate);
$has_new_senate_youtube = has_new_records($senate_youtube_records, $cached_senate_youtube);
$has_new = $has_new_house || $has_new_senate || $has_new_senate_youtube;

// Remember what has been seen only once the instance is known to be up. Saving
// earlier meant a failed start lost the trigger: the video was no longer "new".
$save_snapshots = static function () use (
    $house_snapshot,
    $house_records,
    $senate_snapshot,
    $senate_records,
    $senate_youtube_snapshot,
    $senate_youtube_records
): void {
    save_scraper_snapshot($house_snapshot, $house_records);
    save_scraper_snapshot($senate_snapshot, $senate_records);
    save_scraper_snapshot($senate_youtube_snapshot, $senate_youtube_records);
};

// A backlog is work too. Count what the processor could do right now, using
// the same queue definitions its workers use.
$pending_total = 0;
$last_activity = null;
try {
    $pdo = (new Database())->connect();
    if ($pdo instanceof PDO && class_exists(PendingWork::class)) {
        $pending = new PendingWork($pdo);
        $pending_total = $pending->total();
        $last_activity = $pending->lastActivity();
    }
} catch (Throwable $e) {
    $log->put('Could not count pending video work: ' . $e->getMessage(), 5);
}

// Once a day, say so if work is waiting and nothing has touched it for days.
if ($pending_total > 0 && (int) date('G') === 12) {
    $idle_days = $last_activity === null ? null : (int) floor((time() - $last_activity) / 86400);
    if ($idle_days === null || $idle_days >= 3) {
        $log->put(sprintf(
            'Video pipeline is not making progress: %d item(s) pending, %s.',
            $pending_total,
            $idle_days === null ? 'and no job has ever been recorded' : "no job touched for {$idle_days} days"
        ), 6);
    }
}

if (!$has_new && $pending_total === 0) {
    $save_snapshots();
    $log->put('No new House or Senate videos and no pending video work.', 1);
    exit(0);
}

$log->put(sprintf(
    'Video work found: new house=%s, new senate=%s, new senate-youtube=%s, pending=%d',
    $has_new_house ? 'yes' : 'no',
    $has_new_senate ? 'yes' : 'no',
    $has_new_senate_youtube ? 'yes' : 'no',
    $pending_total
), 3);

// Initialize EC2 client
try {
    $ec2 = new Ec2Client([
        'region' => 'us-east-1',
        'version' => '2016-11-15',
        'credentials' => [
            'key' => AWS_ACCESS_KEY,
            'secret' => AWS_SECRET_KEY,
        ],
    ]);
} catch (Exception $e) {
    $log->put('Could not initialize EC2 client: ' . $e->getMessage(), 6);
    exit(1);
}

// Check instance state
$state = get_instance_state($ec2, VIDEO_PROCESSOR_INSTANCE_ID);

if ($state === null) {
    $log->put('Could not determine video processor instance state.', 5);
    exit(1);
}

if ($state === 'running' || $state === 'pending') {
    $save_snapshots();
    $log->put('Video processor instance is already ' . $state . '.', 2);
    exit(0);
}

if ($state === 'stopping') {
    // Snapshots are deliberately not saved: the next run must try again.
    $log->put('Video processor instance is stopping; will check again later.', 3);
    exit(0);
}

// Instance is stopped (or in another non-running state) - start it
if (start_instance($ec2, VIDEO_PROCESSOR_INSTANCE_ID, $log)) {
    $save_snapshots();
    $log->put('Started video processor instance.', 4);
} else {
    exit(1);
}
```

3. Above that replaced region, delete the three lines that save the snapshots unconditionally:

```php
save_scraper_snapshot($house_snapshot, $house_records);
save_scraper_snapshot($senate_snapshot, $senate_records);
save_scraper_snapshot($senate_youtube_snapshot, $senate_youtube_records);
```

   If those three lines were inside the region you just replaced, they are already gone. Confirm with:

```bash
grep -c "save_scraper_snapshot(" cron/start_video_processor.php
```

Expected: `4` (the function definition, and three calls inside `$save_snapshots`).

4. Replace the header comment sentence `If new videos are found, starts the rs-video-processor EC2 instance.` with `If new videos are found, or the processor has pending work, starts the rs-video-processor EC2 instance.`

- [x] **Step 3: Check hourly**

In `deploy/crontab.txt` replace the line

```
0 12,18,22 * * * /usr/bin/php rs-machine/cron/start_video_processor.php
```

with

```
0 8-23 * * * /usr/bin/php rs-machine/cron/start_video_processor.php
```

- [x] **Step 4: Lint**

```bash
php -l cron/start_video_processor.php
```

Do not commit or push. Tell Waldo the changes are ready for him to review.

---

## Part C: HUMAN rollout

These steps touch production. They are for Waldo, in this order.

- [x] **C1. Create the tables first.** Merge the `video-pipeline-tables` branch of `richmondsunlight.com`, which adds `video_jobs` and `video_sources` to `deploy/mysql/structure.sql`, and create both tables in the production database. They must exist before Part A is deployed: the pipeline checks for them on every run and refuses to start without them, saying so in Slack.

- [x] **C2. Review and merge Part A.** Read the diff of `pipeline-improvements`, then merge to `master`. The merge deploys.

- [x] **C3. Check the instance can take a drain.** `deploy/video-processor.env` sets the worker counts for a 4-vCPU, 8 GB instance: 2 download, 2 screenshot, 1 transcript, 2 bill, 2 speaker and 1 archive. Check free disk (each screenshot worker holds one video plus its frames). To change the counts on the instance without a deploy, put the same variables in `/home/ubuntu/video-processor.env`.

- [x] **C4. First supervised run.** Start the instance, log in over SSH (which holds off auto-shutdown), and watch `journalctl -u video-pipeline -f`. Then:

```bash
php bin/job_status.php          # what is pending, what was given up on, and why
php bin/report_shared_media.php # rows that share a video or screenshots
```

- [ ] **C5. Untangle shared media.** Run `php bin/report_shared_media.php --plan`. It prints three things, to be done in order:

  1. `DELETE` statements for rows that are later copies of a video already imported (same upstream ID). Run them in the database.
  2. `php bin/requeue_video.php --apply ...` commands for the rows that still share a video or screenshot directory with a different meeting. At most one row in each group shows the right video and there is no way to tell which, so all of them are downloaded and processed again under unique keys. Run them on the instance.
  3. Rows that share media but have no recorded source URL. These are mostly from 2018 to 2023 and cannot be re-downloaded automatically; leave them.

- [x] **C6. Merge and deploy Part B** in `rs-machine`, and install the new crontab line. From then on the instance starts whenever work is pending.

- [ ] **C7. Schedule the YouTube fetch.** About 150 Senate videos wait on `scripts/fetch_youtube_uploads.sh`, which must run on a machine with a home internet connection and Firefox logged in to YouTube. On the Mac, save this as `~/Library/LaunchAgents/com.richmondsunlight.youtube-uploads.plist`, then run `launchctl load ~/Library/LaunchAgents/com.richmondsunlight.youtube-uploads.plist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.richmondsunlight.youtube-uploads</string>
    <key>ProgramArguments</key>
    <array>
        <string>/bin/bash</string>
        <string>/Users/waldo/Documents/Git/rs-video-processor/scripts/fetch_youtube_uploads.sh</string>
    </array>
    <key>EnvironmentVariables</key>
    <dict>
        <key>PATH</key>
        <string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin</string>
    </dict>
    <key>StartCalendarInterval</key>
    <dict>
        <key>Hour</key>
        <integer>20</integer>
        <key>Minute</key>
        <integer>0</integer>
    </dict>
    <key>StandardOutPath</key>
    <string>/tmp/rs-youtube-uploads.log</string>
    <key>StandardErrorPath</key>
    <string>/tmp/rs-youtube-uploads.log</string>
</dict>
</plist>
```

- [ ] **C8. After a week, re-run the funnel query** from the 2026-10-11 review and compare with the Background numbers. `php bin/job_status.php` shows what was given up on; the `last_error` column says what to fix next.

---

## Not in this plan

Known and deliberately left out:

- **OCR every few seconds instead of every frame.** Bill detection measures at 4.6 frames per second, which is fast enough once the pipeline runs. Worth doing if runtime cost matters.
- **Download each video once per pass** instead of once per stage (screenshots, transcript, diarization and archive each fetch it again).
- **Retiring the `/pending` and `/none` markers** in `files.capture_directory` and `video_index` in favour of `video_jobs` alone.
- **Backfilling the House catalogue.** There are no House rows for 2021, 17 for 2024 and 10 for 2025; the scraper only reads the newest 50 listings.
- **Senate floor speaker detection.** AWS Transcribe returns anonymous labels (`spk_0`) that can never match a legislator. It is now off by default (`SPEAKER_DIARIZATION=0` in `deploy/video-processor.env`), added outside the numbered tasks; a real replacement is still wanted.
- **Internet Archive identifiers** are built from chamber, date and title, so two same-titled videos on one day share an item.
- **`SenateYouTubeScraper::extractCommitteeFromTitle()`** does not handle the real colon-separated title format (see `CLAUDE.md`).
- **Removing `src/Queue/`**, the unused SQS layer.
