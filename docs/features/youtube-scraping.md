# YouTube Video Scraping

The video processor supports harvesting videos from the Virginia Senate's YouTube channel (`@SenateofVirginia`) in addition to the Granicus platform. This provides redundancy and captures videos that may only be published on YouTube.

## Overview

**Channel:** [@SenateofVirginia](https://www.youtube.com/@SenateofVirginia)
**Channel ID:** `UC9r1OpPhTY1VmL05bemQD0w`
**Source Identifier:** `senate-youtube` (vs `senate` for Granicus)

The system uses the YouTube Data API v3 to fetch video metadata and yt-dlp to download videos.

## Architecture

### Components

**YouTubeApiClient** (`src/Scraper/YouTube/YouTubeApiClient.php`)
- Interfaces with YouTube Data API v3
- Fetches video listings and detailed metadata
- Parses ISO 8601 durations (PT1H23M45S → seconds)
- Tracks API quota usage

**SenateYouTubeScraper** (`src/Scraper/Senate/SenateYouTubeScraper.php`)
- Implements `VideoSourceScraperInterface`
- Extracts committee names from titles
- Parses dates from video metadata
- Detects event types (committee/subcommittee/floor)
- Returns standardized video records

**VideoDownloadProcessor** (`src/Fetcher/VideoDownloadProcessor.php`)
- Enhanced with yt-dlp support
- Detects YouTube URLs by domain
- Downloads best quality MP4 with audio
- Automatically downloads English captions

### Data Flow

```
YouTube API → SenateYouTubeScraper → VideoScraper → JSON snapshots → Pipeline → Database
```

## Setup

### 1. Install yt-dlp

YouTube video downloads require yt-dlp:

```bash
# macOS (Homebrew)
brew install yt-dlp

# pip (Python)
pip install yt-dlp

# Or download binary from:
# https://github.com/yt-dlp/yt-dlp/releases
```

Verify installation:
```bash
which yt-dlp
yt-dlp --version
```

### 2. Obtain YouTube API Key

**Create Google Cloud Project:**
1. Go to https://console.cloud.google.com/
2. Click "Select a project" → "New Project"
3. Name: "Virginia Legislature Video Scraper"
4. Click "Create"

**Enable YouTube Data API v3:**
1. In the project, go to "APIs & Services" → "Library"
2. Search for "YouTube Data API v3"
3. Click on it and click "Enable"

**Create API Key:**
1. Go to "APIs & Services" → "Credentials"
2. Click "Create Credentials" → "API Key"
3. Copy the generated key
4. Click "Restrict Key" (recommended)
5. Under "API restrictions", select "Restrict key"
6. Choose "YouTube Data API v3"
7. Save

**Configure in Application:**
1. Open `includes/settings.inc.php`
2. Add: `define('YOUTUBE_API_KEY', 'YOUR_KEY_HERE');`
3. Never commit the key to version control

## Usage

The YouTube scraper is automatically included when running the pipeline:

```bash
# Scrape all sources (including YouTube)
php bin/scrape.php

# Run full pipeline
php bin/pipeline.php
```

Output will include videos from three sources:
- House (Granicus)
- Senate (Granicus)
- Senate YouTube

## API Quota

YouTube API has daily quota limits:

**Default quota:** 10,000 units/day
**Typical usage:** ~150 units per scrape
**Daily capacity:** ~66 scrapes

**Cost breakdown per scrape:**
- Search: 100 units
- Video details: ~50 units (for 50 videos @ 1 unit each)

Monitor quota usage at: https://console.cloud.google.com/

## Testing

**Run unit tests:**
```bash
includes/vendor/bin/phpunit tests/Scraper/YouTubeApiClientTest.php
includes/vendor/bin/phpunit tests/Scraper/SenateYouTubeScraperTest.php
```

**Test scraping:**
```bash
php bin/scrape.php
```

**Verify output:**
```bash
# Check for YouTube videos
cat storage/scraper/videos-*.json | jq '.records[] | select(.source=="senate-youtube") | {title, video_url, duration_seconds}'
```

**Test video download:**
```bash
php bin/fetch_videos.php --limit=1
```

## Files Created

### Source Code (3 files)
- `src/Scraper/YouTube/YouTubeApiClient.php` - YouTube Data API v3 client
- `src/Scraper/Senate/SenateYouTubeScraper.php` - YouTube scraper
- `src/Fetcher/VideoDownloadProcessor.php` - Enhanced with yt-dlp support

### Tests (4 files)
- `tests/Scraper/YouTubeApiClientTest.php` - API client tests
- `tests/Scraper/SenateYouTubeScraperTest.php` - Scraper tests
- `tests/fixtures/youtube-live-videos.json` - API response fixture
- `tests/fixtures/youtube-video-details.json` - Video details fixture

### Configuration
- `includes/settings-default.inc.php` - Added `YOUTUBE_API_KEY` constant
- `bin/scrape.php` - Registered `SenateYouTubeScraper`
- `bin/pipeline.php` - Added YouTube scraper to pipeline

## Features

✅ YouTube Data API v3 integration
✅ Video fetching from channel
✅ Video details retrieval (title, description, duration, thumbnails)
✅ ISO 8601 duration parsing
✅ Committee name extraction from titles
✅ Date extraction from video metadata
✅ Event type detection (committee/subcommittee/floor)
✅ yt-dlp video download with MP4 format selection
✅ Automatic caption download (WebVTT format)
✅ API quota tracking and logging
✅ Error handling for quota limits and network failures
✅ Dual-source operation (Granicus + YouTube)
✅ Comprehensive test coverage (10 tests, 57 assertions)

## Troubleshooting

### No YouTube videos found

Check API key configuration:
```bash
php -r "require 'includes/settings.inc.php'; echo YOUTUBE_API_KEY ?? 'NOT SET';"
```

### yt-dlp not found error

Verify yt-dlp is installed:
```bash
which yt-dlp
```

If not installed, follow installation steps above.

### API quota exceeded

Monitor quota at: https://console.cloud.google.com/

If consistently hitting limits, consider:
- Reducing scrape frequency
- Requesting quota increase from Google

## Disabling YouTube Scraper

If issues arise, temporarily disable the YouTube scraper:

**In `bin/scrape.php`:**
```php
// Comment out YouTube scraper
// $senateYouTube = new SenateYouTubeScraper($http, YOUTUBE_API_KEY ?? '');

// Update VideoScraper to exclude YouTube
$scraper = new VideoScraper([$house, $senateGranicus], $writer, $logger);
```

**In `bin/pipeline.php`:**
```php
// Comment out YouTube scraper
// $senateYouTubeScraper = new SenateYouTubeScraper(...);

// Remove from array_merge
$newRecords = array_merge(
    $houseScraper->scrape(),
    $senateScraper->scrape()
    // $senateYouTubeScraper->scrape()
);
```

The Granicus scraper continues working normally with no data loss.

## Implementation Status

**Completed:** January 16, 2026
**Tests:** 10 tests, 57 assertions - all passing
**Status:** Production ready
