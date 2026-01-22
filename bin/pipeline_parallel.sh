#!/bin/bash

# Parallel video processing pipeline
# Runs the entire video processing workflow with parallel workers for each stage

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Configuration (can be overridden via environment variables)
FETCH_WORKERS=${FETCH_WORKERS:-3}
SCREENSHOT_WORKERS=${SCREENSHOT_WORKERS:-4}
TRANSCRIPT_WORKERS=${TRANSCRIPT_WORKERS:-3}
BILL_WORKERS=${BILL_WORKERS:-3}
SPEAKER_WORKERS=${SPEAKER_WORKERS:-3}
ARCHIVE_WORKERS=${ARCHIVE_WORKERS:-2}

JOBS_PER_WORKER=${JOBS_PER_WORKER:-5}

echo "========================================"
echo "Parallel Video Processing Pipeline"
echo "========================================"
echo ""

# Step 1: Scrape and import metadata
echo "[1/6] Scraping and importing metadata..."
php "$SCRIPT_DIR/pipeline.php"
echo ""

# Step 2: Download videos to S3 (parallel)
echo "[2/6] Downloading videos to S3 ($FETCH_WORKERS workers)..."
pids=()
for i in $(seq 1 "$FETCH_WORKERS"); do
    php "$SCRIPT_DIR/fetch_videos.php" --limit="$JOBS_PER_WORKER" &
    pids+=($!)
done
for pid in "${pids[@]}"; do wait "$pid"; done
echo "✓ Video downloads complete"
echo ""

# Step 3: Generate screenshots (parallel)
echo "[3/6] Generating screenshots ($SCREENSHOT_WORKERS workers)..."
pids=()
for i in $(seq 1 "$SCREENSHOT_WORKERS"); do
    php "$SCRIPT_DIR/generate_screenshots.php" --limit="$JOBS_PER_WORKER" &
    pids+=($!)
done
for pid in "${pids[@]}"; do wait "$pid"; done
echo "✓ Screenshot generation complete"
echo ""

# Step 4: Process transcripts, bills, and speakers in parallel
# These can all run simultaneously since they depend on different inputs
echo "[4/6] Processing transcripts, bills, and speakers in parallel..."
pids=()

# Transcripts (depends on videos)
for i in $(seq 1 "$TRANSCRIPT_WORKERS"); do
    php "$SCRIPT_DIR/generate_transcripts.php" --limit="$JOBS_PER_WORKER" &
    pids+=($!)
done

# Bill detection (depends on screenshots)
for i in $(seq 1 "$BILL_WORKERS"); do
    php "$SCRIPT_DIR/detect_bills.php" --limit="$JOBS_PER_WORKER" &
    pids+=($!)
done

# Speaker detection (depends on screenshots for House, uses diarization for Senate)
for i in $(seq 1 "$SPEAKER_WORKERS"); do
    php "$SCRIPT_DIR/detect_speakers.php" --limit="$JOBS_PER_WORKER" &
    pids+=($!)
done

for pid in "${pids[@]}"; do wait "$pid"; done
echo "✓ Transcripts, bills, and speakers complete"
echo ""

# Step 5: Upload to Internet Archive (parallel)
echo "[5/6] Uploading to Internet Archive ($ARCHIVE_WORKERS workers)..."
pids=()
for i in $(seq 1 "$ARCHIVE_WORKERS"); do
    php "$SCRIPT_DIR/upload_archive.php" --limit="$JOBS_PER_WORKER" &
    pids+=($!)
done
for pid in "${pids[@]}"; do wait "$pid"; done
echo "✓ Archive uploads complete"
echo ""

# Step 6: Repair Archive URLs (convert details URLs to direct MP4 URLs)
echo "[6/6] Repairing Archive URLs..."
php "$SCRIPT_DIR/repair_archive_urls.php"
echo "✓ Archive URL repair complete"
echo ""

echo "========================================"
echo "Pipeline Complete!"
echo "========================================"
