#!/usr/bin/env bash
set -euo pipefail

# Run the full video processing pipeline.
# This script is called after boot/update to process any pending videos.
#
# Default mode: Runs in a loop for up to 110 minutes, then exits to allow auto-shutdown.
# Drain mode (--drain): Runs the parallel pipeline in a loop until all queues are empty.

APP_DIR="${APP_DIR:-/home/ubuntu/video-processor}"
GUARD_FILE="${GUARD_FILE:-/home/ubuntu/video-processor.txt}"
MAX_RUNTIME_SECONDS="${MAX_RUNTIME_SECONDS:-6600}"  # 1 hour 50 minutes default
DRAIN_MODE=false

# Parse arguments
for arg in "$@"; do
  case "$arg" in
    --drain) DRAIN_MODE=true ;;
  esac
done

if [[ ! -f "$GUARD_FILE" ]]; then
  echo "Guard file not found (${GUARD_FILE}); skipping pipeline."
  exit 0
fi

cd "$APP_DIR"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Record start time
START_TIME=$(date +%s)
echo "Starting video processing pipeline at $(date)"

if [[ "$DRAIN_MODE" == true ]]; then
  echo "Running in DRAIN mode (parallel pipeline, loop until all queues empty)"
else
  echo "Will run for up to $((MAX_RUNTIME_SECONDS / 60)) minutes"
fi

# Function to run one sequential pipeline pass (default mode)
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

  # Regenerate manifest so it reflects videos processed in step 2b
  echo "=== Step 2b (post): Regenerating upload manifest ==="
  php bin/generate_upload_manifest.php

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

  # Note: YouTube video downloads (bin/fetch_videos.php) are NOT run here.
  # YouTube cookies expire too quickly for server-side yt-dlp to be reliable.
  # Use scripts/fetch_youtube_uploads.sh locally instead.

  echo "Pipeline pass #${pass_num} complete at $(date)"
}

# Function to run one parallel pipeline pass (drain mode)
run_drain_pass() {
  local pass_num=$1
  echo ""
  echo "=========================================="
  echo "Drain pass #${pass_num} started at $(date)"
  echo "=========================================="

  "$APP_DIR/bin/pipeline_parallel.sh"

  echo "Drain pass #${pass_num} complete at $(date)"
}

# Function to check pending work (drain mode)
check_pending() {
  local output
  output=$("$SCRIPT_DIR/check-pending-work.sh")
  local count
  count=$(echo "$output" | tail -1)
  echo "$output" | head -n -1 >&2 || true
  echo "$count"
}

# Run pipeline in a loop
pass_count=0

if [[ "$DRAIN_MODE" == true ]]; then
  # Drain mode: loop until no pending work remains
  while true; do
    pass_count=$((pass_count + 1))
    run_drain_pass "$pass_count"

    echo ""
    echo "Checking for remaining work..."
    PENDING_COUNT=$(check_pending)

    CURRENT_TIME=$(date +%s)
    ELAPSED=$((CURRENT_TIME - START_TIME))
    echo "Time elapsed: $((ELAPSED / 60)) minutes"

    if [[ "$PENDING_COUNT" -eq 0 ]]; then
      echo "All queues empty — drain complete."
      break
    fi

    echo "$PENDING_COUNT items still pending — starting next pass."
  done
else
  # Default mode: loop until time limit
  while true; do
    pass_count=$((pass_count + 1))
    run_pipeline_pass "$pass_count"

    CURRENT_TIME=$(date +%s)
    ELAPSED=$((CURRENT_TIME - START_TIME))
    REMAINING=$((MAX_RUNTIME_SECONDS - ELAPSED))

    echo ""
    echo "Time elapsed: $((ELAPSED / 60)) minutes"
    echo "Time remaining: $((REMAINING / 60)) minutes"

    if [[ $ELAPSED -ge $MAX_RUNTIME_SECONDS ]]; then
      echo "Time limit reached after $pass_count pipeline pass(es)"
      break
    fi

    # Brief pause between passes to avoid hammering the system
    echo "Waiting 10 seconds before next pass..."
    sleep 10
  done
fi

echo ""
echo "=========================================="
echo "Pipeline completed $pass_count pass(es) at $(date)"
CURRENT_TIME=$(date +%s)
ELAPSED=$((CURRENT_TIME - START_TIME))
echo "Total runtime: $((ELAPSED / 60)) minutes"
echo "=========================================="
