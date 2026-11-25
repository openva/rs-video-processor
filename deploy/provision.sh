#!/bin/bash

# Install everything the video processor needs on a fresh Ubuntu 24.04 server.
#
# Packer runs this once per disk image (image/video-processor.pkr.hcl), with
# --strict, so that any failure fails the build. Instances started from those
# images skip it. The hand-built instance runs it on every deploy, through
# deploy.sh, best-effort: there a transient apt or network failure must not
# stop the services being installed, and on an already-provisioned machine
# nearly everything here does nothing.

if [[ "${1:-}" == "--strict" ]]; then
  set -euo pipefail
else
  set -uo pipefail
fi

# Run from the application directory, where requirements.txt is.
cd "$(dirname "${BASH_SOURCE[0]}")/.."

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

# Update the OS and repositories (need ondrej/php for PHP 8.x).
# --allow-releaseinfo-change: the ondrej/php PPA periodically changes its repo
# Label/Origin (it now redirects to packages.sury.org), which makes a plain
# `apt-get update` exit 100 until the change is acknowledged.
sudo apt-get update --allow-releaseinfo-change
sudo apt-get install -y unattended-upgrades
# Reports some packages it holds back as a failure; not worth failing a build.
sudo unattended-upgrades --verbose || true
sudo apt-get install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update --allow-releaseinfo-change

# Install necessary packages. rsyslog writes the journal to /var/log/syslog,
# which the CloudWatch agent sends to CloudWatch Logs.
sudo apt-get install -y \
  curl \
  git \
  unzip \
  rsync \
  jq \
  rsyslog \
  ffmpeg \
  tesseract-ocr \
  imagemagick \
  python3 \
  python3-pip \
  php8.4-cli \
  php8.4-mbstring \
  php8.4-xml \
  php8.4-curl \
  php8.4-gd \
  php8.4-mysql \
  php8.4-zip \
  php8.4-intl \
  php8.4-memcached \
  internetarchive \
  composer

# Run PHP 8.4 even if a newer PHP is also installed: apt points `php` at the
# newest version it has.
sudo update-alternatives --set php /usr/bin/php8.4

# Install deno (required by yt-dlp for YouTube signature decryption)
if ! command -v deno &> /dev/null; then
  curl -fsSL https://deno.land/install.sh | DENO_INSTALL=/usr/local sudo -E sh
  echo "Installed deno $(deno --version | head -1)"
fi

# Install the AWS CLI
if ! command -v aws &> /dev/null; then
  curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o "awscliv2.zip"
  unzip -q awscliv2.zip
  sudo ./aws/install
  rm -rf awscliv2.zip aws
fi

# Install the CloudWatch agent, which reports memory and disk use (EC2 reports
# only CPU, network and disk traffic) and sends /var/log/syslog to CloudWatch
# Logs. It sends with the instance's IAM role (terraform/processor_role.tf).
# deploy.sh loads its configuration, deploy/cloudwatch-agent.json, at each boot.
if [[ ! -x /opt/aws/amazon-cloudwatch-agent/bin/amazon-cloudwatch-agent-ctl ]]; then
  curl -fsSL -o /tmp/amazon-cloudwatch-agent.deb \
    https://amazoncloudwatch-agent.s3.amazonaws.com/ubuntu/amd64/latest/amazon-cloudwatch-agent.deb
  sudo dpkg -i -E /tmp/amazon-cloudwatch-agent.deb
  rm -f /tmp/amazon-cloudwatch-agent.deb
fi
# /var/log/syslog is readable by the adm group only.
sudo usermod -aG adm cwagent

# Install Python tooling for video downloads. pip itself can't be upgraded
# over Ubuntu's copy ("uninstall-no-record-file"), and needn't be.
python3 -m pip install --upgrade pip --break-system-packages || true
python3 -m pip install --upgrade -r requirements.txt --break-system-packages

# Ensure user-level scripts (e.g., yt-dlp) are on PATH and available system-wide.
LOCAL_BIN="$HOME/.local/bin"
if [[ -d "$LOCAL_BIN" ]] && ! grep -qs "$LOCAL_BIN" "$HOME/.profile"; then
  echo 'export PATH="$HOME/.local/bin:$PATH"' >> "$HOME/.profile"
fi
if [[ -x "$LOCAL_BIN/yt-dlp" ]]; then
  sudo ln -sf "$LOCAL_BIN/yt-dlp" /usr/local/bin/yt-dlp
fi
