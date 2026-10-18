# Contract — Front-End Read Logic

This directory contains a **portable copy** of the read-side logic from [`richmondsunlight.com/htdocs/includes/class.Video.php`](https://github.com/openva/richmondsunlight.com/blob/master/htdocs/includes/class.Video.php).

## Purpose

The video processor writes data to `files`, `video_index`, and `video_transcript`. The front-end reads that data to render video clips, screenshots, and captions. This contract layer captures the front-end's interpretation logic so we can test it in this repo.

## Files

- **`VideoReadContract.php`** — Ports `by_bill()`, `index_clips()`, `normalize_screenshot_url()`, and `time_to_seconds()` to PDO. Logic and thresholds match the front-end exactly.
- **`ContractValidator.php`** — Validates processor output (missing captions, unresolved raw_text, bad capture_directory format, etc.).

## What the ID columns hold

| Column | Holds | Written by |
|--------|-------|------------|
| `video_index.linked_id` where `type = 'bill'` | `bills.id` | `BillResolver` (via `RawTextResolver`) |
| `video_index.linked_id` where `type = 'legislator'` | **`people.id`**, not `terms.id` | `LegislatorResolver` (via `RawTextResolver`), `LegislatorDirectory` (speaker detection), `MetadataIndexer` (House speaker lists) |
| `video_transcript.legislator_id` | `terms.id` | Only the website's own speaker matching (`class.Video.php`); this pipeline writes NULL |

Most legislators' `people.id` equals one of their `terms.id`, but not for anyone elected since 2024, so joining `video_index.linked_id` to `terms.id` shows the wrong names. The website reads it as a `people.id`, and finds the term from `terms.person_id` and the video's date.

## Key Constants

These values **must match** the front-end:

| Constant | Value | Front-end location |
|----------|-------|--------------------|
| `CLIP_BOUNDARY_THRESHOLD` | 30s | Gap between frames that triggers a clip split |
| `CLIP_PADDING_SECONDS` | 10s | Padding before/after clip boundaries |
| `MIN_FUZZ_FOR_SAME_TIME` | 15s | Minimum duration when start == end |
| `VIDEO_BASE_URL` | `https://video.richmondsunlight.com/` | Base URL for screenshot assets |

## Keeping in Sync

When the front-end's `class.Video.php` changes its clip boundary logic, URL construction, or query patterns, this contract must be updated to match. The contract tests in `tests/Contract/` will catch drift if the data format changes, but logic changes require manual review.
