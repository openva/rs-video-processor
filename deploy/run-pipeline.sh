#!/usr/bin/env bash
set -euo pipefail

# Run the full video processing pipeline.
# This script is called after boot/update to process any pending videos.

APP_DIR="${APP_DIR:-/home/ubuntu/video-processor}"
GUARD_FILE="${GUARD_FILE:-/home/ubuntu/video-processor.txt}"

if [[ ! -f "$GUARD_FILE" ]]; then
  echo "Guard file not found (${GUARD_FILE}); skipping pipeline."
  exit 0
fi

cd "$APP_DIR"

echo "Starting video processing pipeline at $(date)"

# Step 1: Scrape and sync new videos to database
echo "=== Step 1: Scraping and syncing videos ==="
php bin/pipeline.php

# Step 2: Fetch/download videos that need downloading
echo "=== Step 2: Fetching videos ==="
php bin/fetch_videos.php

# Step 3: Generate screenshots for videos that need them
echo "=== Step 3: Generating screenshots ==="
php bin/generate_screenshots.php

# Step 4: Generate transcripts
echo "=== Step 4: Generating transcripts ==="
php bin/generate_transcripts.php

# Step 5: Detect bills from screenshots
echo "=== Step 5: Detecting bills ==="
php bin/detect_bills.php

# Step 6: Detect speakers
echo "=== Step 6: Detecting speakers ==="
php bin/detect_speakers.php

# Step 7: Resolve raw text to database references
echo "=== Step 7: Resolving raw text ==="
php bin/resolve_raw_text.php

# Step 8: Archive to Internet Archive
echo "=== Step 8: Archiving videos ==="
php bin/upload_archive.php

echo "Pipeline complete at $(date)"
