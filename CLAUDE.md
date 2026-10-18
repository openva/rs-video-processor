# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

See `AGENTS.md` for full architecture and design documentation. This file records Claude-specific guidance and session learnings.

## Commands

```bash
# Run all tests (recommended — ensures ffmpeg available)
./docker-tests.sh

# Run tests natively
./includes/vendor/bin/phpunit

# Run a single test file
./includes/vendor/bin/phpunit tests/Scraper/SenateYouTubeScraperTest.php --display-warnings

# Install Composer dependencies (installs to includes/vendor/, not vendor/)
composer install
```

## Key Gotchas

### JSON_PRETTY_PRINT and LIKE patterns
`VideoImporter` stores `video_index_cache` with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`, which writes `"key": "value"` (space after colon). Any SQL `LIKE` pattern matching against `video_index_cache` must include the space:

```php
// CORRECT
'%"youtube_id": "' . $youtubeId . '"%'

// WRONG — matches nothing
'%"youtube_id":"' . $youtubeId . '"%'
```

### video_index.ignored flag
`video_index.ignored` is `'y'`/`'n'`. Job queues insert placeholder rows with `ignored='y'` before work begins. **Every query that selects from `video_index` for processing should add `AND ignored != 'y'`** — otherwise placeholders are processed as real data.

Two kinds of `ignored='y'` rows exist, distinguished by `raw_text`:
- `raw_text='/pending'` — a transient claim marker inserted at fetch time. Released by `bin/reset_stale_claims.php` once older than `STALE_CLAIM_MAX_AGE_HOURS` (default 3h), so an interrupted job is retried.
- `raw_text='/none'` — a permanent "stage ran, found nothing" sentinel. It satisfies the queue's `NOT EXISTS` check so the video is not re-processed forever.

Both must be excluded from any query that reads `video_index` as real results.

### S3KeyBuilder and NULL committee_id

`S3KeyBuilder::build()` takes the file ID as its fourth argument and puts it in the key (`chamber/floor/YYYYMMDD-<id>.mp4`). Always pass it. Without it, every video with an unresolved committee on one day gets the same key, and they overwrite each other; that happened in production. `capture_directory` is written once by `ScreenshotGenerator` and must never be moved or recomputed.

### MySQL utf8mb3 and 4-byte Unicode
The `webvtt` column (and others) uses `utf8mb3`, which rejects 4-byte Unicode sequences (emoji, supplementary characters) and null bytes. Strip them before inserting:

```php
$captionContents = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $captionContents);
```

### SenateYouTubeScraper title format
Real Senate YouTube titles use `"Senate of Virginia: {body} on YYYY-MM-DD [Status]"` format with colon separators. `extractCommitteeFromTitle()` handles this and falls back to the older dash-separated format.

### files.capture_rate means source frames per screenshot
The website (`class.Video.php` in richmondsunlight.com) reads `capture_rate` as the number of source video frames between screenshots, and lays out the "By Time" thumbnails from `fps` and `capture_rate`. Screenshots are taken at one per second, so `ScreenshotGenerator` writes the video's rounded frame rate. Do not write a fixed value: a fixed 60 on 30 fps video made every thumbnail come from half its labelled time. A video without `fps` gets no "By Time" tab; `php bin/repair.php --stage=metadata` fills it in.

### Speaker OCR reads one line
`SpeakerNameOcrEngine` runs Tesseract with `--psm 7` (a single line of text), so each speaker region in `config/chyron_regions.yaml` must cover only the name line. When the House floor region also took in the district line, OCR read none of the House names. `SpeakerDetectionFixtureTest` runs the production speaker classes, so check a region change with it. (Bill OCR uses `TesseractOcrEngine`, `--psm 6`.)

### OCR is skipped on unchanged frames
`BillDetectionProcessor` and `OcrSpeakerExtractor` compare each frame's crop with the last one OCRed (`CropSignature`) and reuse that reading when it looks the same. The thresholds (a pixel moving by more than 15, on more than 3% of the crop) were calibrated by OCRing every frame of the three fixture videos: bills came out identical, every speaker was found. Recalibrate the same way before changing them, and keep comparing with the last frame OCRed rather than the previous frame, or name plates that fade in are missed.

### Screenshots are read from S3, in batches
Give `ScreenshotFetcher` the S3 client: it then reads frames straight from the bucket instead of through Cloudflare (slower, and the source of the 500/502 errors). Stages call `prefetch()` with the next batch of frames (`PREFETCH_CONCURRENCY`) and then `fetch()` each one; a frame that failed to prefetch is fetched again on its own, so errors surface as before. AWS `CommandPool` callbacks receive the command's position, not the generator's key.

### Official captions are offset from the audio
Caption times are early or late by an amount that differs from file to file (from about −15 minutes to +10 seconds) but holds steady within a file. So `TranscriptProcessor` asks `CaptionAligner` to measure each file's captions against five two-minute Whisper samples of `files.path`. When the measurement holds up and is at least 1.5s, it shifts them before writing. Whisper output is never shifted. The result is recorded in `files.caption_alignment` and `files.caption_offset`, because `TranscriptWriter` overwrites `files.webvtt` and nothing else records where a transcript came from. `bin/align_transcripts.php` corrects transcripts stored before this. Shifting rows in place keeps their `legislator_id` and `new_speaker`, and `video_index` times come from the frames, so speakers need no reprocessing.

House captions appear to be timed from the gavel, not from the start of the recording, which explains the large early House offsets (file 15141: 701s early against its sliq.net source).

Never align captions against a copy that is another meeting's video. `TranscriptRealigner` checks the copy's length against the source record's `duration_seconds` (`CopyDurationCheck`), and `bin/find_mismatched_copies.php` lists every copy that fails that check. Senate `duration_seconds` is only to the minute, and records scraped before 2026-10 are whole hours: `SenateScraper` dropped the minutes (`&nbsp;` became U+00A0, which `\s` does not match without `/u`).

### GD cannot scale with IMG_BICUBIC
`imagescale(..., IMG_BICUBIC)` returns false with GD 2.3.3, so the 3x enlargement in `BillTextExtractor` and `SpeakerTextExtractor` never happens; they fall back to the unscaled crop. Measured on the fixture frames, enlarging (with `IMG_BILINEAR_FIXED`) made no difference to accuracy, so it has been left alone. Don't assume the enlargement is happening.

### Job state lives in video_jobs

Do not add a new "is this pending?" query anywhere. Ask the queue class (`countPending()`) or `PendingWork`. Do not write a worker loop by hand; use `StageRunner`, which counts attempts and records failures in `video_jobs` through `JobLedger`.

New SQL in `src/` must run on SQLite as well as MariaDB, because the unit tests use SQLite: no `NOW()`, no `ON DUPLICATE KEY`. `tests/Integration/MariaDbQueueTest.php` runs the queues on MariaDB, inside Docker only.

## Manual YouTube Upload Pipeline

When the server's `yt-dlp` cookie authentication fails for Senate videos:

1. Server generates `s3://video.richmondsunlight.com/uploads/manifest.json` via `bin/generate_upload_manifest.php` (runs automatically in pipeline)
2. Local machine downloads and uploads via `scripts/fetch_youtube_uploads.sh` (requires `yt-dlp`, `awscli`, `jq`)
3. Server processes staged files via `bin/process_uploads.php` (runs automatically in pipeline)

The shell script is safe to interrupt and resume — it skips already-uploaded files by checking `aws s3 ls`.

If downloads are throttled to ~2-3 MiB/s, YouTube's nsig extraction has failed. Fix with `yt-dlp -U`.

## RawTextResolver Sampling

`RawTextResolver::getFilesWithUnresolvedEntries()` uses probabilistic age-based sampling to avoid wasting time on chronically unresolvable old entries:

```sql
AND (
    DATEDIFF(NOW(), f.date) <= 30
    OR RAND() < 30.0 / DATEDIFF(NOW(), f.date)
)
```

Videos from the last 30 days are always attempted; older videos are sampled with decreasing probability.
