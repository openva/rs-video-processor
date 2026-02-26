#!/usr/bin/env bash
set -euo pipefail

# Run the full video processing pipeline.
# This script is called after boot/update to process any pending videos.
# Runs in a loop for up to 50 minutes, then exits to allow auto-shutdown.

APP_DIR="${APP_DIR:-/home/ubuntu/video-processor}"
GUARD_FILE="${GUARD_FILE:-/home/ubuntu/video-processor.txt}"
MAX_RUNTIME_SECONDS="${MAX_RUNTIME_SECONDS:-3000}"  # 50 minutes default

if [[ ! -f "$GUARD_FILE" ]]; then
  echo "Guard file not found (${GUARD_FILE}); skipping pipeline."
  exit 0
fi

cd "$APP_DIR"

# Record start time
START_TIME=$(date +%s)
echo "Starting video processing pipeline at $(date)"
echo "Will run for up to $((MAX_RUNTIME_SECONDS / 60)) minutes"

# Function to run one pipeline pass
run_pipeline_pass() {
  local pass_num=$1
  echo ""
  echo "=========================================="
  echo "Pipeline pass #${pass_num} started at $(date)"
  echo "=========================================="

  # Step 1: Scrape and sync new videos to database
  echo "=== Step 1: Scraping and syncing videos ==="
  php bin/pipeline.php

  # Step 2a: Generate manifest of Senate YouTube videos awaiting manual upload
  echo "=== Step 2a: Generating upload manifest ==="
  php bin/generate_upload_manifest.php

  # Step 2b: Process any videos already uploaded to the S3 uploads/ staging area
  echo "=== Step 2b: Processing manual uploads ==="
  php bin/process_uploads.php

  # Step 2c: Fetch/download videos that need downloading
  echo "=== Step 2c: Fetching videos ==="
  php bin/fetch_videos.php

  # Step 3: Generate screenshots for videos that need them
  echo "=== Step 3: Generating screenshots ==="
  php bin/generate_screenshots.php --enqueue
  php bin/generate_screenshots.php

  # Step 4: Generate transcripts
  echo "=== Step 4: Generating transcripts ==="
  php bin/generate_transcripts.php --enqueue
  php bin/generate_transcripts.php

  # Step 5: Repair committee classifications
  echo "=== Step 5: Repairing committee classifications ==="
  php bin/repair_committee_classification.php --limit=50

  # Step 6: Repair missing manifest.json files
  echo "=== Step 6: Repairing missing manifests ==="
  php bin/repair_manifests.php --limit=50

  # Step 7: Detect bills from screenshots
  echo "=== Step 7: Detecting bills ==="
  php bin/detect_bills.php --enqueue
  php bin/detect_bills.php

  # Step 8: Detect speakers
  echo "=== Step 8: Detecting speakers ==="
  php bin/detect_speakers.php --enqueue
  php bin/detect_speakers.php

  # Step 9: Resolve raw text to database references
  echo "=== Step 9: Resolving raw text ==="
  php bin/resolve_raw_text.php

  # Step 10: Archive to Internet Archive
  echo "=== Step 10: Archiving videos ==="
  php bin/upload_archive.php

  echo "Pipeline pass #${pass_num} complete at $(date)"
}

# Run pipeline in a loop until timeout
pass_count=0
while true; do
  pass_count=$((pass_count + 1))

  # Run one pass
  run_pipeline_pass "$pass_count"

  # Check elapsed time
  CURRENT_TIME=$(date +%s)
  ELAPSED=$((CURRENT_TIME - START_TIME))
  REMAINING=$((MAX_RUNTIME_SECONDS - ELAPSED))

  echo ""
  echo "Time elapsed: $((ELAPSED / 60)) minutes"
  echo "Time remaining: $((REMAINING / 60)) minutes"

  # Exit if we've exceeded the time limit
  if [[ $ELAPSED -ge $MAX_RUNTIME_SECONDS ]]; then
    echo "Time limit reached after $pass_count pipeline pass(es)"
    break
  fi

  # Brief pause between passes to avoid hammering the system
  echo "Waiting 10 seconds before next pass..."
  sleep 10
done

echo ""
echo "=========================================="
echo "Pipeline completed $pass_count pass(es) at $(date)"
echo "Total runtime: $((ELAPSED / 60)) minutes"
echo "=========================================="
