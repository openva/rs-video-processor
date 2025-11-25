#!/usr/bin/env bash
set -euo pipefail

# Update the codebase from an S3-hosted zip artifact.
# The script only runs when the guard file exists.

GUARD_FILE="${GUARD_FILE:-/home/ubuntu/video-processor.txt}"
APP_DIR="${APP_DIR:-/home/ubuntu/video-processor}"
ARTIFACT_S3_URI="${ARTIFACT_S3_URI:-s3://deploy.richmondsunlight.com/rs-video-processor-master.zip}"
TMP_DIR="${TMP_DIR:-/tmp/rs-video-processor-update}"
ZIP_PATH="${TMP_DIR}/artifact.zip"

if [[ ! -f "$GUARD_FILE" ]]; then
  echo "Guard file not found (${GUARD_FILE}); skipping update."
  exit 0
fi

if ! command -v aws >/dev/null 2>&1; then
  echo "aws CLI is required but not found on PATH." >&2
  exit 1
fi

mkdir -p "$TMP_DIR"
rm -f "$ZIP_PATH"

echo "Downloading artifact from ${ARTIFACT_S3_URI}..."
aws s3 cp "$ARTIFACT_S3_URI" "$ZIP_PATH"

rm -rf "${TMP_DIR}/unpacked"
mkdir -p "${TMP_DIR}/unpacked"

echo "Unpacking artifact..."
unzip -q "$ZIP_PATH" -d "${TMP_DIR}/unpacked"

# The zip is created from the repo root; sync everything except local config/state.
echo "Syncing files into ${APP_DIR}..."
rsync -a --delete \
  --exclude '.git' \
  --exclude '.github' \
  --exclude 'upload' \
  --exclude 'logs' \
  --exclude 'includes/settings.inc.php' \
  "${TMP_DIR}/unpacked/" "$APP_DIR/"

cd "$APP_DIR"

# Restore execute bits that S3 strips from shell scripts.
find "$APP_DIR" -type f -name '*.sh' -print0 | xargs -0 chmod +x

# This shouldn't be necessary, since this was done in the CI/CD pipeline, but it doesn't hurt.
echo "Installing Composer dependencies..."
composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader

echo "Update complete."
