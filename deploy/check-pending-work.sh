#!/usr/bin/env bash
set -euo pipefail

# Check for pending work in the video processing pipeline.
# Prints the per-stage breakdown, then the total count alone on the last line.
#
# The counts come from the job queue classes (via bin/pending_work.php), so
# this always agrees with what the workers would actually fetch.
#
# Usage:
#   PENDING_OUTPUT=$(./deploy/check-pending-work.sh)
#   PENDING_COUNT=$(echo "$PENDING_OUTPUT" | tail -1)

APP_DIR="${APP_DIR:-/home/ubuntu/video-processor}"

cd "$APP_DIR"

php bin/pending_work.php 2>&1
