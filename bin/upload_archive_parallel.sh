#!/bin/bash

# Parallel Internet Archive upload worker launcher
# Launches multiple workers to process archive upload jobs from SQS queue in parallel

set -e

# Configuration
WORKERS=${1:-2}           # Number of parallel workers (default: 2)
LIMIT=${2:-5}             # Jobs per worker per iteration (default: 5)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "Starting $WORKERS parallel Internet Archive upload workers (limit: $LIMIT jobs each)"
echo "Press Ctrl+C to stop all workers"

# Array to store background process IDs
pids=()

# Trap Ctrl+C to kill all workers
cleanup() {
    echo ""
    echo "Stopping all workers..."
    for pid in "${pids[@]}"; do
        kill "$pid" 2>/dev/null || true
    done
    exit 0
}
trap cleanup SIGINT SIGTERM

# Launch workers
for i in $(seq 1 "$WORKERS"); do
    echo "Launching worker $i..."
    php "$SCRIPT_DIR/upload_archive.php" --limit="$LIMIT" &
    pids+=($!)
done

# Wait for all workers to complete
echo "All workers started. Waiting for completion..."
for pid in "${pids[@]}"; do
    wait "$pid"
done

echo "All Internet Archive upload workers completed."
