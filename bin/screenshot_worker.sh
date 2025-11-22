#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

LIMIT="${SCREENSHOT_WORKER_LIMIT:-5}"
SLEEP_SECONDS="${SCREENSHOT_WORKER_IDLE_SECONDS:-30}"

echo "Starting screenshot worker (limit=${LIMIT}, sleep=${SLEEP_SECONDS}s)"

while true; do
  php bin/generate_screenshots.php --limit="${LIMIT}"
  STATUS=$?
  if [[ $STATUS -ne 0 ]]; then
    echo "Screenshot worker run exited with status ${STATUS}" >&2
  fi
  sleep "${SLEEP_SECONDS}"
done
