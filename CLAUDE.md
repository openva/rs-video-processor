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

### S3KeyBuilder and NULL committee_id
`S3KeyBuilder::build()` returns `chamber/floor/YYYYMMDD.mp4` when `committeeShortname` is null. If a video has `committee_id = NULL` due to classification failure, it will collide with the floor video path. When uploading, always re-derive the committee from `video_index_cache` fields (`committee_name`, `event_type`) rather than trusting the DB's `committee_id`.

### MySQL utf8mb3 and 4-byte Unicode
The `webvtt` column (and others) uses `utf8mb3`, which rejects 4-byte Unicode sequences (emoji, supplementary characters) and null bytes. Strip them before inserting:

```php
$captionContents = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $captionContents);
```

### SenateYouTubeScraper title format
Real Senate YouTube titles use `"Senate of Virginia: {body} on YYYY-MM-DD [Status]"` format with colon separators — **not** the dash-separated format that `extractCommitteeFromTitle()` currently handles. This is a known bug: the method needs to be updated to handle the colon-prefix format. See the plan file for details.

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
