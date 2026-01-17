#!/bin/bash

set -euo pipefail

cd /home/ubuntu/video-processor/

# Change the timezone to Eastern
sudo cp /usr/share/zoneinfo/US/Eastern /etc/localtime
    
# Ensure root filesystem entry exists in fstab (required for read-write remount on boot)
if ! grep -q ' / ' /etc/fstab; then
    ROOT_PARTUUID=$(findmnt -n -o PARTUUID /)
    if [ -n "$ROOT_PARTUUID" ]; then
        echo "PARTUUID=$ROOT_PARTUUID   /   ext4   defaults,discard   0   1" | sudo tee -a /etc/fstab
    fi
fi

# Add swap space, if it doesn't exist
if ! grep -q swap /etc/fstab; then
    sudo /bin/dd if=/dev/zero of=/var/swap.1 bs=1M count=1024
    sudo /sbin/mkswap /var/swap.1
    sudo chmod 600 /var/swap.1
    sudo /sbin/swapon /var/swap.1
    echo "/var/swap.1   swap    swap    defaults        0   0" | sudo tee -a /etc/fstab
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
  php8.5-cli \
  php8.5-mbstring \
  php8.5-xml \
  php8.5-curl \
  php8.5-gd \
  php8.5-mysql \
  php8.5-zip \
  php8.5-intl \
  php-memcached \
  internetarchive \
  composer

# Install Google Chrome (required for yt-dlp YouTube cookie extraction)
if ! command -v google-chrome &> /dev/null; then
  echo "Installing Google Chrome for YouTube cookie support..."
  cd /tmp
  wget -q https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
  sudo apt install -y ./google-chrome-stable_current_amd64.deb || true
  sudo apt-get install -f -y
  rm -f google-chrome-stable_current_amd64.deb
  cd -

  # Initialize Chrome's cookie database by visiting YouTube
  # This creates the necessary cookie files that yt-dlp will read
  echo "Initializing Chrome cookie database..."
  google-chrome --headless --disable-gpu --disable-software-rasterizer --no-sandbox \
    --user-data-dir=/home/ubuntu/.config/google-chrome \
    --dump-dom https://www.youtube.com/ > /dev/null 2>&1 || true

  echo "Chrome installed and initialized for YouTube downloads."
else
  echo "Chrome already installed."

  # Refresh Chrome cookies periodically by visiting YouTube again
  echo "Refreshing Chrome cookies..."
  google-chrome --headless --disable-gpu --disable-software-rasterizer --no-sandbox \
    --user-data-dir=/home/ubuntu/.config/google-chrome \
    --dump-dom https://www.youtube.com/ > /dev/null 2>&1 || true
fi

# Install the AWS CLI
if ! command -v aws &> /dev/null; then
  curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o "awscliv2.zip"
  unzip awscliv2.zip
  sudo ./aws/install
  rm -rf awscliv2.zip aws
fi

# Install Python tooling for video downloads.
python3 -m pip install --upgrade pip --break-system-packages
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
