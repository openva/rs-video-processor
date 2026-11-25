#!/bin/bash
set -euo pipefail

# Install and enable the video processor's systemd units from deploy/services/.
#
# Run by deploy.sh on every deploy, and by Packer when it builds a disk image,
# so an instance started from the image runs the code update and the pipeline
# at its first boot. Units are only enabled here, never started: on the
# machine Packer builds on, the idle-shutdown timer would shut it down.

APP_DIR="${APP_DIR:-/home/ubuntu/video-processor}"
GUARD_FILE="${GUARD_FILE:-/home/ubuntu/video-processor.txt}"

cd "$APP_DIR"

# One-shot updater (runs on boot) when enabled via guard file.
echo "Installing update-from-s3 service..."
sudo cp deploy/services/update-from-s3.service /etc/systemd/system/update-from-s3.service
sudo systemctl daemon-reload
sudo systemctl enable update-from-s3.service

if [[ ! -f "$GUARD_FILE" ]]; then
  echo "Skipping video processing services install on this host (guard file not found)."
  exit 0
fi

echo "Installing video processing services..."

# S3 strips execute bits, and so does Packer's file upload.
chmod +x deploy/*.sh

# The video pipeline (runs after the update, then shuts the instance down).
sudo cp deploy/services/video-pipeline.service /etc/systemd/system/video-pipeline.service

# Re-checks every 15 minutes, so a box left running after someone logs out
# still shuts down.
sudo cp deploy/services/idle-shutdown.service /etc/systemd/system/idle-shutdown.service
sudo cp deploy/services/idle-shutdown.timer /etc/systemd/system/idle-shutdown.timer

sudo systemctl daemon-reload
sudo systemctl enable video-pipeline.service
sudo systemctl enable idle-shutdown.timer

# The pipeline generates screenshots itself. The standalone worker competed
# with it and was killed mid-job at every shutdown, so make sure it is off.
sudo systemctl disable --now screenshot-worker.service 2>/dev/null || true

echo "Video processing services installed and enabled."
