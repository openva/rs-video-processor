# Richmond Sunlight Video Processor

The video OCR processor for [Richmond Sunlight](/openva/richmondsunlight.com/).

Richmond Sunlight’s standalone pipeline for finding Virginia General Assembly video, downloading it, generating screenshots, extracting transcripts/bill metadata/speaker metadata, and uploading finalized assets to the Internet Archive.

[![Maintainability](https://api.codeclimate.com/v1/badges/01e66f67b95ef85f85cd/maintainability)](https://codeclimate.com/github/openva/rs-video-processor/maintainability)

---

## Overview

The worker stack mirrors the main `richmondsunlight.com` repo: PHP 8.x, Composer-managed dependencies (vendor dir lives under `includes/`), and the shared `Log`/`Database` helpers. Core modules:

* **Scraper** (`bin/scrape.php`) — collects House/Senate metadata (floor + committee) and persists JSON snapshots under `storage/scraper/`.
* **Sync + fetchers** (`bin/fetch_videos.php`, `bin/generate_screenshots.php`) — reconcile scraped data against the `files` table, download MP4s to S3, and create screenshot manifests for downstream analysis.
* **Analysis workers** (`bin/generate_transcripts.php`, `bin/detect_bills.php`, `bin/detect_speakers.php`) — populate `video_transcript` and `video_index` by parsing captions, OCRing chyrons, and mapping speakers. Each script understands both an enqueue mode (for the lightweight control plane) and a worker mode (for the GPU instance).
* **Archival** (`bin/upload_archive.php`) — pushes S3-hosted assets and captions to the Internet Archive and updates `files.path` with the IA URL.

Job orchestration is handled via `JobDispatcher`. In production the dispatcher speaks to the FIFO queue `rs-video-harvester.fifo` (SQS); in Docker/tests it falls back to an in-memory queue so the full pipeline can run locally without AWS credentials.

When deployed, this will not run any video analysis unless `/home/ubuntu/video-processor.txt` is present (the contents of the file are immaterial). This is to allow it to run on a dual-server configuration, with the scraper etc. running on Machine, reserving video analysis for a more powerful instance.

Sample MP4 fixtures are pulled from `video.richmondsunlight.com/fixtures` (see `bin/fetch_test_fixtures.php`) so integration tests exercise real video. Most CLI tests synthesize temporary MP4s via ffmpeg when needed.

---

## Requirements

* PHP 8.1+ with `ext-pdo`, `ext-json`, `ext-simplexml`.
* Composer (for installing/updating dependencies under `includes/vendor`).
* ffmpeg and curl for screenshot/transcription stages.
* Docker (optional) for the provided test environment (`docker-run.sh`, `docker-tests.sh`).
* AWS credentials (staging/production) for S3 + SQS, and OpenAI credentials for transcript/diarization fallbacks.

Environment constants mirror the main site and are declared in `includes/settings.inc.php`. At minimum define:

* `PDO_DSN`, `PDO_USERNAME`, `PDO_PASSWORD` (point at staging DB for local work).
* `AWS_ACCESS_KEY`, `AWS_SECRET_KEY`, `AWS_REGION`, `SQS_QUEUE_URL`.
* `OPENAI_KEY`, `IA_ACCESS_KEY`, `IA_SECRET_KEY`, optional `SLACK_WEBHOOK`.

For Docker development, override those values via environment variables or mount a tailored `includes/settings.inc.php`.

---

## Local Setup

1. Install Composer dependencies:

   ```bash
   composer install
   ```

2. Copy the shared includes from the main site (or use the `includes/` directory committed to this repo) and adjust `includes/settings.inc.php` for your local DB/keys.
   ```

3. To stand up the Docker environment with all tooling (PHP, ffmpeg, OpenAI CLI deps, etc.):

   ```bash
   ./docker-run.sh    # builds/starts the container
   ./docker-tests.sh  # runs the test suite inside Docker
   ```

   Use `./docker-stop.sh` to shut the container down when finished. Inside Docker the dispatcher automatically uses the in-memory queue, and no writes go to production resources as long as you point `PDO_*`/`AWS_*` at staging.

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

* `bin/scrape.php` — gather fresh metadata from the public House/Senate sources.
* `bin/fetch_videos.php --limit=N` — download any missing videos to S3 (`video.richmondsunlight.com`).
* `bin/generate_screenshots.php --enqueue|--limit=N` — enqueue screenshot jobs (control plane) or run worker mode (analysis box).
* `bin/generate_transcripts.php`, `bin/detect_bills.php`, `bin/detect_speakers.php` — same enqueue/worker pattern for analysis.
* `bin/upload_archive.php --limit=N` — upload S3-hosted files + captions to the Internet Archive, updating the `files` table.

All scripts bootstrap via `bin/bootstrap.php`, which wires up the shared `Log`, PDO connection, and `JobDispatcher`. Use the `--enqueue` flag when running on the lightweight instance so work is pushed to SQS; omit it on the worker to poll/process jobs directly.
