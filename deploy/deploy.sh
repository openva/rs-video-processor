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

# OS provisioning below is best-effort: on an already-provisioned box these are
# no-ops, and a transient apt/PPA/network failure here must NOT block the service
# installation at the end of this script (which is the part deploys actually
# need). errexit is restored before that critical section.
set +e

# Update the OS and repositories (need ondrej/php for PHP 8.x).
# --allow-releaseinfo-change: the ondrej/php PPA periodically changes its repo
# Label/Origin (it now redirects to packages.sury.org), which makes a plain
# `apt-get update` exit 100 until the change is acknowledged — that exit code
# previously aborted the whole deploy.
sudo apt-get update --allow-releaseinfo-change
sudo apt-get install -y unattended-upgrades
sudo unattended-upgrades --verbose
sudo apt-get install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update --allow-releaseinfo-change

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

# YouTube downloads require cookies file at /home/ubuntu/youtube-cookies.txt
# Export from your browser using "Get cookies.txt LOCALLY" extension
# Instructions: https://github.com/yt-dlp/yt-dlp/wiki/FAQ#how-do-i-pass-cookies-to-yt-dlp

# Install deno (required by yt-dlp for YouTube signature decryption)
if ! command -v deno &> /dev/null; then
  curl -fsSL https://deno.land/install.sh | DENO_INSTALL=/usr/local sudo -E sh
  echo "Installed deno $(deno --version | head -1)"
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

# Re-enable errexit: OS provisioning is done (best-effort above); the service
# installation below is critical and must fail loudly if anything goes wrong.
set -e

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
  chmod +x deploy/idle-shutdown.sh
  chmod +x deploy/check-pending-work.sh
  chmod +x deploy/update-from-s3.sh

  # Install the video pipeline service (runs after update, then auto-shuts down)
  sudo cp deploy/services/video-pipeline.service /etc/systemd/system/video-pipeline.service

  # Re-checks every 15 minutes, so a box left running after someone logs out
  # still shuts down.
  sudo cp deploy/services/idle-shutdown.service /etc/systemd/system/idle-shutdown.service
  sudo cp deploy/services/idle-shutdown.timer /etc/systemd/system/idle-shutdown.timer

  sudo systemctl daemon-reload

  # The `ia` command that uploads to the Internet Archive reads its credentials
  # from ia.ini, not from settings.inc.php. Write it from the settings when it is
  # missing, so a new instance can archive without anyone running `ia configure`.
  IA_CONFIG="$HOME/.config/internetarchive/ia.ini"
  if [[ ! -f "$IA_CONFIG" ]]; then
    mkdir -p "$(dirname "$IA_CONFIG")"
    if php -r '
      require "includes/settings.inc.php";
      if (!defined("IA_ACCESS_KEY") || IA_ACCESS_KEY === "" || !defined("IA_SECRET_KEY") || IA_SECRET_KEY === "") {
          exit(1);
      }
      file_put_contents($argv[1], "[s3]\naccess = " . IA_ACCESS_KEY . "\nsecret = " . IA_SECRET_KEY . "\n");
    ' "$IA_CONFIG"; then
      chmod 600 "$IA_CONFIG"
      echo "Wrote Internet Archive credentials to ${IA_CONFIG}."
    else
      rm -f "$IA_CONFIG"
      echo "WARNING: IA_ACCESS_KEY / IA_SECRET_KEY are not set; archiving will not run until ${IA_CONFIG} exists."
    fi
  fi

  # Enable services to run on boot
  sudo systemctl enable video-pipeline.service
  sudo systemctl enable --now idle-shutdown.timer
  # The pipeline generates screenshots itself. The standalone worker competed
  # with it and was killed mid-job at every shutdown, so make sure it is off.
  sudo systemctl disable --now screenshot-worker.service 2>/dev/null || true

  echo "Video processing services installed and enabled."
else
  echo "Skipping video processing services install on this host (guard file not found)."
fi
