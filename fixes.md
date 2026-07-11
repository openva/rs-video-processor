# Video Pipeline Reliability Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the video processing pipeline reliably carry videos through every stage (download → screenshots → transcripts → bills → speakers → archive) by removing the broken SQS layer, restoring the download step, and making job claims recoverable instead of permanent.

**Architecture:** The pipeline becomes purely database-driven. Each stage's `*JobQueue` class already selects pending work from MariaDB with `FOR UPDATE SKIP LOCKED`; the SQS dispatch/receive layer is removed from the worker scripts (it loses and cross-consumes jobs). Job "claims" (rows marked in-progress at fetch time) become recoverable: a new cleanup script resets claims older than a configurable threshold (default 3 hours), and stages that legitimately find nothing record a permanent `/none` sentinel instead of retrying forever.

**Tech Stack:** PHP 8.1+, PHPUnit (SQLite in-memory for unit tests), bash, MariaDB in production, ffmpeg/Tesseract on workers.

---

## Background: why the pipeline "peters out" (read this first)

Diagnosis of the production pipeline found these root causes. Each task below fixes one.

1. **Nothing downloads videos.** Commit `9945dbf` removed `php bin/fetch_videos.php` from `deploy/run-pipeline.sh`, believing it was YouTube-only. It is actually the *only* downloader for House (Sliq) and Senate (Granicus) videos — YouTube is already skipped inside `VideoDownloadQueue`. Every downstream stage requires `path LIKE 'https://video.richmondsunlight.com/%'`, so new videos stall immediately after import.
2. **The SQS layer loses jobs.** All job types share one FIFO queue. Workers that receive a message of another stage's type "skip" it — but a `finally { acknowledge() }` block **deletes it from the queue unprocessed**. Failed jobs are also acknowledged (never retried). The screenshot worker never reads SQS at all (it reads the DB), so every `--enqueue` pass claims 3 videos as in-progress and dispatches messages nobody processes.
3. **Claims are permanent.** `ScreenshotJobQueue` marks claimed rows `capture_directory='/pending', capture_rate=0`; `BillDetectionJobQueue`/`SpeakerJobQueue` insert `ignored='y'` placeholder rows into `video_index`. If the worker fails or the instance auto-shuts-down mid-job, nothing ever resets these — the video becomes permanently invisible to its stage *and* to `deploy/check-pending-work.sh`. The fix releases claims older than a configurable threshold (default **3 hours** via `STALE_CLAIM_MAX_AGE_HOURS`): longer than the slowest single job, short enough that a multi-pass drain session recovers orphans partway through.
4. **Poison pills starve the queues.** Every queue is `ORDER BY date DESC LIMIT 2-3` with no failure tracking. Videos that fail deterministically (emoji in captions vs. utf8mb3 columns; missing manifest; no detectable bills/speakers) are re-fetched every pass, occupying the tiny window forever.
5. **The download query has a typo.** `path NOT LIKE 'https:///video.richmondsunlight.com/%'` (three slashes) never matches real paths, so already-downloaded videos are re-downloaded forever. YouTube rows are also filtered in PHP *after* the SQL `LIMIT`, so they consume the whole batch.
6. **`set -euo pipefail` + 12 sequential steps** means one hard failure aborts the whole session (and the box then auto-shuts-down).
7. **`screenshot-worker.service`** has `WorkingDirectory=/home/ubuntu/rideo-processor` ("rideo") — the unit can never start.

**Explicitly out of scope for this plan** (known issues, tracked separately):
- `SenateYouTubeScraper::extractCommitteeFromTitle()` doesn't handle the real colon-separated title format (documented in `CLAUDE.md`).
- `ScreenshotGenerator::uploadFramesParallel()` is actually sequential (performance, not correctness).
- The transcript stage has no claim marker, so a persistently failing transcript still retries each pass (bounded once Task 2 removes the main deterministic failure). This also means concurrent transcript workers (drain mode runs 3) can occasionally fetch the same videos — `FOR UPDATE SKIP LOCKED` only dedupes while the fetch transactions overlap — duplicating Whisper API spend. Not data corruption (`TranscriptWriter::write()` is delete-then-insert); accepted as a cost risk, with a placeholder-claim pattern as the follow-up fix if it proves expensive.
- Deleting `src/Queue/` and the dispatcher from `bin/bootstrap.php` — left in place, unused by the pipeline, still used by `bin/verify_classification.php`.

**Conventions used throughout:**
- Run tests with `./includes/vendor/bin/phpunit <file> --display-warnings` (native; the tests in this plan are SQLite-based and need no ffmpeg) or `./docker-tests.sh` for the full suite.
- Composer deps live in `includes/vendor/`, not `vendor/`.
- Unit tests use `new PDO('sqlite::memory:')` with hand-created tables — copy the pattern from `tests/Fetcher/VideoDownloadQueueTest.php`.
- The `*JobQueue` classes only run their claim/locking SQL on MySQL/Postgres (`$driver` check), so SQLite tests exercise the SELECT logic but not the claim UPDATE. That is expected.

---

### Task 1: Fix `VideoDownloadQueue` — typo re-downloads finished videos; YouTube rows starve the batch

**Files:**
- Modify: `src/Fetcher/VideoDownloadQueue.php:22-32`
- Test: `tests/Fetcher/VideoDownloadQueueTest.php`

- [ ] **Step 1: Write two failing tests**

Add to `tests/Fetcher/VideoDownloadQueueTest.php` (inside the existing class; it already has `$this->pdo` with a `files` table created in `setUp()`):

```php
public function testFetchSkipsAlreadyDownloadedVideos(): void
{
    $stmt = $this->pdo->prepare('INSERT INTO files (chamber, committee_id, title, date, path, video_index_cache, date_created) VALUES (:chamber, :committee_id, :title, :date, :path, :cache, :created)');
    $stmt->execute([
        ':chamber' => 'house',
        ':committee_id' => null,
        ':title' => 'Floor Session',
        ':date' => '2025-11-19',
        ':path' => 'https://video.richmondsunlight.com/house/floor/20251119.mp4',
        ':cache' => json_encode(['video_url' => 'https://sg001-harmony.sliq.net/download/12345']),
        ':created' => '2025-11-19 12:00:00',
    ]);

    $queue = new VideoDownloadQueue($this->pdo);
    $jobs = $queue->fetch();

    $this->assertCount(0, $jobs, 'A video already downloaded to S3 must not be re-queued for download.');
}

public function testYouTubeRowsDoNotConsumeTheFetchLimit(): void
{
    $stmt = $this->pdo->prepare('INSERT INTO files (chamber, committee_id, title, date, path, video_index_cache, date_created) VALUES (:chamber, :committee_id, :title, :date, :path, :cache, :created)');

    // Five newer YouTube videos (would fill a LIMIT 5 window)
    for ($i = 0; $i < 5; $i++) {
        $stmt->execute([
            ':chamber' => 'senate',
            ':committee_id' => null,
            ':title' => 'Senate video ' . $i,
            ':date' => '2025-11-2' . $i,
            ':path' => '',
            ':cache' => json_encode(['video_url' => 'https://www.youtube.com/watch?v=abc' . $i]),
            ':created' => '2025-11-20 12:00:00',
        ]);
    }

    // One older, downloadable House video
    $stmt->execute([
        ':chamber' => 'house',
        ':committee_id' => null,
        ':title' => 'House Floor',
        ':date' => '2025-11-01',
        ':path' => '',
        ':cache' => json_encode(['video_url' => 'https://sg001-harmony.sliq.net/download/999']),
        ':created' => '2025-11-01 12:00:00',
    ]);

    $queue = new VideoDownloadQueue($this->pdo);
    $jobs = $queue->fetch(5);

    $this->assertCount(1, $jobs, 'YouTube rows must be excluded in SQL so they do not consume the LIMIT.');
    $this->assertSame('https://sg001-harmony.sliq.net/download/999', $jobs[0]->remoteUrl);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./includes/vendor/bin/phpunit tests/Fetcher/VideoDownloadQueueTest.php --display-warnings`
Expected: `testFetchSkipsAlreadyDownloadedVideos` FAILS (1 job returned — the triple-slash typo), `testYouTubeRowsDoNotConsumeTheFetchLimit` FAILS (0 jobs returned — YouTube rows filled the window).

- [ ] **Step 3: Fix the SQL**

In `src/Fetcher/VideoDownloadQueue.php`, replace the `$sql` assignment (currently lines 22-32) with:

```php
        $sql = "SELECT id, chamber, committee_id, title, date, path, video_index_cache
            FROM files
            WHERE (path IS NULL OR path = '' OR (
                path NOT LIKE 'https://video.richmondsunlight.com/%'
                AND path NOT LIKE 'https://archive.org/%'
            ))
              AND (html IS NULL OR html = '')
              AND video_index_cache IS NOT NULL
              AND video_index_cache LIKE '{%'
              AND video_index_cache NOT LIKE '%youtube.com%'
              AND video_index_cache NOT LIKE '%youtu.be%'
            ORDER BY date DESC
            LIMIT :limit";
```

Two changes: `https:///` → `https://` (the typo), and the two `NOT LIKE '%youtube%'` clauses (mirroring `deploy/check-pending-work.sh:32-33`) so YouTube rows never enter the LIMIT window. **Keep** the existing PHP-side `preg_match` YouTube guard at line 58 as defense in depth.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./includes/vendor/bin/phpunit tests/Fetcher/VideoDownloadQueueTest.php --display-warnings`
Expected: all tests PASS (including the two pre-existing ones).

- [ ] **Step 5: Commit**

```bash
git add src/Fetcher/VideoDownloadQueue.php tests/Fetcher/VideoDownloadQueueTest.php
git commit -m "Fix download queue: triple-slash typo re-downloaded finished videos; exclude YouTube in SQL"
```

---

### Task 2: Sanitize transcript text for utf8mb3 columns

The `webvtt`, `transcript`, and `video_transcript.text` columns are `utf8mb3` in production, which **rejects 4-byte Unicode (emoji) and null bytes**. `TranscriptWriter` writes raw text, so one emoji in a caption file makes that video's transcript fail forever — and since the transcript queue is `ORDER BY date DESC LIMIT 3` with no failure tracking, it permanently occupies a slot. (This exact gotcha is documented in `CLAUDE.md`; the strip currently exists only in `bin/process_uploads.php:128`.)

**Files:**
- Modify: `src/Transcripts/TranscriptWriter.php`
- Test: `tests/Transcripts/TranscriptWriterTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Transcripts/TranscriptWriterTest.php` (each test in that file creates its own PDO — follow the same pattern):

```php
public function testStripsFourByteUnicodeAndNullBytes(): void
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

    $writer = new TranscriptWriter($pdo);
    $writer->write(1, [
        ['start' => 0.0, 'end' => 2.0, 'text' => "Hello \u{1F600} world\0!"],
    ]);

    $text = $pdo->query('SELECT text FROM video_transcript WHERE file_id = 1')->fetchColumn();
    $this->assertStringNotContainsString("\u{1F600}", $text);
    $this->assertStringNotContainsString("\0", $text);
    $this->assertStringContainsString('Hello', $text);
    $this->assertStringContainsString('world', $text);

    $file = $pdo->query('SELECT transcript, webvtt FROM files WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    $this->assertStringNotContainsString("\u{1F600}", $file['transcript']);
    $this->assertStringNotContainsString("\u{1F600}", $file['webvtt']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./includes/vendor/bin/phpunit tests/Transcripts/TranscriptWriterTest.php --display-warnings`
Expected: FAIL — SQLite accepts the emoji, so the assertion `assertStringNotContainsString` fails.

- [ ] **Step 3: Implement sanitization**

In `src/Transcripts/TranscriptWriter.php`, at the top of `write()` (right after the `if (empty($segments)) { return; }` guard), sanitize every segment once — `transcript` and `webvtt` are derived from segments, so this covers all three columns:

```php
        foreach ($segments as $i => $segment) {
            $segments[$i]['text'] = $this->sanitizeForUtf8mb3($segment['text']);
        }
```

Add this private method to the class:

```php
    /**
     * Production columns (video_transcript.text, files.transcript, files.webvtt)
     * are utf8mb3, which rejects 4-byte Unicode (emoji, supplementary planes)
     * and null bytes. Strip them so one emoji can't poison a transcript forever.
     * Malformed UTF-8 is repaired (substitute characters) before stripping.
     */
    private function sanitizeForUtf8mb3(string $text): string
    {
        // Repair/replace ill-formed byte sequences first, so the /u regex below
        // never fails on malformed input and silently no-ops. Malformed bytes are
        // also rejected by MySQL's utf8mb3 validation, so this must run regardless.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);
        return str_replace("\0", '', $text);
    }
```

The `mb_convert_encoding` repair-first step matters: `preg_replace` with `/u` returns null for the ENTIRE string if it contains any ill-formed UTF-8 byte anywhere, which would otherwise silently skip the emoji strip exactly when input is garbled (and malformed bytes are themselves rejected by utf8mb3).

Also add two more tests: one for a string containing both an invalid byte sequence and an emoji — e.g. `"Hello \xC3\x28 \u{1F600} world"` — asserting the emoji is stripped and the stored value round-trips as valid UTF-8; and one asserting legitimate ≤3-byte multibyte text like `"Café 你好世界"` survives verbatim.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./includes/vendor/bin/phpunit tests/Transcripts/TranscriptWriterTest.php --display-warnings`
Expected: PASS (all tests in the file).

- [ ] **Step 5: Commit**

```bash
git add src/Transcripts/TranscriptWriter.php tests/Transcripts/TranscriptWriterTest.php
git commit -m "Strip 4-byte Unicode and null bytes before writing transcripts (utf8mb3 columns)"
```

---

### Task 3: Recoverable claims — `StaleClaimCleaner` + timestamped screenshot claims

Claims must become recoverable: a claim older than the threshold (default 3 hours, configurable via `STALE_CLAIM_MAX_AGE_HOURS`) means the worker died (sessions are capped at ~110 minutes), so it should be released for retry. This task also serves as the **one-time repair of the historical backlog** — on first production run it releases every orphaned video accumulated so far.

**Files:**
- Create: `src/Maintenance/StaleClaimCleaner.php`
- Create: `bin/reset_stale_claims.php`
- Modify: `src/Screenshots/ScreenshotJobQueue.php:56` (claim must set `date_modified`)
- Test: `tests/Maintenance/StaleClaimCleanerTest.php` (new directory)

- [ ] **Step 1: Write the failing test**

Create `tests/Maintenance/StaleClaimCleanerTest.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Tests\Maintenance;

use PDO;
use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Maintenance\StaleClaimCleaner;

class StaleClaimCleanerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            capture_directory TEXT,
            capture_rate INTEGER,
            date_modified TEXT
        )');
        $this->pdo->exec('CREATE TABLE video_index (
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
    }

    public function testResetsStaleScreenshotClaimsOnly(): void
    {
        // Fresh rows use the DB clock (datetime('now'), UTC in SQLite) to match
        // the DB-side cutoff; a PHP-local date() would be on a different clock.
        $this->pdo->exec("INSERT INTO files (capture_directory, capture_rate, date_modified) VALUES ('/pending', 0, '2020-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO files (capture_directory, capture_rate, date_modified) VALUES ('/pending', 0, datetime('now'))");
        $this->pdo->exec("INSERT INTO files (capture_directory, capture_rate, date_modified) VALUES ('/house/floor/20250101/', 60, '2020-01-01 00:00:00')");

        $cleaner = new StaleClaimCleaner($this->pdo);
        $counts = $cleaner->clean(3);

        $this->assertSame(1, $counts['screenshot_claims']);
        $rows = $this->pdo->query("SELECT id, capture_directory FROM files ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNull($rows[0]['capture_directory'], 'Stale claim must be reset');
        $this->assertSame('/pending', $rows[1]['capture_directory'], 'Fresh claim must be kept');
        $this->assertSame('/house/floor/20250101/', $rows[2]['capture_directory'], 'Completed video must be untouched');
    }

    public function testResetsClaimsWithNullDateModified(): void
    {
        // Claims made before this fix never set date_modified — treat as stale.
        $this->pdo->exec("INSERT INTO files (capture_directory, capture_rate, date_modified) VALUES ('/pending', 0, NULL)");

        $cleaner = new StaleClaimCleaner($this->pdo);
        $counts = $cleaner->clean(3);

        $this->assertSame(1, $counts['screenshot_claims']);
    }

    public function testDeletesStalePlaceholdersButKeepsSentinelsAndResults(): void
    {
        // Stale claim placeholders (should be deleted)
        $this->pdo->exec("INSERT INTO video_index (file_id, raw_text, type, ignored, date_created) VALUES (1, '/pending', 'bill', 'y', '2020-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO video_index (file_id, raw_text, type, ignored, date_created) VALUES (2, '/pending', 'legislator', 'y', '2020-01-01 00:00:00')");
        // Fresh claim placeholder (should be kept) — DB clock to match the cutoff.
        $this->pdo->exec("INSERT INTO video_index (file_id, raw_text, type, ignored, date_created) VALUES (3, '/pending', 'bill', 'y', datetime('now'))");
        // Terminal none-found sentinel (should be kept)
        $this->pdo->exec("INSERT INTO video_index (file_id, raw_text, type, ignored, date_created) VALUES (4, '/none', 'bill', 'y', '2020-01-01 00:00:00')");
        // Real result (should be kept)
        $this->pdo->exec("INSERT INTO video_index (file_id, raw_text, type, ignored, date_created) VALUES (5, 'HB1234', 'bill', 'n', '2020-01-01 00:00:00')");

        $cleaner = new StaleClaimCleaner($this->pdo);
        $counts = $cleaner->clean(3);

        $this->assertSame(2, $counts['index_placeholders']);
        $remaining = $this->pdo->query("SELECT raw_text FROM video_index ORDER BY file_id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['/pending', '/none', 'HB1234'], $remaining);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./includes/vendor/bin/phpunit tests/Maintenance/StaleClaimCleanerTest.php --display-warnings`
Expected: FAIL with "Class ... StaleClaimCleaner not found".

- [ ] **Step 3: Implement `StaleClaimCleaner`**

Create `src/Maintenance/StaleClaimCleaner.php`:

```php
<?php

namespace RichmondSunlight\VideoProcessor\Maintenance;

use PDO;

/**
 * Releases stale in-progress claims so crashed/interrupted jobs get retried.
 *
 * Two claim mechanisms exist:
 *  - ScreenshotJobQueue marks claimed files capture_directory='/pending', capture_rate=0.
 *  - BillDetectionJobQueue / SpeakerJobQueue insert video_index placeholder rows
 *    (raw_text='/pending', ignored='y') so NOT EXISTS checks skip claimed files.
 *
 * If a worker dies (error, EC2 auto-shutdown mid-job), these claims are never
 * released and the video becomes permanently invisible to its pipeline stage.
 * Claims older than the cutoff are released here; processing sessions are capped
 * at ~110 minutes, so a claim older than the default 3-hour cutoff is dead.
 *
 * Terminal '/none' sentinel rows (written when a stage legitimately finds
 * nothing) are deliberately NOT touched.
 *
 * Caveat — files.date_modified is a shared ON UPDATE column: it is defined
 * `timestamp ... ON UPDATE current_timestamp()`, so ANY write to a '/pending'
 * file row (e.g. TranscriptWriter, committee_id repair scripts) bumps it. A
 * dedicated claim-timestamp column would be cleaner, but this project does no
 * schema migrations, so we accept this. It fails SAFE: a shared write can only
 * make a claim look NEWER, so the cleaner may DELAY recovery of a stuck
 * screenshot claim but will never prematurely reset a still-live one. The
 * bills/speakers half is immune: video_index.date_created has no ON UPDATE.
 */
class StaleClaimCleaner
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{screenshot_claims:int,index_placeholders:int}
     */
    public function clean(int $maxAgeHours = 3): array
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // Build the cutoff DB-side so it shares the database's clock/timezone
        // with the CURRENT_TIMESTAMP / NOW() values stored in date_modified and
        // date_created. A PHP-computed cutoff (date()) would use PHP's timezone,
        // which can differ from the MySQL server's, silently offsetting every
        // comparison by hours. $maxAgeHours is an int, so interpolation is safe.
        if ($driver === 'sqlite') {
            $cutoffExpr = "datetime('now', '-{$maxAgeHours} hours')";
        } else {
            $cutoffExpr = "(NOW() - INTERVAL {$maxAgeHours} HOUR)";
        }

        // date_modified IS NULL covers claims made before claims were timestamped.
        $stmt = $this->pdo->prepare(
            "UPDATE files
             SET capture_directory = NULL, capture_rate = NULL
             WHERE capture_directory = '/pending'
               AND (date_modified IS NULL OR date_modified < {$cutoffExpr})"
        );
        $stmt->execute();
        $screenshotClaims = $stmt->rowCount();

        $stmt = $this->pdo->prepare(
            "DELETE FROM video_index
             WHERE raw_text = '/pending'
               AND ignored = 'y'
               AND type IN ('bill', 'legislator')
               AND date_created < {$cutoffExpr}"
        );
        $stmt->execute();
        $indexPlaceholders = $stmt->rowCount();

        return [
            'screenshot_claims' => $screenshotClaims,
            'index_placeholders' => $indexPlaceholders,
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./includes/vendor/bin/phpunit tests/Maintenance/StaleClaimCleanerTest.php --display-warnings`
Expected: PASS (3 tests).

- [ ] **Step 5: Timestamp screenshot claims**

In `src/Screenshots/ScreenshotJobQueue.php`, the claim UPDATE (line 56) currently doesn't set `date_modified`, so claim age can't be measured. Change:

```php
            $update = $this->pdo->prepare(
                "UPDATE files SET capture_rate = 0, capture_directory = '/pending' WHERE id IN ({$placeholders})"
            );
```

to:

```php
            $update = $this->pdo->prepare(
                "UPDATE files SET capture_rate = 0, capture_directory = '/pending', date_modified = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})"
            );
```

(This branch only runs on MySQL/Postgres, so no SQLite test covers it — verify with `php -l src/Screenshots/ScreenshotJobQueue.php`.)

- [ ] **Step 6: Create the CLI entry point**

Create `bin/reset_stale_claims.php` (mode 755, like other bin scripts):

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

use RichmondSunlight\VideoProcessor\Maintenance\StaleClaimCleaner;

$app = require __DIR__ . '/bootstrap.php';
$log = $app->log;
$pdo = $app->pdo;

// Threshold is configurable so ops can tune recovery speed vs. safety against
// resetting a still-active claim. Default 3h: longer than the slowest single
// job, shorter than a drain session so orphans recover mid-session.
$maxAgeHours = getenv('STALE_CLAIM_MAX_AGE_HOURS');
$maxAgeHours = is_numeric($maxAgeHours) ? max(1, (int) $maxAgeHours) : 3;

$cleaner = new StaleClaimCleaner($pdo);
$counts = $cleaner->clean($maxAgeHours);

if ($counts['screenshot_claims'] > 0) {
    $log->put(sprintf('Released %d stale screenshot claim(s) for retry.', $counts['screenshot_claims']), 3);
}
if ($counts['index_placeholders'] > 0) {
    $log->put(sprintf('Released %d stale bill/speaker claim placeholder(s) for retry.', $counts['index_placeholders']), 3);
}
if ($counts['screenshot_claims'] === 0 && $counts['index_placeholders'] === 0) {
    $log->put('No stale claims found.', 2);
}
```

Run: `chmod +x bin/reset_stale_claims.php && php -l bin/reset_stale_claims.php`
Expected: `No syntax errors detected`.

- [ ] **Step 7: Run the full test suite**

Run: `./includes/vendor/bin/phpunit --display-warnings` (or `./docker-tests.sh` if native ffmpeg is missing)
Expected: PASS (pre-existing skips are fine).

- [ ] **Step 8: Commit**

```bash
git add src/Maintenance/StaleClaimCleaner.php bin/reset_stale_claims.php src/Screenshots/ScreenshotJobQueue.php tests/Maintenance/StaleClaimCleanerTest.php
git commit -m "Release stale job claims for retry instead of orphaning them forever"
```

---

### Task 4: Bill detection — leave claims on failure, record `/none` sentinel on empty results

Current behavior in `BillDetectionProcessor::process()`: it deletes the claim placeholder immediately (line 30), then early-returns on missing manifest / failed manifest load / missing crop config — leaving **zero** `video_index` rows, so the same video is re-fetched on the very next pass, forever. Videos where OCR finds no bills also end with zero rows and loop forever (re-OCRing every screenshot each time).

New semantics:
- **Early failure** (no manifest, manifest load error, no crop): return *without* touching the placeholder. The claim blocks re-fetch until the threshold (default 3h) passes, then `StaleClaimCleaner` releases it → bounded retries.
- **Per-screenshot failure inside the OCR loop** (transient S3 fetch error, corrupt frame, OCR crash): caught per-frame, logged, and skipped — one bad frame must not abort the whole job. Results are collected and only committed after the loop, so the placeholder is never cleared before we have a usable result. Decision after the loop, computed from what we actually processed:
  - Some bills found → commit them (clear placeholder, write rows); if some frames failed, this is a logged partial result, still marked done (real bills are better kept than lost to endless re-OCR).
  - Zero bills found **and** at least one frame failed → we did not fully look, so **leave the placeholder** for a bounded retry (do *not* write `/none`). This also covers the all-frames-failed case.
  - Zero bills found and **every** frame processed cleanly (or the manifest was empty) → write the `/none` sentinel (terminal).
- **OCR ran on the whole manifest, zero bills found**: record one `raw_text='/none', ignored='y'` sentinel row. The queue's `NOT EXISTS` then treats the video as done, permanently. (`RawTextResolver` already filters `ignored != 'y'` — verified at `src/Resolution/RawTextResolver.php:242,274` — so sentinels never reach bill resolution, and the website convention is that `ignored='y'` rows are invisible.)

**Files:**
- Modify: `src/Analysis/Bills/BillResultWriter.php` (add `recordNoneFound()`)
- Modify: `src/Analysis/Bills/BillDetectionProcessor.php:21-68`
- Test: `tests/Analysis/Bills/BillDetectionProcessorTest.php`

- [ ] **Step 1: Write two failing tests**

Add to `tests/Analysis/Bills/BillDetectionProcessorTest.php`. The existing `testProcessesManifest()` shows the construction pattern — reuse it. Both new tests need the same SQLite `video_index` table; extract it or repeat it (the file currently inlines setup per test; repeating is consistent):

```php
public function testRecordsNoneFoundSentinelWhenNoBillsDetected(): void
{
    $fixture = __DIR__ . '/../../fixtures/senate-floor.jpg';
    if (!file_exists($fixture)) {
        $this->markTestSkipped('Fixture file not found: ' . $fixture);
    }

    $loader = $this->createMock(ScreenshotManifestLoader::class);
    $loader->method('load')->willReturn([
        ['timestamp' => 0, 'full' => 'https://video.richmondsunlight.com/senate/floor/20250101/00000000.jpg', 'thumb' => null]
    ]);

    $fetcher = $this->createMock(ScreenshotFetcher::class);
    $fetcher->method('fetch')->willReturnCallback(function () use ($fixture) {
        $temp = tempnam(sys_get_temp_dir(), 'bill_fixture_') . '.jpg';
        if (!copy($fixture, $temp)) {
            throw new \RuntimeException('Unable to copy bill fixture.');
        }
        return $temp;
    });

    // OCR that finds nothing
    $ocr = new class implements OcrEngineInterface {
        public function extractText(string $imagePath): string
        {
            return '';
        }
    };

    $pdo = new PDO('sqlite::memory:');
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
    // Simulate the claim placeholder the job queue inserts at fetch time
    $pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
                VALUES (1, '00:00:00', '00000000', '/pending', 'bill', NULL, 'y', '2025-01-01 00:00:00')");

    $processor = new BillDetectionProcessor(
        $loader,
        $fetcher,
        new BillTextExtractor($ocr),
        new BillParser(),
        new BillResultWriter($pdo),
        new ChamberConfig(),
        new AgendaExtractor(),
        null
    );

    $job = new BillDetectionJob(
        1,
        'senate',
        null,
        'floor',
        'https://video.richmondsunlight.com/senate/floor/20250101/',
        'https://video.richmondsunlight.com/senate/floor/20250101/manifest.json',
        null
    );
    $processor->process($job);

    $rows = $pdo->query("SELECT raw_text, ignored FROM video_index WHERE file_id = 1")->fetchAll(PDO::FETCH_ASSOC);
    $this->assertCount(1, $rows, 'Placeholder must be replaced by exactly one sentinel row.');
    $this->assertSame('/none', $rows[0]['raw_text']);
    $this->assertSame('y', $rows[0]['ignored']);
}

public function testLeavesClaimPlaceholderWhenManifestMissing(): void
{
    $pdo = new PDO('sqlite::memory:');
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
    $pdo->exec("INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created)
                VALUES (1, '00:00:00', '00000000', '/pending', 'bill', NULL, 'y', '2025-01-01 00:00:00')");

    $ocr = new class implements OcrEngineInterface {
        public function extractText(string $imagePath): string
        {
            return '';
        }
    };

    $processor = new BillDetectionProcessor(
        $this->createMock(ScreenshotManifestLoader::class),
        $this->createMock(ScreenshotFetcher::class),
        new BillTextExtractor($ocr),
        new BillParser(),
        new BillResultWriter($pdo),
        new ChamberConfig(),
        new AgendaExtractor(),
        null
    );

    // manifestUrl = null → early return; the claim must survive so the video
    // is retried after StaleClaimCleaner releases it, instead of looping now.
    $job = new BillDetectionJob(1, 'senate', null, 'floor', '', null, null);
    $processor->process($job);

    $row = $pdo->query("SELECT raw_text FROM video_index WHERE file_id = 1")->fetch(PDO::FETCH_ASSOC);
    $this->assertNotFalse($row, 'Claim placeholder must NOT be deleted on early failure.');
    $this->assertSame('/pending', $row['raw_text']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./includes/vendor/bin/phpunit tests/Analysis/Bills/BillDetectionProcessorTest.php --display-warnings`
Expected: both new tests FAIL (`recordNoneFound` doesn't exist yet / placeholder gets deleted by the `clearExisting` at the top of `process()`).

- [ ] **Step 3: Add `recordNoneFound()` to `BillResultWriter`**

Add this method to `src/Analysis/Bills/BillResultWriter.php`:

```php
    /**
     * Record a terminal "no bills found" sentinel. The job queue's NOT EXISTS
     * check treats any video_index row of type 'bill' as done, so this stops
     * the video from being re-OCRed on every pass. ignored='y' keeps it
     * invisible to the website and to RawTextResolver.
     */
    public function recordNoneFound(int $fileId): void
    {
        $this->clearExisting($fileId);
        $now = new DateTimeImmutable('now');
        $stmt = $this->pdo->prepare('INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created) VALUES (:file_id, :time, :screenshot, :raw_text, :type, NULL, "y", :created)');
        $stmt->execute([
            ':file_id' => $fileId,
            ':time' => '00:00:00',
            ':screenshot' => '00000000',
            ':raw_text' => '/none',
            ':type' => 'bill',
            ':created' => $now->format('Y-m-d H:i:s'),
        ]);
    }
```

- [ ] **Step 4: Rework `BillDetectionProcessor::process()`**

Replace the entire `process()` method in `src/Analysis/Bills/BillDetectionProcessor.php` with:

```php
    public function process(BillDetectionJob $job): void
    {
        // Get a fresh DB connection before each job — bill detection takes minutes
        // (downloading + OCR on every screenshot) and the connection times out.
        $this->writer->reconnect();

        // The job queue inserted a claim placeholder (raw_text='/pending',
        // ignored='y') when it fetched this job. On early failure we return
        // WITHOUT touching it: the claim blocks immediate re-fetch, and
        // StaleClaimCleaner releases it after the stale-claim threshold (default 3h) for a retry.

        if (!$job->manifestUrl) {
            $this->logger?->put('No manifest available for file #' . $job->fileId . '; leaving claim for later retry.', 4);
            return;
        }

        try {
            $manifest = $this->manifestLoader->load($job->manifestUrl);
        } catch (\Throwable $e) {
            $this->logger?->put('Manifest load failed for file #' . $job->fileId . ': ' . $e->getMessage() . '; leaving claim for later retry.', 4);
            return;
        }

        $crop = $this->chamberConfig->getCrop($job->chamber, $job->eventType, $job->date);
        if (!$crop) {
            $this->logger?->put('No crop configuration for chamber ' . $job->chamber . '; leaving claim for later retry.', 4);
            return;
        }

        $agenda = $this->agendaExtractor->extract($job->metadata);

        // OCR every screenshot, tolerating per-frame failures — a corrupt frame
        // or a transient S3 fetch error must not abort the whole job. Results are
        // collected and only committed after the loop, so the placeholder claim
        // is never cleared unless we have a usable result. Clearing it before the
        // loop (as this once did) meant a mid-loop exception could either mark the
        // video "done" with partial data or wipe the claim and lose the retry.
        $collected = [];
        $attempted = 0;
        $failed = 0;
        foreach ($manifest as $entry) {
            $attempted++;
            try {
                $imagePath = $this->screenshotFetcher->fetch($entry['full']);
                try {
                    $text = $this->textExtractor->extract($job->chamber, $imagePath, $crop);
                } finally {
                    @unlink($imagePath);
                }
            } catch (\Throwable $e) {
                $this->logger?->put('Screenshot processing failed for file #' . $job->fileId . ' (' . $entry['full'] . '): ' . $e->getMessage(), 4);
                $failed++;
                continue;
            }
            $bills = $this->parser->parse($text);
            if (empty($bills) && !empty($agenda)) {
                $bills = $this->matchAgenda($agenda, $entry['timestamp']);
            }
            $collected[] = [
                'timestamp' => $entry['timestamp'],
                'bills' => $bills,
                'screenshot' => basename($entry['full']),
            ];
        }

        $totalBills = 0;
        foreach ($collected as $item) {
            $totalBills += count($item['bills']);
        }

        // If we found no bills AND some screenshots failed, we did not actually
        // get to look at the whole video — do NOT finalize it as "none found".
        // Leave the claim placeholder intact so StaleClaimCleaner releases it for
        // a bounded retry (this also covers the all-screenshots-failed case).
        if ($totalBills === 0 && $failed > 0) {
            $this->logger?->put(
                'Bill detection incomplete for file #' . $job->fileId
                . ' (' . $failed . '/' . $attempted . ' screenshots failed, no bills found); leaving claim for later retry.',
                4
            );
            return;
        }

        // Commit: clear the placeholder (and any stale results) and write fresh.
        // Reconnect first — the OCR loop above does no DB work and can run for
        // hours on a long video, so the connection from the top of process()
        // is almost certainly dead by now ("MySQL server has gone away").
        $this->writer->reconnect();
        $this->writer->clearExisting($job->fileId);
        foreach ($collected as $item) {
            $this->writer->record($job->fileId, $item['timestamp'], $item['bills'], $item['screenshot']);
        }

        if ($totalBills === 0) {
            // Every screenshot was processed and genuinely no bills were found
            // (or the manifest was empty). Record a terminal sentinel so this
            // video isn't re-OCRed on every future pass.
            $reason = $attempted === 0 ? 'manifest was empty' : 'processed ' . $attempted . ' screenshot(s), found none';
            $this->writer->recordNoneFound($job->fileId);
            $this->logger?->put('No bills detected for file #' . $job->fileId . ' (' . $reason . '); recorded none-found sentinel.', 3);
            return;
        }

        $this->logger?->put(
            'Finished bill detection for file #' . $job->fileId
            . ($failed > 0 ? ' (' . $failed . '/' . $attempted . ' screenshots failed, kept partial results)' : ''),
            3
        );
    }
```

(`matchAgenda()` and the constructor are unchanged. In addition to the two tests in Step 1, add tests covering the per-frame failure paths: partial results kept when some screenshots fail but bills were found; claim left intact when all screenshots fail with no bills; and the writer reconnecting a second time before the post-loop commit.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `./includes/vendor/bin/phpunit tests/Analysis/Bills/ --display-warnings`
Expected: PASS, including the pre-existing `testProcessesManifest` (real bills still recorded) and `BillResultWriterTest`.

- [ ] **Step 6: Commit**

```bash
git add src/Analysis/Bills/BillDetectionProcessor.php src/Analysis/Bills/BillResultWriter.php tests/Analysis/Bills/BillDetectionProcessorTest.php
git commit -m "Bill detection: retryable claims on failure, terminal /none sentinel on empty results"
```

---

### Task 5: Speaker detection — same semantics as Task 4

Current behavior in `SpeakerDetectionProcessor::process()` (lines 58-62): whenever no speakers are found — whether because diarization/OCR *errored* or because there genuinely are none — it calls `clearExisting()` (deleting the claim placeholder) and returns with zero rows, so the video is re-fetched and re-processed (expensive OCR/diarization) on every pass forever. This is the standing state for most committee videos.

New semantics:
- **An extraction step threw** → return without touching the placeholder (retry after `StaleClaimCleaner` releases it).
- **Extraction ran cleanly but found nothing** → record a `/none` sentinel of type `legislator` (permanent).

**Files:**
- Modify: `src/Analysis/Speakers/SpeakerResultWriter.php` (add `recordNoneFound()`)
- Modify: `src/Analysis/Speakers/SpeakerDetectionProcessor.php:21-75`
- Test: `tests/Analysis/Speakers/SpeakerDetectionProcessorTest.php`

- [ ] **Step 1: Write two failing tests**

Add to `tests/Analysis/Speakers/SpeakerDetectionProcessorTest.php` (match the existing file's imports; these tests mock every collaborator, so they need `SpeakerMetadataExtractor`, `DiarizerInterface`, `OcrSpeakerExtractor`, `LegislatorDirectory`, `SpeakerResultWriter`, `SpeakerJob`, `SpeakerDetectionProcessor` imported):

```php
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
    $processor->process($job);
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
    $processor->process($job);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./includes/vendor/bin/phpunit tests/Analysis/Speakers/SpeakerDetectionProcessorTest.php --display-warnings`
Expected: FAIL — `recordNoneFound` doesn't exist; the failure path currently calls `clearExisting`.

- [ ] **Step 3: Add `recordNoneFound()` to `SpeakerResultWriter`**

Add to `src/Analysis/Speakers/SpeakerResultWriter.php` (it already has `clearExisting()` at line 42; place this after it, and add `use DateTimeImmutable;` if the file doesn't already import it):

```php
    /**
     * Record a terminal "no speakers found" sentinel. Any video_index row of
     * type 'legislator' satisfies the job queue's NOT EXISTS check, so this
     * stops the video from being re-processed on every pass. ignored='y'
     * keeps it invisible to the website and to RawTextResolver.
     */
    public function recordNoneFound(int $fileId): void
    {
        $this->clearExisting($fileId);
        $now = new DateTimeImmutable('now');
        $stmt = $this->pdo->prepare('INSERT INTO video_index (file_id, time, screenshot, raw_text, type, linked_id, ignored, date_created) VALUES (:file_id, :time, :shot, :raw, :type, NULL, "y", :created)');
        $stmt->execute([
            ':file_id' => $fileId,
            ':time' => '00:00:00',
            ':shot' => '00000000',
            ':raw' => '/none',
            ':type' => 'legislator',
            ':created' => $now->format('Y-m-d H:i:s'),
        ]);
    }
```

(Match the placeholder-name style of the existing `write()` INSERT in that file, which uses `:shot`/`:raw`.)

- [ ] **Step 4: Rework `SpeakerDetectionProcessor::process()`**

Replace the `process()` method in `src/Analysis/Speakers/SpeakerDetectionProcessor.php` with:

```php
    public function process(SpeakerJob $job): void
    {
        // Get a fresh DB connection before each job — diarization and OCR take
        // minutes and the connection times out between jobs.
        $this->writer->reconnect();

        $hadError = false;

        $segments = $this->metadataExtractor->extract($job->metadata);
        if (empty($segments)) {
            if ($job->manifestUrl && $job->eventType) {
                $this->logger?->put('No metadata speakers for file #' . $job->fileId . ', trying OCR.', 4);
                try {
                    $segments = $this->ocrExtractor->extract(
                        $job->manifestUrl,
                        $job->chamber,
                        $job->eventType,
                        $job->date
                    );
                } catch (\Throwable $e) {
                    $this->logger?->put('OCR extraction failed for file #' . $job->fileId . ': ' . $e->getMessage(), 4);
                    $hadError = true;
                    $segments = [];
                }
            }

            // Only diarize floor videos (not committee videos)
            if (empty($segments) && $this->isFloorVideo($job->metadata, $job->eventType)) {
                $this->logger?->put('No metadata speakers for file #' . $job->fileId . ', running diarization (floor video).', 4);
                try {
                    $segments = $this->diarizer->diarize($job->videoUrl);
                } catch (\Throwable $e) {
                    $this->logger?->put('Diarization failed for file #' . $job->fileId . ': ' . $e->getMessage(), 4);
                    $hadError = true;
                    $segments = [];
                }
            } else {
                $this->logger?->put('Skipping diarization for file #' . $job->fileId . ' (committee video).', 4);
            }
        }

        if (empty($segments)) {
            if ($hadError) {
                // An extraction step failed — leave the claim placeholder from the
                // job queue in place. StaleClaimCleaner releases it after the
                // stale-claim threshold (default 3h), giving a bounded retry
                // instead of an every-pass loop.
                $this->logger?->put('Speaker extraction errored for file #' . $job->fileId . '; leaving claim for later retry.', 4);
                return;
            }
            // Extraction ran cleanly and found nothing — record it permanently
            // so this video isn't re-processed on every pass.
            $this->logger?->put('No speakers found for file #' . $job->fileId . '; recorded none-found sentinel.', 3);
            $this->writer->recordNoneFound($job->fileId);
            return;
        }

        $mapped = array_map(function ($segment) use ($job) {
            $legislatorId = $this->legislators->matchId($segment['name']);
            return [
                'name' => $segment['name'],
                'start' => $segment['start'],
                'legislator_id' => $legislatorId,
            ];
        }, $segments);

        $this->writer->write($job->fileId, $mapped);
        $this->logger?->put('Stored speaker data for file #' . $job->fileId, 3);
    }
```

(`isFloorVideo()` and the constructor are unchanged.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `./includes/vendor/bin/phpunit tests/Analysis/Speakers/ --display-warnings`
Expected: PASS, including pre-existing tests.

- [ ] **Step 6: Commit**

```bash
git add src/Analysis/Speakers/SpeakerDetectionProcessor.php src/Analysis/Speakers/SpeakerResultWriter.php tests/Analysis/Speakers/SpeakerDetectionProcessorTest.php
git commit -m "Speaker detection: retryable claims on error, terminal /none sentinel on clean empty"
```

---

### Task 6: Remove SQS from the four pipeline worker scripts

SQS is confirmed active in production and is actively harmful (see Background #2). All four stage scripts get the same treatment: delete the enqueue/dispatch/receive branches so they always fetch from their DB job queue and process directly. The `--enqueue` flag stays accepted-but-ignored (with a log line) so any stray crontab/scripts don't break. `src/Queue/`, `bin/bootstrap.php`, and `$app->dispatcher` are intentionally left untouched (still used by `bin/verify_classification.php`).

**Files:**
- Modify: `bin/generate_screenshots.php`
- Modify: `bin/generate_transcripts.php`
- Modify: `bin/detect_bills.php`
- Modify: `bin/detect_speakers.php`

- [ ] **Step 1: Rewrite `bin/generate_screenshots.php`**

Replace the entire file with:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use RichmondSunlight\VideoProcessor\Bootstrap\AppBootstrap;
use RichmondSunlight\VideoProcessor\Fetcher\CommitteeDirectory;
use RichmondSunlight\VideoProcessor\Fetcher\S3KeyBuilder;
use RichmondSunlight\VideoProcessor\Fetcher\S3Storage;
use RichmondSunlight\VideoProcessor\Screenshots\ScreenshotGenerator;
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
if (isset($options['enqueue'])) {
    $log->put('--enqueue is deprecated (SQS removed); processing jobs directly from the database.', 2);
}

$s3Client = new S3Client([
    'key' => AWS_ACCESS_KEY,
    'secret' => AWS_SECRET_KEY,
    'region' => 'us-east-1',
    'version' => '2006-03-01',
]);

$bucket = 'video.richmondsunlight.com';
$storage = new S3Storage($s3Client, $bucket);
$keyBuilder = new S3KeyBuilder();

$processed = 0;
for ($i = 0; $i < $limit; $i++) {
    // Fresh connection before each job — screenshot jobs take minutes
    // (download from S3 + ffmpeg + upload frames) and the connection times out.
    $pdo = AppBootstrap::createFreshConnection();
    $committeeDirectory = new CommitteeDirectory($pdo);
    $pdoFactory = fn() => AppBootstrap::createFreshConnection();
    $generator = new ScreenshotGenerator($pdo, $storage, $committeeDirectory, $keyBuilder, $log, null, null, $pdoFactory);
    $queue = new ScreenshotJobQueue($pdo);

    $jobs = $queue->fetch(1);
    if (empty($jobs)) {
        $log->put("No more screenshot jobs after processing {$processed}.", 3);
        break;
    }
    try {
        $generator->process($jobs[0]);
        $processed++;
    } catch (Throwable $e) {
        // The claim ('/pending') stays on this file; StaleClaimCleaner releases
        // it after the stale-claim threshold (default 3h) so the job is retried
        // in a later session.
        $log->put('Screenshot job failed for file #' . $jobs[0]->id . ': ' . $e->getMessage(), 6);
    }
}
$log->put("Processed {$processed} screenshot job(s).", 3);
```

(Behavioral changes: the enqueue/SQS-dispatch mode is gone, and a failed job now counts against the loop bound — the old `while ($processed < $limit)` never incremented on failure.)

- [ ] **Step 2: Rewrite the mode logic in `bin/generate_transcripts.php`**

Delete these pieces: the `use RichmondSunlight\VideoProcessor\Queue\JobType;` and `use ...TranscriptJobPayloadMapper;` imports, the `$mapper = new TranscriptJobPayloadMapper();` line, the `$dispatcher = $app->dispatcher;` line, and everything from `if ($mode === 'enqueue') {` (line 49) through the end of the `foreach ($messages as $message) { ... }` loop (line 114). Replace `$mode = isset($options['enqueue']) ? 'enqueue' : 'worker';` and all the deleted blocks with:

```php
if (isset($options['enqueue'])) {
    $log->put('--enqueue is deprecated (SQS removed); processing jobs directly from the database.', 2);
}

$jobs = $jobQueue->fetch($limit);
if (empty($jobs)) {
    $log->put('No files pending transcript generation.', 3);
    exit(0);
}
processTranscriptJobs($jobs, $processor, $log);
```

Keep the existing `processTranscriptJobs()` and `validateTranscriptContract()` functions unchanged — `TranscriptJobQueue::fetch()` already selects `webvtt`/`srt`, so the SQS-era "re-fetch large columns from DB" step is unnecessary.

- [ ] **Step 3: Rewrite the mode logic in `bin/detect_bills.php`**

Same operation: remove the `JobType` and `BillDetectionJobPayloadMapper` imports, `$mapper = ...`, `$dispatcher = $app->dispatcher;`, and everything from `if ($mode === 'enqueue') {` through the SQS message loop. Replace with:

```php
if (isset($options['enqueue'])) {
    $log->put('--enqueue is deprecated (SQS removed); processing jobs directly from the database.', 2);
}

$jobs = $queue->fetch($limit);
if (empty($jobs)) {
    $log->put('No files pending bill detection.', 3);
    exit(0);
}
processBillJobs($jobs, $processor, $log);
```

Keep `processBillJobs()` unchanged.

- [ ] **Step 4: Rewrite the mode logic in `bin/detect_speakers.php`**

Same operation: remove the `JobType` and `SpeakerJobPayloadMapper` imports, `$mapper = ...`, `$dispatcher = $app->dispatcher;`, and everything from `if ($mode === 'enqueue') {` through the SQS message loop. Replace with:

```php
if (isset($options['enqueue'])) {
    $log->put('--enqueue is deprecated (SQS removed); processing jobs directly from the database.', 2);
}

$jobs = $queue->fetch($limit);
if (empty($jobs)) {
    $log->put('No files pending speaker detection.', 3);
    exit(0);
}
processSpeakerJobs($jobs, $processor, $log);
```

Keep `processSpeakerJobs()` unchanged.

- [ ] **Step 5: Lint and run the full suite**

Run:
```bash
php -l bin/generate_screenshots.php && php -l bin/generate_transcripts.php && php -l bin/detect_bills.php && php -l bin/detect_speakers.php
./includes/vendor/bin/phpunit --display-warnings
```
Expected: no syntax errors; full suite PASSES (the suite exercises the queue/processor classes, not the bin scripts, so nothing should regress).

- [ ] **Step 6: Commit**

```bash
git add bin/generate_screenshots.php bin/generate_transcripts.php bin/detect_bills.php bin/detect_speakers.php
git commit -m "Remove SQS dispatch/receive from pipeline workers; process DB job queues directly"
```

---

### Task 7: Rework `deploy/run-pipeline.sh` and `bin/pipeline_parallel.sh`

Changes: restore the download step; call the stale-claim cleaner each pass; drop the now-meaningless `--enqueue` calls; make each step failure-tolerant (one failing step must not abort the session, which `set -euo pipefail` currently causes); cap drain mode's runtime (it currently loops forever if any stage can never drain).

**Files:**
- Modify: `deploy/run-pipeline.sh`
- Modify: `bin/pipeline_parallel.sh`

- [ ] **Step 1: Rewrite `run_pipeline_pass()` and add `run_step()` in `deploy/run-pipeline.sh`**

Add this helper immediately after the `cd "$APP_DIR"` / `SCRIPT_DIR=` lines:

```bash
# Run one pipeline step; log failures but never abort the session over one step.
run_step() {
  local name="$1"; shift
  echo "=== ${name} ==="
  local status=0
  "$@" || status=$?
  if [[ $status -ne 0 ]]; then
    echo "WARNING: step '${name}' failed with exit code ${status} — continuing with next step"
  fi
}
```

Replace the entire `run_pipeline_pass()` function with:

```bash
# Function to run one sequential pipeline pass (default mode)
run_pipeline_pass() {
  local pass_num=$1
  echo ""
  echo "=========================================="
  echo "Pipeline pass #${pass_num} started at $(date)"
  echo "=========================================="

  run_step "Step 1: Scraping and syncing videos" php bin/pipeline.php

  run_step "Step 2a: Generating upload manifest" php bin/generate_upload_manifest.php
  run_step "Step 2b: Processing manual uploads" php bin/process_uploads.php
  run_step "Step 2c: Regenerating upload manifest" php bin/generate_upload_manifest.php

  # Release claims orphaned by crashed/interrupted jobs so they get retried.
  run_step "Step 3: Releasing stale job claims" php bin/reset_stale_claims.php

  # Download non-YouTube videos (House Sliq, Senate Granicus) to S3.
  # YouTube videos are excluded inside VideoDownloadQueue — cookies expire too
  # quickly for server-side yt-dlp; use scripts/fetch_youtube_uploads.sh locally.
  run_step "Step 4: Downloading videos to S3" php bin/fetch_videos.php --limit=10

  run_step "Step 5: Generating screenshots" php bin/generate_screenshots.php --limit=5

  run_step "Step 6: Generating transcripts" php bin/generate_transcripts.php --limit=5

  run_step "Step 7: Repairing committee classifications" php bin/repair_committee_classification.php --limit=50
  run_step "Step 8: Repairing missing manifests" php bin/repair_manifests.php --limit=50

  run_step "Step 9: Detecting bills" php bin/detect_bills.php --limit=5

  run_step "Step 10: Detecting speakers" php bin/detect_speakers.php --limit=5

  run_step "Step 11: Resolving raw text" php bin/resolve_raw_text.php

  run_step "Step 12: Archiving videos" php bin/upload_archive.php --limit=10

  echo "Pipeline pass #${pass_num} complete at $(date)"
}
```

- [ ] **Step 2: Make drain mode failure-tolerant and time-capped**

In the same file, replace `run_drain_pass()` with:

```bash
# Function to run one parallel pipeline pass (drain mode)
run_drain_pass() {
  local pass_num=$1
  echo ""
  echo "=========================================="
  echo "Drain pass #${pass_num} started at $(date)"
  echo "=========================================="

  local status=0
  "$APP_DIR/bin/pipeline_parallel.sh" || status=$?
  if [[ $status -ne 0 ]]; then
    echo "WARNING: drain pass #${pass_num} exited with code ${status} — continuing"
  fi

  echo "Drain pass #${pass_num} complete at $(date)"
}
```

Near the top of the file, alongside `MAX_RUNTIME_SECONDS`, add:

```bash
MAX_DRAIN_RUNTIME_SECONDS="${MAX_DRAIN_RUNTIME_SECONDS:-21600}"  # 6 hours default
```

And in the drain-mode `while true` loop, after the `echo "Time elapsed..."` line, add a time-limit exit before the `if [[ "$PENDING_COUNT" -eq 0 ]]` check:

```bash
    if [[ $ELAPSED -ge $MAX_DRAIN_RUNTIME_SECONDS ]]; then
      echo "Drain time limit reached ($((MAX_DRAIN_RUNTIME_SECONDS / 60)) minutes) with $PENDING_COUNT items still pending."
      break
    fi
```

- [ ] **Step 3: Update `bin/pipeline_parallel.sh`**

After the Step 2 block (`process_uploads.php` / second `generate_upload_manifest.php`), insert:

```bash
# Step 2.5: Release claims orphaned by crashed/interrupted jobs
echo "[2.5/9] Releasing stale job claims..."
php "$SCRIPT_DIR/reset_stale_claims.php"
echo ""
```

- [ ] **Step 4: Syntax-check both scripts**

Run: `bash -n deploy/run-pipeline.sh && bash -n bin/pipeline_parallel.sh && echo OK`
Expected: `OK`

- [ ] **Step 5: Commit**

```bash
git add deploy/run-pipeline.sh bin/pipeline_parallel.sh
git commit -m "Pipeline: restore download step, release stale claims, tolerate step failures, cap drain runtime"
```

---

### Task 8: Fix `screenshot-worker.service` typo

**Files:**
- Modify: `deploy/services/screenshot-worker.service`

- [ ] **Step 1: Fix the path**

Change `WorkingDirectory=/home/ubuntu/rideo-processor` to `WorkingDirectory=/home/ubuntu/video-processor`. (The typo — "rideo" — has prevented this unit from ever starting.)

- [ ] **Step 2: Commit**

```bash
git add deploy/services/screenshot-worker.service
git commit -m "Fix screenshot-worker.service WorkingDirectory typo (rideo -> video)"
```

---

### Task 9: Update documentation

**Files:**
- Modify: `AGENTS.md`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Update `AGENTS.md`**

Make these edits:
1. In the "Running CLI Scripts" section, replace the `--enqueue|--limit=N` invocations for screenshots/transcripts/bills/speakers with plain `--limit=N` invocations, and add `php bin/reset_stale_claims.php` with a one-line description ("Release stale in-progress claims so interrupted jobs get retried").
2. Replace the "Queue System" section content with a short paragraph: the pipeline is database-driven; each stage's `*JobQueue` selects pending work with `FOR UPDATE SKIP LOCKED` and claims it (screenshots: `capture_directory='/pending'`; bills/speakers: `video_index` placeholder rows with `ignored='y'`, `raw_text='/pending'`); `bin/reset_stale_claims.php` releases claims older than a configurable threshold (`STALE_CLAIM_MAX_AGE_HOURS`, default 3 hours); a `raw_text='/none'`, `ignored='y'` row means "stage ran, found nothing — do not retry." Note that `src/Queue/` (SQS/in-memory) is legacy, no longer used by the pipeline.
3. In "Required Constants", mark `VIDEO_SQS_URL` as legacy/unused.

- [ ] **Step 2: Update `CLAUDE.md`**

In the "video_index.ignored flag" gotcha, extend the note: placeholder rows use `raw_text='/pending'` (transient claim, released by `bin/reset_stale_claims.php` once older than `STALE_CLAIM_MAX_AGE_HOURS`, default 3h) and `raw_text='/none'` (permanent "nothing found" sentinel); both have `ignored='y'` and must be excluded from any processing query.

- [ ] **Step 3: Commit**

```bash
git add AGENTS.md CLAUDE.md
git commit -m "Document DB-driven queue semantics, claim/sentinel rows, and SQS removal"
```

---

## Final verification

- [ ] Run the full test suite: `./docker-tests.sh` (or `./includes/vendor/bin/phpunit --display-warnings` natively). Expected: PASS; pre-existing skips (missing fixtures/API keys) are fine.
- [ ] Lint every changed PHP file: `php -l` on each. Expected: no syntax errors.
- [ ] `bash -n deploy/run-pipeline.sh bin/pipeline_parallel.sh`. Expected: silence.
- [ ] Re-read the Background section and confirm each numbered root cause maps to a completed task: #1→Task 7, #2→Task 6, #3→Tasks 3-5, #4→Tasks 2-5, #5→Task 1, #6→Task 7, #7→Task 8.

## Production rollout notes (human/ops steps — not code tasks)

1. **Purge the SQS queue** after deploying, since nothing will consume its messages anymore: `aws sqs purge-queue --queue-url <rs-video-harvester.fifo URL>`. Leaving stale messages is harmless but untidy. `VIDEO_SQS_URL` can stay defined in production `settings.inc.php`; the pipeline ignores it now.
2. **`sudo systemctl daemon-reload`** on the worker instance after the service-file fix (the deploy script copies unit files but a reload is needed for the changed `WorkingDirectory`).
3. **First run releases the backlog**: `bin/reset_stale_claims.php` will free every historically orphaned video (stuck `/pending` claims and placeholder rows) on its first pass. Expect `deploy/check-pending-work.sh` counts to jump — that's recovered work becoming visible again, not a regression.
4. Given the backlog, consider one supervised `deploy/run-pipeline.sh --drain` session (now time-capped at 6 hours by default) to catch up, rather than waiting for the 110-minute boot sessions to chip away at it.
