#!/usr/bin/env bash
set -euo pipefail

# Check for pending work in the video processing pipeline.
# Prints the total pending count to stdout (last line).
# Prints per-stage breakdown to stderr.
#
# Usage:
#   PENDING_OUTPUT=$(./deploy/check-pending-work.sh)
#   PENDING_COUNT=$(echo "$PENDING_OUTPUT" | tail -1)

APP_DIR="${APP_DIR:-/home/ubuntu/video-processor}"

cd "$APP_DIR"

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
