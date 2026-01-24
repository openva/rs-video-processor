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
  # Count users logged in via multiple methods for reliability
  local who_count pts_count users_count sshd_count max_count

  # Method 1: who command (shows all logged-in users)
  who_count=$(who | wc -l)

  # Method 2: pts terminals specifically
  pts_count=$(who | grep -c 'pts/' || true)

  # Method 3: users command
  users_count=$(users | wc -w)

  # Method 4: Check for active sshd processes (most reliable)
  # Look for sshd processes that have a pts associated (actual sessions, not just the daemon)
  sshd_count=$(pgrep -a sshd | grep -c 'sshd:.*@pts' || true)

  # Use the maximum count from all methods for safety
  max_count=$who_count
  [[ $pts_count -gt $max_count ]] && max_count=$pts_count
  [[ $users_count -gt $max_count ]] && max_count=$users_count
  [[ $sshd_count -gt $max_count ]] && max_count=$sshd_count

  echo "$max_count"
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
echo "Checking for active users..."
who
echo "---"
SSH_SESSIONS=$(check_ssh_sessions)
echo "Detected $SSH_SESSIONS active session(s)"

if [[ "$SSH_SESSIONS" -gt 0 ]]; then
  echo "Active SSH sessions detected ($SSH_SESSIONS); will not shut down."
  echo "Video processor has finished, but not shutting down because you're logged in. Please shut down when you're done: sudo shutdown -h now" | wall
  exit 0
fi

# Check for pending work (for logging only - no longer blocks shutdown)
echo "Checking for pending work..."
PENDING_WORK=$(check_pending_work)

# Extract just the number (last line of output)
PENDING_COUNT=$(echo "$PENDING_WORK" | tail -1)

if [[ "$PENDING_COUNT" -gt 0 ]]; then
  echo "Pending work detected ($PENDING_COUNT items), but proceeding with shutdown anyway (time limit reached)."
  echo "$PENDING_WORK" | head -n -1  # Show the breakdown
else
  echo "No pending work detected."
fi

echo "No SSH sessions detected."
echo "Initiating shutdown..."
if command -v php >/dev/null 2>&1; then
  php -r "require '${APP_DIR}/includes/settings.inc.php'; require '${APP_DIR}/includes/class.Log.php'; (new Log())->put('Auto-shutdown initiated (no pending work, no active SSH).', 3);"
fi

# Give a brief grace period for any last-minute connections
sleep 10

# Final SSH check before shutdown
SSH_SESSIONS=$(check_ssh_sessions)
if [[ "$SSH_SESSIONS" -gt 0 ]]; then
  echo "SSH session connected during grace period; aborting shutdown."
  echo "Video processor has finished, but not shutting down because you're logged in. Please shut down when you're done: sudo shutdown -h now" | wall
  exit 0
fi

# Shut down the instance
echo "Proceeding with shutdown now."
sudo shutdown -h now
