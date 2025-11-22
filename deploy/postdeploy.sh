#!/bin/bash

set -euo pipefail

cd /home/ubuntu/video-processor/

python3 -m pip install --upgrade pip
python3 -m pip install -r requirements.txt

sudo apt-get update
sudo apt-get install -y ffmpeg

if [[ -f /home/ubuntu/video-processor.txt ]]; then
  echo "Installing screenshot worker service..."
  sudo cp deploy/services/screenshot-worker.service /etc/systemd/system/screenshot-worker.service
  sudo systemctl daemon-reload
  sudo systemctl enable --now screenshot-worker.service
else
  echo "Skipping screenshot worker service install on this host."
fi
