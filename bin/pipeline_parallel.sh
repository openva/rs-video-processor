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
echo "[1/9] Scraping and importing metadata..."
php "$SCRIPT_DIR/pipeline.php"
echo ""

# Step 2: Generate upload manifest and process staged uploads
echo "[2/9] Processing upload manifest and staged uploads..."
php "$SCRIPT_DIR/generate_upload_manifest.php"
php "$SCRIPT_DIR/process_uploads.php"
php "$SCRIPT_DIR/generate_upload_manifest.php"
echo ""

# Step 3: Download non-YouTube videos to S3 (parallel)
# YouTube videos are skipped by VideoDownloadQueue — they're downloaded locally
# via scripts/fetch_youtube_uploads.sh and staged in S3 uploads/ instead.
echo "[3/9] Downloading videos to S3 ($FETCH_WORKERS workers)..."
pids=()
for i in $(seq 1 "$FETCH_WORKERS"); do
    php "$SCRIPT_DIR/fetch_videos.php" --limit="$JOBS_PER_WORKER" &
    pids+=($!)
done
for pid in "${pids[@]}"; do wait "$pid"; done
echo "✓ Video downloads complete"
echo ""

# Step 4: Repair committee classifications and manifests
echo "[4/9] Repairing committee classifications and manifests..."
php "$SCRIPT_DIR/repair_committee_classification.php" --limit=50
php "$SCRIPT_DIR/repair_manifests.php" --limit=50
echo ""

# Step 5: Generate screenshots (parallel)
echo "[5/9] Generating screenshots ($SCREENSHOT_WORKERS workers)..."
pids=()
for i in $(seq 1 "$SCREENSHOT_WORKERS"); do
    php "$SCRIPT_DIR/generate_screenshots.php" --limit="$JOBS_PER_WORKER" &
    pids+=($!)
done
for pid in "${pids[@]}"; do wait "$pid"; done
echo "✓ Screenshot generation complete"
echo ""

# Step 6: Process transcripts, bills, and speakers in parallel
# These can all run simultaneously since they depend on different inputs
echo "[6/9] Processing transcripts, bills, and speakers in parallel..."
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

# Step 7: Resolve raw text to database references
echo "[7/9] Resolving raw text..."
php "$SCRIPT_DIR/resolve_raw_text.php"
echo ""

# Step 8: Upload to Internet Archive (parallel)
echo "[8/9] Uploading to Internet Archive ($ARCHIVE_WORKERS workers)..."
pids=()
for i in $(seq 1 "$ARCHIVE_WORKERS"); do
    php "$SCRIPT_DIR/upload_archive.php" --limit="$JOBS_PER_WORKER" &
    pids+=($!)
done
for pid in "${pids[@]}"; do wait "$pid"; done
echo "✓ Archive uploads complete"
echo ""

# Step 9: Repair Archive URLs (convert details URLs to direct MP4 URLs)
echo "[9/9] Repairing Archive URLs..."
php "$SCRIPT_DIR/repair_archive_urls.php"
echo "✓ Archive URL repair complete"
echo ""

echo "========================================"
echo "Pipeline Complete!"
echo "========================================"
