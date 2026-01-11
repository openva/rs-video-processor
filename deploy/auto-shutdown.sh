#!/usr/bin/env bash
set -euo pipefail

# Auto-shutdown script for the video processor EC2 instance.
# Shuts down the instance when:
#   1. No SSH sessions are active
#   2. The pipeline has completed (no work remaining)
#
# This script is designed to be run after the pipeline completes.

APP_DIR="${APP_DIR:-/home/ubuntu/video-processor}"
GUARD_FILE="${GUARD_FILE:-/home/ubuntu/video-processor.txt}"

if [[ ! -f "$GUARD_FILE" ]]; then
  echo "Guard file not found (${GUARD_FILE}); skipping shutdown check."
  exit 0
fi

# Check for active SSH sessions
check_ssh_sessions() {
  # Count users logged in via SSH (pts terminals)
  local ssh_count
  ssh_count=$(who | grep -c 'pts/' || true)
  echo "$ssh_count"
}

# Check if there's pending work in the database
check_pending_work() {
  cd "$APP_DIR"

  # Use PHP to check for pending work across all pipeline stages
  php -r '
    require_once "vendor/autoload.php";
    require_once "includes/settings.inc.php";

    $db = new Database();
    $pdo = $db->connect();

    // Check for videos needing any processing stage
    $checks = [
      "SELECT COUNT(*) FROM files WHERE video_url IS NOT NULL AND s3_url IS NULL" => "download",
      "SELECT COUNT(*) FROM files WHERE s3_url IS NOT NULL AND screenshot_url IS NULL" => "screenshots",
      "SELECT COUNT(*) FROM files WHERE screenshot_url IS NOT NULL AND transcript IS NULL" => "transcripts",
      "SELECT COUNT(*) FROM files WHERE screenshot_url IS NOT NULL AND bills_detected IS NULL" => "bill detection",
      "SELECT COUNT(*) FROM files WHERE screenshot_url IS NOT NULL AND speakers_detected IS NULL" => "speaker detection",
      "SELECT COUNT(*) FROM files WHERE s3_url IS NOT NULL AND archive_url IS NULL" => "archiving",
    ];

    $totalPending = 0;
    foreach ($checks as $sql => $stage) {
      $count = (int) $pdo->query($sql)->fetchColumn();
      if ($count > 0) {
        fwrite(STDERR, "  - $count videos pending $stage\n");
        $totalPending += $count;
      }
    }

    echo $totalPending;
  ' 2>&1
}

echo "=== Auto-shutdown check at $(date) ==="

# Check SSH sessions first
SSH_SESSIONS=$(check_ssh_sessions)
if [[ "$SSH_SESSIONS" -gt 0 ]]; then
  echo "Active SSH sessions detected ($SSH_SESSIONS); will not shut down."
  exit 0
fi

# Check for pending work
echo "Checking for pending work..."
PENDING_WORK=$(check_pending_work)

# Extract just the number (last line of output)
PENDING_COUNT=$(echo "$PENDING_WORK" | tail -1)

if [[ "$PENDING_COUNT" -gt 0 ]]; then
  echo "Pending work detected; will not shut down."
  echo "$PENDING_WORK" | head -n -1  # Show the breakdown
  exit 0
fi

echo "No SSH sessions and no pending work detected."
echo "Initiating shutdown..."

# Give a brief grace period for any last-minute connections
sleep 10

# Final SSH check before shutdown
SSH_SESSIONS=$(check_ssh_sessions)
if [[ "$SSH_SESSIONS" -gt 0 ]]; then
  echo "SSH session connected during grace period; aborting shutdown."
  exit 0
fi

# Shut down the instance in 2 minutes
sudo shutdown -h 2m
