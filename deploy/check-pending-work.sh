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
  require_once "includes/vendor/autoload.php";
  require_once "includes/settings.inc.php";

  $db = new Database();
  $pdo = $db->connect();

  // Check for videos needing any processing stage.
  // These queries mirror the WHERE clauses in each stage's job queue class.
  $checks = [
    // VideoDownloadQueue: has metadata but no S3/archive path (excludes YouTube —
    // those are downloaded locally via scripts/fetch_youtube_uploads.sh)
    "SELECT COUNT(*) FROM files
     WHERE (path IS NULL OR path = '' OR (path NOT LIKE 'https://video.richmondsunlight.com/%' AND path NOT LIKE 'https://archive.org/%'))
       AND (html IS NULL OR html = '')
       AND video_index_cache IS NOT NULL AND video_index_cache LIKE '{%'
       AND video_index_cache NOT LIKE '%youtube.com%'
       AND video_index_cache NOT LIKE '%youtu.be%'" => "download",

    // ScreenshotJobQueue: downloaded but no screenshots
    "SELECT COUNT(*) FROM files
     WHERE path LIKE 'https://video.richmondsunlight.com/%'
       AND (capture_directory IS NULL OR capture_directory = ''
            OR (capture_directory NOT LIKE '/%' AND capture_directory NOT LIKE 'https://%')
            OR capture_rate IS NULL)" => "screenshots",

    // TranscriptJobQueue: downloaded but no transcript rows
    "SELECT COUNT(*) FROM files f
     WHERE f.path LIKE 'https://video.richmondsunlight.com/%'
       AND NOT EXISTS (SELECT 1 FROM video_transcript vt WHERE vt.file_id = f.id)" => "transcripts",

    // BillDetectionJobQueue: has screenshots but no bill detection
    "SELECT COUNT(*) FROM files f
     WHERE f.capture_directory IS NOT NULL AND f.capture_directory != ''
       AND (f.capture_directory LIKE '/%' OR f.capture_directory LIKE 'https://%')
       AND f.capture_directory != '/pending'
       AND f.date >= '2020-01-01'
       AND NOT EXISTS (SELECT 1 FROM video_index vi WHERE vi.file_id = f.id AND vi.type = 'bill')" => "bill detection",

    // SpeakerJobQueue: downloaded but no speaker detection
    "SELECT COUNT(*) FROM files f
     WHERE (f.path LIKE 'https://video.richmondsunlight.com/%' OR f.path LIKE 'https://archive.org/%')
       AND f.date >= '2020-01-01'
       AND (f.capture_directory IS NULL OR f.capture_directory != '/pending')
       AND NOT EXISTS (SELECT 1 FROM video_index vi WHERE vi.file_id = f.id AND vi.type = 'legislator')" => "speaker detection",

    // ArchiveJobQueue: fully processed but not archived
    "SELECT COUNT(*) FROM files f
     WHERE f.path LIKE 'https://video.richmondsunlight.com/%'
       AND (f.webvtt IS NOT NULL OR f.srt IS NOT NULL)
       AND f.capture_directory IS NOT NULL AND f.capture_directory != ''
       AND f.transcript IS NOT NULL" => "archiving",
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
