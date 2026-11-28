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

### Every files row needs a slug
`files.slug` (four random characters; see `VideoSlug`) identifies each video in its URL on the website (`/video/{slug}/`). `VideoImporter` gives every row it inserts a slug, and tries another if one is taken. Any other code that inserts into `files` must do the same: the column is `NOT NULL` in production. `bin/backfill_slugs.php` gave existing rows slugs. The website makes slugs the same way (`Video::new_slug()`); keep the two alike.

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

The Senate committee crop is a wide strip, so when a meeting shows a presentation it reads a line of the slide. `SpeakerNameParser` rejects anything over 50 characters (`MAX_LENGTH`) as not a name, and `SpeakerResultWriter` writes in one transaction: any `legislator` row marks a video done for the speaker queue, so a write that failed partway left it unfinished for good.

### Senate floor bills are written "S.B. 956"
`BillParser` accepts a period after each letter. Until November 2026 it did not, so Tesseract read every Senate floor bill correctly and the parser dropped them all; `BillDetectionFixtureTest` checked the OCR text, not the parser's output, so nothing failed. It now checks what the parser finds. `bin/redo_senate_floor_bills.php` sends those videos back through bill detection.

Preprocessing the crops (enlarging, inverting, black-and-white thresholding) was measured on every fixture plate in November 2026 and changed no result: OCR reads every chyron on screen, and the misses are frames without one.

### OCR is skipped on unchanged frames
`BillDetectionProcessor` and `OcrSpeakerExtractor` compare each frame's crop with the last one OCRed (`CropSignature`) and reuse that reading when it looks the same. The thresholds (a pixel moving by more than 15, on more than 3% of the crop) were calibrated by OCRing every frame of the three fixture videos: bills came out identical, every speaker was found. Recalibrate the same way before changing them, and keep comparing with the last frame OCRed rather than the previous frame, or name plates that fade in are missed.

### Our own files are read from S3, never through video.richmondsunlight.com
`video.richmondsunlight.com` is Cloudflare in front of CloudFront in front of the bucket, and CloudFront bills for every byte. Workers downloading whole videos that way ran up about 700 GB of CloudFront transfer in October 2026, while reading from the bucket within AWS is free. So give `ScreenshotGenerator`, `AudioExtractor` and `InternetArchiveUploader` a `BucketReader`, which downloads our videos from the bucket (and gives ffmpeg presigned S3 URLs for `extractWindows()`), falling back to HTTPS only if S3 fails. `files.path` holds the literal key, `+` and `%` included, so `BucketReader::keyFor()` decodes nothing. `bin/align_transcripts.php` is left without one, because it may run outside AWS.

### Old videos are deliberately not downloaded
`VideoDownloadQueue` downloads floor sessions from 2020 on (`FLOOR_FROM`) and committee and subcommittee meetings from 2024 on (`COMMITTEES_FROM`), judged by `event_type` in `video_index_cache`. Older rows waiting with a source URL are expected, not stuck; see README.md, "Which videos are processed", for why. Every video downloaded costs S3 storage and two screenshot PUTs per second of video, which is what drove the October 2026 S3 bill (Cost Explorer, grouped by usage type and operation, showed it). Once archived, a video's S3 copy is moved to Glacier Instant Retrieval (`ColdStorage`), so don't read archived videos from S3 in bulk: retrieval costs $0.03 a GB.

### Screenshots are read from S3, in batches
Give `ScreenshotFetcher` the S3 client: it then reads frames straight from the bucket instead of through Cloudflare (slower, and the source of the 500/502 errors). Stages call `prefetch()` with the next batch of frames (`PREFETCH_CONCURRENCY`) and then `fetch()` each one; a frame that failed to prefetch is fetched again on its own, so errors surface as before. AWS `CommandPool` callbacks receive the command's position, not the generator's key.

### Official captions are offset from the audio
Caption times are early or late by an amount that differs from file to file (from about −15 minutes to +10 seconds) but holds steady within a file. So `TranscriptProcessor` asks `CaptionAligner` to measure each file's captions against five two-minute Whisper samples of `files.path`. When the measurement holds up and is at least 1.5s, it shifts them before writing. Whisper output is never shifted. The result is recorded in `files.caption_alignment` and `files.caption_offset`, because `TranscriptWriter` overwrites `files.webvtt` and nothing else records where a transcript came from. `bin/align_transcripts.php` corrects transcripts stored before this. Shifting rows in place keeps their `legislator_id` and `new_speaker`, and `video_index` times come from the frames, so speakers need no reprocessing. The transcript stage also takes archived videos, and reads their audio from archive.org, since their S3 copy is in Glacier.

House captions appear to be timed from the gavel, not from the start of the recording, which explains the large early House offsets (file 15141: 701s early against its sliq.net source).

Never align captions against a copy that is another meeting's video. `TranscriptRealigner` checks the copy's length against the source record's `duration_seconds` (`CopyDurationCheck`), and `bin/find_mismatched_copies.php` lists every copy that fails that check. Senate `duration_seconds` is only to the minute, and records scraped before 2026-10 are whole hours: `SenateScraper` dropped the minutes (`&nbsp;` became U+00A0, which `\s` does not match without `/u`).

### YouTube captions roll, and WebVTT escapes ">>"
YouTube's automatic captions (Senate videos from 2025 on, fetched by yt-dlp) show two lines and roll up, so each line appears in three cues: as a cue's new bottom line, in a 10 ms cue of its own, and as the next cue's top line. `CaptionParser::unroll()` drops the fleeting cues and the repeated top lines; captions without fleeting cues (Granicus, House, Whisper) are untouched, so a real repeat ("Aye." "Aye.") survives. Their text also escapes the speaker-change mark `>>` as `&gt;&gt;`, which the website escaped again; `CaptionParser::cleanText()` decodes entities and drops `>>`. Until November 2026 neither was done, and every YouTube transcript was stored with each line three times: `bin/repair_caption_text.php` repairs stored transcripts in place (`CaptionTextRepairer`), keeping the website's speaker matches on the rows it keeps.

Ask yt-dlp for `en-orig`, not `en`. On Senate videos, `en` is YouTube's machine translation of the `en-orig` speech recognition track (same text), and from November 2026 YouTube answered HTTP 429 to every request for it, from any IP address, signed in or not, with or without a PO token. Other videos' `en` still downloaded, so the 429 looked like a rate limit. `VideoDownloadProcessor` and `scripts/fetch_youtube_uploads.sh` fall back to `en` only for videos without `en-orig`, and the script won't upload a video whose captions exist but were refused, since the server would then transcribe it with Whisper at OpenAI's expense.

### OpenAI's spending cap is a wait, not a failure
OpenAI answers HTTP 429 `insufficient_quota` once the monthly spending cap is reached. `OpenAIResponse::json()` turns that into `OpenAIQuotaExceeded` (a `NotReadyYet` for every job), so send OpenAI requests with `'http_errors' => false` and read the reply through it. Every transcript job needs OpenAI (Whisper, or the five alignment samples), so `bin/generate_transcripts.php` then sets them all aside (`TranscriptJobQueue::putAllAside()`) until the start of next month UTC, which keeps `PendingWork` from starting the instance for them, and says so in Slack. To resume sooner after raising the cap: `php bin/job_status.php --resume=transcript`. Anything that catches alignment errors must rethrow `OpenAIQuotaExceeded`: before this was handled, a refused sample was taken for captions that cannot be aligned, and they were stored as `unaligned`, never to be measured again.

### Old videos without captions are transcribed on Waldo's Mac
Nothing from before 2020 is transcribed (`TranscriptJobQueue::TRANSCRIBE_FROM`). A video with captions costs OpenAI about $0.06 (the five timing samples); one without costs $0.36 an hour of video, because Whisper transcribes all of it. Those made almost all of the $78 OpenAI bill of October–November 2026, and the queue still held $262 of them from 2018–2023. So the transcript stage sends uncaptioned videos to OpenAI only from 2024 on (`OPENAI_WHISPER_FROM`). The older ones (`TranscriptJobQueue::backlog()`) are transcribed for free with mlx-whisper on the Mac, by `scripts/transcribe_backlog.sh` (`bin/transcribe_backlog.php --local`). The two trade through the bucket (`TranscriptBacklog`), because the instance is off most of the time and the Mac can't reach the database: every pipeline pass runs `transcribe_backlog.php --sync`, which stores the transcripts the Mac has left under `transcribe-backlog/transcripts/` (`BacklogImporter`), deletes them, and publishes the list still waiting to `transcribe-backlog/backlog.json`. The Mac reads them only from archive.org: reading our S3 copy from outside AWS costs $0.09 a GB, and the archive stage would then pay that again to send the video to the archive. So a backlog video waits until it has been archived. `LocalWhisperTranscriber::transcribe()` splits segments longer than 200 bytes at their words, because `video_transcript.text` is TINYTEXT and `TranscriptWriter` cuts what doesn't fit.

### The instance is not always four CPUs
rs-machine (`cron/start_video_processor.php`) launches each run as a fresh instance from the launch template (`terraform/`), on Spot when it can (`RsMachine\VideoProcessorLauncher`), and picks its type (`RsMachine\VideoProcessorSize`): a c7a.xlarge when download, screenshot, bill or speaker work is waiting or new videos were found, otherwise a t3a.medium (2 vCPU, 4 GiB). In November 2026 the c7a.xlarge ran 10 to 12 hours a day at 0.5% CPU doing only archive uploads. A Spot instance can be reclaimed by AWS mid-run, with two minutes' warning; its claimed jobs are retried once they go stale. A run started small stays small, so anything the pipeline imports during it is processed on two CPUs. The CloudWatch agent (`deploy/cloudwatch-agent.json`, installed by `deploy/provision.sh`) reports memory and disk use, by instance type, under the `CWAgent` namespace.

### Software belongs in provision.sh, not deploy.sh
Disposable instances start from a Packer image (`image/video-processor.pkr.hcl`) built with `deploy/provision.sh` and marked with `/etc/rs-video-processor-image`, and `deploy.sh` skips provisioning when that file exists. So a package or tool added to `deploy.sh` never reaches them: add it to `provision.sh` and rebuild the image. Anything copied onto an instance by hand is lost when it terminates; files it needs at boot come from the deploy bundle or the deploy bucket (the YouTube cookies do). Pipeline output is read in CloudWatch Logs (`/rs-video-processor/pipeline`), since the journal goes with the instance.

### GD cannot scale with IMG_BICUBIC
`imagescale(..., IMG_BICUBIC)` returns false with GD 2.3.3, so the 3x enlargement in `BillTextExtractor` and `SpeakerTextExtractor` never happens; they fall back to the unscaled crop. Measured on the fixture frames, enlarging (with `IMG_BILINEAR_FIXED`) made no difference to accuracy, so it has been left alone. Don't assume the enlargement is happening.

### Internet Archive captions must share the video's name
The archive's player shows captions only when the caption file has the same name as the video (`myvid.mp4` with `myvid.en.vtt`). `ia upload` names each file in the item after the local file, so `InternetArchiveUploader` uploads `{identifier}.mp4` and `{identifier}.en.vtt` (or `.en.srt`). Uploads before November 2026 used random temporary names (`ia_video_….mp4`, `ia_vtt_….vtt`), so their captions don't show. Renaming the caption file to match the video was confirmed to fix that (rs-house-20260309-house-session). `bin/fix_archive_caption_names.php` renames them.

Videos are archived once downloaded and screenshotted, without waiting for a transcript or captions (some never get them); the transcript stage then reads them from archive.org. Captions that change after a video is archived are sent by the `archive_captions` stage (`bin/sync_archive_captions.php`, `ArchiveCaptionUploader`), under the same video-matching name. Anything that writes `files.webvtt` for a video that may be archived must call `ArchiveCaptions::requestIfArchived()` in the same transaction; `TranscriptWriter` and `TranscriptRealigner` do. Captions are sent only once their timing has been checked (`ArchiveCaptions::sendable()`: `caption_alignment` is set).

### Job state lives in video_jobs

Do not add a new "is this pending?" query anywhere. Ask the queue class (`countPending()`) or `PendingWork`. Do not write a worker loop by hand; use `StageRunner`, which counts attempts and records failures in `video_jobs` through `JobLedger`.

A stage waiting on someone else throws `NotReadyYet`: `StageRunner` then puts the job aside as `waiting` without using an attempt. The instance runs only on demand (rs-machine starts it when `PendingWork` counts anything), so a waiting job is left out of `countPending()` (`JobLedger::notWaitingSql()`) but still handed out by `fetch()`: it is checked on a run that is happening anyway, never one started for it. The archive stage does this until the Internet Archive lists the uploaded MP4, and keeps `files.path` on S3 meanwhile, because the website plays `files.path` directly and an `archive.org/details/` page is not a video. A later try asks the archive before uploading, since the identifier (`IdentifierBuilder`) is fixed. `bin/upload_archive.php` checks the waiting uploads first (`ArchiveJobQueue::WAITING`) and doesn't count them against `--limit`, which applies to new uploads (`NOT_WAITING`) only: counted together, as from late October 2026, the checks took half of every run's places, and archiving slowed from about 25 videos a day to 7. When the archive turns an upload away for the request rate ("total_tasks_queued exceeds global_limit": its own task queue is full), that is a wait too, of an hour, with `everyJobWaits` set, so `StageRunner` stops the run rather than download more videos the archive will refuse. The archive being down is handled the same way, with a half-hour wait: a 5xx or 429 from its metadata API, no reply, or its "Temporarily Offline" page instead of JSON. Ask the metadata API through `ArchiveMetadata::fetch()`, which throws that wait, rather than with a Guzzle call of its own.

Most stages' jobs exist for every video their queue matches. `archive_captions` jobs exist only on request: `JobLedger::request()` writes a `pending` row, starting afresh even over a `done` or `failed` one, and no row means nothing to send.

New SQL in `src/` must run on SQLite as well as MariaDB, because the unit tests use SQLite: no `NOW()`, no `ON DUPLICATE KEY`. `tests/Integration/MariaDbQueueTest.php` runs the queues on MariaDB, inside Docker only.

### video_index.raw_text must stay indexed
`HistoricalRawTextLookup` looks up every entry the resolvers can't match by `raw_text`, and nearly all of them are OCR noise that never matches. Without the `raw_text` key on `video_index`, each lookup scanned the whole table (`EXPLAIN`: type ALL, about 4.2 million rows) and took over a second on the database: in November 2026 the "Resolving raw text" step ran five hours, retrying 12,378 entries to resolve 2, with the instance idle, until the 8-hour service timeout ended the run. A slow pipeline step with low CPU on the instance is the database working, not the instance.

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
