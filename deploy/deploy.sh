#!/bin/bash

set -euo pipefail

# Configure the video processor after a code update. update-from-s3.sh runs
# this at each boot that brings a new deploy bundle, which on an instance
# started from a disk image is every boot.
#
# Installing software is deploy/provision.sh. Images built by Packer
# (image/video-processor.pkr.hcl) have it done already and are marked with
# /etc/rs-video-processor-image; the hand-built instance runs it here.

APP_DIR="${APP_DIR:-/home/ubuntu/video-processor}"
GUARD_FILE="${GUARD_FILE:-/home/ubuntu/video-processor.txt}"
DEPLOY_BUCKET="${DEPLOY_BUCKET:-deploy.richmondsunlight.com}"
COOKIES_KEY="${COOKIES_KEY:-rs-video-processor/youtube-cookies.txt}"

cd "$APP_DIR"

if [[ ! -f /etc/rs-video-processor-image ]]; then
  # Best-effort: a transient apt/PPA/network failure must not block the
  # service installation below, which is the part deploys actually need.
  deploy/provision.sh || echo "WARNING: provisioning did not finish; continuing with the services."
fi

deploy/install-services.sh

if [[ ! -f "$GUARD_FILE" ]]; then
  exit 0
fi

sudo systemctl start idle-shutdown.timer

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

# yt-dlp's YouTube cookies (see VideoDownloadProcessor). They are kept in the
# deploy bucket, because an instance started from an image has nothing copied
# onto it by hand. To refresh them, export them from a browser with "Get
# cookies.txt LOCALLY" and upload them there:
#   aws s3 cp cookies.txt s3://deploy.richmondsunlight.com/rs-video-processor/youtube-cookies.txt
# If the bucket has none, a copy already on this machine is kept.
COOKIES_FILE=$(php -r 'require "includes/settings.inc.php"; echo defined("YTDLP_COOKIES_FILE") ? YTDLP_COOKIES_FILE : "";' 2>/dev/null || true)
COOKIES_FILE="${COOKIES_FILE:-/home/ubuntu/youtube-cookies.txt}"
if aws s3 cp --only-show-errors "s3://${DEPLOY_BUCKET}/${COOKIES_KEY}" "${COOKIES_FILE}.new" 2>/dev/null; then
  chmod 600 "${COOKIES_FILE}.new"
  mv "${COOKIES_FILE}.new" "$COOKIES_FILE"
  echo "Fetched YouTube cookies to ${COOKIES_FILE}."
else
  rm -f "${COOKIES_FILE}.new"
  echo "No YouTube cookies in s3://${DEPLOY_BUCKET}/${COOKIES_KEY}; keeping what is at ${COOKIES_FILE}, if anything."
fi

# Memory and disk metrics, and /var/log/syslog to CloudWatch Logs.
if [[ -x /opt/aws/amazon-cloudwatch-agent/bin/amazon-cloudwatch-agent-ctl ]]; then
  sudo /opt/aws/amazon-cloudwatch-agent/bin/amazon-cloudwatch-agent-ctl \
    -a fetch-config -m ec2 -s -c "file:${APP_DIR}/deploy/cloudwatch-agent.json"
fi
