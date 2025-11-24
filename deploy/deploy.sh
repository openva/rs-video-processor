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
python3 -m pip install --upgrade -r requirements.txt

# If this is the instance that will analyze video contents.
if [[ -f /home/ubuntu/video-processor.txt ]]; then
  echo "Installing screenshot worker service..."
  sudo cp deploy/services/screenshot-worker.service /etc/systemd/system/screenshot-worker.service
  sudo systemctl daemon-reload
  sudo systemctl enable --now screenshot-worker.service
else
  echo "Skipping screenshot worker service install on this host."
fi
