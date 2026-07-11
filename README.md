# Richmond Sunlight Video Processor

The video OCR processor for [Richmond Sunlight](/openva/richmondsunlight.com/).

Richmond Sunlight’s standalone pipeline for finding Virginia General Assembly video, downloading it, generating screenshots, extracting transcripts/bill metadata/speaker metadata, and uploading finalized assets to the Internet Archive.

[![Maintainability](https://api.codeclimate.com/v1/badges/01e66f67b95ef85f85cd/maintainability)](https://codeclimate.com/github/openva/rs-video-processor/maintainability)

---

## Overview

The worker stack mirrors the main `richmondsunlight.com` repo: PHP 8.x, Composer-managed dependencies (vendor dir lives under `includes/`), and the shared `Log`/`Database` helpers. Core modules:

* **Scraper** (`bin/scrape.php`) — collects House/Senate metadata (floor + committee) from Granicus and YouTube, and persists JSON snapshots under `storage/scraper/`.
* **Sync + fetchers** (`bin/fetch_videos.php`, `bin/generate_screenshots.php`) — reconcile scraped data against the `files` table, download MP4s to S3, and create screenshot manifests for downstream analysis.
* **Analysis workers** (`bin/generate_transcripts.php`, `bin/detect_bills.php`, `bin/detect_speakers.php`) — populate `video_transcript` and `video_index` by parsing captions, OCRing chyrons, and mapping speakers. Each script selects pending work directly from the database with `--limit=N`. Speaker detection uses AWS Transcribe for floor videos (House and Senate floor sessions) but skips diarization for committee videos due to cost constraints.
* **Raw text resolution** (`bin/resolve_raw_text.php`) — resolves OCR-extracted text in `video_index` to database references using fuzzy matching for legislators and strict validation for bills. Runs after speaker/bill detection to link raw text entries to `people.id` and `bills.id`.
* **Archival** (`bin/upload_archive.php`) — pushes S3-hosted assets and captions to the Internet Archive and updates `files.path` with the IA URL.

Job orchestration is database-driven: each stage's job-queue class selects pending work with `FOR UPDATE SKIP LOCKED` and claims it (screenshot claims on `files`, bill/speaker placeholder rows in `video_index`), so parallel workers do not double-process. `bin/reset_stale_claims.php` releases claims orphaned by interrupted jobs. The `src/Queue/` SQS/in-memory classes are legacy and no longer used by the pipeline.

When deployed, this will not run any video analysis unless `/home/ubuntu/video-processor.txt` is present (the contents of the file are immaterial). This is to allow it to run on a dual-server configuration, with the scraper etc. running on Machine, reserving video analysis for a more powerful instance.

Sample MP4 fixtures are pulled from `video.richmondsunlight.com/fixtures` (see `bin/fetch_test_fixtures.php`) so integration tests exercise real video. Most CLI tests synthesize temporary MP4s via ffmpeg when needed.

---

## Requirements

* PHP 8.1+ with `ext-pdo`, `ext-json`, `ext-simplexml`.
* Composer (for installing/updating dependencies under `includes/vendor`).
* ffmpeg and curl for screenshot/transcription stages.
* Docker (optional) for the provided test environment (`docker-run.sh`, `docker-tests.sh`).
* AWS credentials (staging/production) for S3 and Transcribe (for speaker diarization of floor videos).

Environment constants mirror the main site and are declared in `includes/settings.inc.php`. At minimum define:

* `PDO_DSN`, `PDO_USERNAME`, `PDO_PASSWORD` (point at staging DB for local work).
* `AWS_ACCESS_KEY`, `AWS_SECRET_KEY`, `AWS_REGION`.
* `OPENAI_KEY` (for transcript generation fallback), `IA_ACCESS_KEY`, `IA_SECRET_KEY`, optional `SLACK_WEBHOOK`.

For Docker development, override those values via environment variables or mount a tailored `includes/settings.inc.php`.

---

## Local Setup

1. To stand up the Docker environment with all tooling (PHP, ffmpeg, OpenAI CLI deps, etc.):

   ```bash
   ./docker-run.sh    # builds/starts the container
   ./docker-tests.sh  # runs the test suite inside Docker
   ```

   Use `./docker-stop.sh` to shut the container down when finished. Inside Docker the dispatcher automatically uses the in-memory queue, and no writes go to production resources as long as you point `PDO_*`/`AWS_*` at staging.

   If you have an OpenAPI key that you want to use, specify that in `includes/settings.inc.php` (which will be copied over automatically from `deploy/settings-docker.inc.php` on first run, or you can copy it manually and add the OpenAPI key at the same time.)

---

## Running Tests

* Native host:

  ```bash
  ./includes/vendor/bin/phpunit
  ```

  Certain suites require ffmpeg and the downloaded MP4 fixtures; the tests self-skip if prerequisites are missing.

* Docker (recommended for parity with CI):

  ```bash
  ./docker-tests.sh
  ```

That script ensures the container is running, verifies dependencies, and launches PHPUnit inside the container. A full run currently executes 40+ unit/integration tests (see `tests/Integration/*` for queue smoke tests).

---

## Key CLI entry points

* `bin/scrape.php` — gather fresh metadata from the public House/Senate sources (Granicus and YouTube).
* `bin/fetch_videos.php --limit=N` — download any missing videos to S3 (`video.richmondsunlight.com`).
* `bin/generate_screenshots.php --limit=N` — generate screenshots for videos that need them.
* `bin/generate_transcripts.php`, `bin/detect_bills.php`, `bin/detect_speakers.php` — same `--limit=N` pattern for the other analysis stages.
* `bin/reset_stale_claims.php` — release stale in-progress claims so interrupted jobs get retried (threshold via `STALE_CLAIM_MAX_AGE_HOURS`, default 3h).
* `bin/resolve_raw_text.php --file-id=N|--limit=N|--dry-run` — resolve OCR text to database references (legislators, bills).
* `bin/upload_archive.php --limit=N` — upload S3-hosted files + captions to the Internet Archive, updating the `files` table.
* `bin/generate_upload_manifest.php` — writes a JSON manifest of Senate YouTube videos not yet on S3 to `uploads/manifest.json` in the video bucket. Runs automatically each pipeline pass.
* `bin/process_uploads.php` — processes videos staged in `uploads/` by the local upload script: extracts metadata, moves each file to its final S3 path, and updates the `files` table. Runs automatically each pipeline pass.

---

## Manual YouTube uploads

When the server's `yt-dlp` cookie authentication fails, Senate YouTube videos can be downloaded locally and uploaded to S3 for the server to process.

**Requirements (local machine):**

```bash
brew install yt-dlp awscli jq
```

AWS CLI must be configured with credentials that allow `s3:GetObject` and `s3:PutObject` on `video.richmondsunlight.com/uploads/`.

**Workflow:**

1. The server generates `uploads/manifest.json` automatically on each pipeline run, listing all Senate YouTube videos not yet downloaded.

2. Run the local script to download and upload them:

   ```bash
   ./scripts/fetch_youtube_uploads.sh
   ```

   The script downloads each video (plus auto-generated captions) via `yt-dlp` and uploads the results to `s3://video.richmondsunlight.com/uploads/`. It is safe to interrupt and re-run — already-uploaded videos are skipped.

3. On the next pipeline run, `bin/process_uploads.php` detects the staged files, moves each to its final S3 path (e.g. `senate/floor/20260223.mp4`), and updates the `files` table. Screenshots, transcripts, and all other downstream processing then proceed normally.

**Troubleshooting slow downloads:**

YouTube throttles downloads when `yt-dlp`'s `n`-signature extraction fails. Update to the latest release to fix this:

```bash
yt-dlp -U
```

All scripts bootstrap via `bin/bootstrap.php`, which wires up the shared `Log` and PDO connection. Workers select pending work directly from the database (`FOR UPDATE SKIP LOCKED` plus claim markers); there is no queue service. The old `--enqueue` flag is accepted but deprecated and ignored.

---

## Parallel Processing

To speed up video processing on GPU-capable instances, parallel worker scripts are available for each stage. These scripts launch multiple workers that claim jobs from the database simultaneously (via `FOR UPDATE SKIP LOCKED` and claim markers), dramatically reducing processing time.

### Full Pipeline (Recommended)

Run the entire pipeline with parallel workers at each stage:

```bash
./bin/pipeline_parallel.sh
```

This script:
1. Scrapes and imports new video metadata
2. Downloads videos to S3 (3 parallel workers)
3. Generates screenshots (4 parallel workers)
4. Processes transcripts, bills, and speakers simultaneously (9 total workers)
5. Uploads to Internet Archive (2 parallel workers)

Configure worker counts via environment variables:

```bash
SCREENSHOT_WORKERS=8 TRANSCRIPT_WORKERS=5 JOBS_PER_WORKER=10 ./bin/pipeline_parallel.sh
```

### Individual Stage Scripts

For catching up on specific stages or debugging, use the individual parallel scripts:

```bash
# Screenshots (default: 4 workers, 5 jobs each)
./bin/generate_screenshots_parallel.sh [workers] [jobs_per_worker]

# Transcripts (default: 3 workers)
./bin/generate_transcripts_parallel.sh [workers] [jobs_per_worker]

# Bill Detection (default: 3 workers)
./bin/detect_bills_parallel.sh [workers] [jobs_per_worker]

# Speaker Detection (default: 3 workers)
./bin/detect_speakers_parallel.sh [workers] [jobs_per_worker]

# Internet Archive Uploads (default: 2 workers)
./bin/upload_archive_parallel.sh [workers] [jobs_per_worker]
```

Examples:

```bash
# Catch up on screenshot backlog with 8 workers processing 10 jobs each
./bin/generate_screenshots_parallel.sh 8 10

# Run transcripts and bill detection simultaneously
./bin/generate_transcripts_parallel.sh 3 5 &
./bin/detect_bills_parallel.sh 3 5 &
wait
```

**Note:** Parallel workers coordinate through database row locks and claim markers, so no queue service is required. The transcript stage has no claim marker, so concurrent transcript workers can occasionally duplicate work (wasted API spend, not data corruption).

---

## Server setup

* Manually install on Ubuntu EC2 instance in `~/video-processor/`
* Run `deploy/deploy.sh`
* Configure the Archive.org setup on the server with `ia configure`
* Recommended: Set up a CloudWatch alarm to shut down the instance if it's idle (it could get expensive fast)

---

## Documentation

Detailed feature documentation is available in [`docs/features/`](docs/features/):

- **[YouTube Scraping](docs/features/youtube-scraping.md)** - Setup and usage of the YouTube video harvester for Virginia Senate videos
- **[Chyron Configuration](docs/features/chyron-configuration.md)** - Configure OCR crop regions for bill and speaker detection across different video types and eras
- **[Raw Text Resolution](docs/features/raw-text-resolution.md)** - OCR text matching to database references (legislators and bills)
- **[Database Migrations](sql/README.md)** - SQL schema changes and migration guide

For LLM development assistance, see [`llm-instructions.md`](llm-instructions.md).
