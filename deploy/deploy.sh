#!/bin/bash

set -euo pipefail

cd /home/ubuntu/video-processor/

# Change the timezone to Eastern
sudo cp /usr/share/zoneinfo/US/Eastern /etc/localtime
    
# Add swap space, if it doesn't exist
if [ "$(grep -c swap /etc/fstab)" -eq "0" ]; then
    sudo /bin/dd if=/dev/zero of=/var/swap.1 bs=1M count=1024
    sudo /sbin/mkswap /var/swap.1
    sudo chmod 600 /var/swap.1
    sudo /sbin/swapon /var/swap.1
    echo "/var/swap.1   swap    swap    defaults        0   0" | sudo tee /etc/fstab
fi

# Update the OS and repositories (need ondrej/php for PHP 8.x)
sudo apt-get update
sudo apt-get install -y unattended-upgrades
sudo unattended-upgrades --verbose
sudo apt-get install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update

# Install necessary packages.
sudo apt-get install -y \
  curl \
  git \
  unzip \
  jq \
  ffmpeg \
  tesseract-ocr \
  imagemagick \
  python3 \
  python3-pip \
  php8.3-cli \
  php8.3-mbstring \
  php8.3-xml \
  php8.3-curl \
  php8.3-gd \
  php8.3-mysql \
  php8.3-zip \
  composer

# Install the AWS CLI
if ! command -v aws &> /dev/null; then
  curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o "awscliv2.zip"
  unzip awscliv2.zip
  sudo ./aws/install
  rm -rf awscliv2.zip aws
fi

# Install Python tooling for video downloads.
python3 -m pip install --upgrade pip
python3 -m pip install --upgrade -r requirements.txt --break-system-packages

# Ensure user-level scripts (e.g., yt-dlp) are on PATH and available system-wide.
LOCAL_BIN="$HOME/.local/bin"
if [[ -d "$LOCAL_BIN" ]] && ! printf '%s' "$PATH" | grep -q "$LOCAL_BIN"; then
  echo 'export PATH="$HOME/.local/bin:$PATH"' >> "$HOME/.profile"
fi
if [[ -x "$LOCAL_BIN/yt-dlp" ]]; then
  sudo ln -sf "$LOCAL_BIN/yt-dlp" /usr/local/bin/yt-dlp
fi

# Install one-shot updater (runs on boot) when enabled via guard file.
echo "Installing update-from-s3 service..."
sudo cp deploy/services/update-from-s3.service /etc/systemd/system/update-from-s3.service
sudo systemctl daemon-reload
sudo systemctl enable update-from-s3.service

# If this is the video processor instance (guard file present)
if [[ -f /home/ubuntu/video-processor.txt ]]; then
  echo "Installing video processing services..."

  # Ensure scripts are executable
  chmod +x deploy/run-pipeline.sh
  chmod +x deploy/auto-shutdown.sh
  chmod +x deploy/update-from-s3.sh

  # Install the video pipeline service (runs after update, then auto-shuts down)
  sudo cp deploy/services/video-pipeline.service /etc/systemd/system/video-pipeline.service

  # Install the screenshot worker service (optional, for continuous processing)
  sudo cp deploy/services/screenshot-worker.service /etc/systemd/system/screenshot-worker.service

  sudo systemctl daemon-reload

  # Enable services to run on boot
  sudo systemctl enable video-pipeline.service
  sudo systemctl enable screenshot-worker.service

  echo "Video processing services installed and enabled."
else
  echo "Skipping video processing services install on this host (guard file not found)."
fi
