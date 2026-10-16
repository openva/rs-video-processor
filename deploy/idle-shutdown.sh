#!/usr/bin/env bash
set -euo pipefail

# Periodic idle check for the video processor EC2 instance, run by
# idle-shutdown.timer.
#
# auto-shutdown.sh runs once, when video-pipeline.service stops. If someone is
# logged in at that moment it declines, and before this timer nothing checked
# again, so the instance stayed up after they logged out. This runs the same
# check every 15 minutes, but only while nothing is working.

APP_DIR="${APP_DIR:-/home/ubuntu/video-processor}"
GUARD_FILE="${GUARD_FILE:-/home/ubuntu/video-processor.txt}"

if [[ ! -f "$GUARD_FILE" ]]; then
  exit 0
fi

# The pipeline (or its own shutdown check, which runs while the unit is
# deactivating) or the boot-time code update is still going.
for unit in video-pipeline.service update-from-s3.service; do
  state=$(systemctl is-active "$unit" || true)
  case "$state" in
    active|activating|deactivating|reloading)
      echo "${unit} is ${state}; not idle."
      exit 0
      ;;
  esac
done

# A script someone started by hand, perhaps in tmux, where a detached session
# does not count as a login.
if pgrep -f 'php .*bin/' >/dev/null; then
  echo "A bin/ script is still running; not idle."
  pgrep -af 'php .*bin/' || true
  exit 0
fi

# A shutdown is already on its way.
if [[ -f /run/systemd/shutdown/scheduled ]]; then
  exit 0
fi

# Idle. auto-shutdown.sh makes the login check and shuts down. QUIET stops it
# writing to the terminals of anyone logged in every 15 minutes.
QUIET=1 exec "$APP_DIR/deploy/auto-shutdown.sh"
