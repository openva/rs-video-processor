#!/usr/bin/env bash
set -euo pipefail

# Update the codebase from an S3-hosted zip artifact.
# Only updates if the remote artifact is newer than the local version.
# The script only runs when the guard file exists.

GUARD_FILE="${GUARD_FILE:-/home/ubuntu/video-processor.txt}"
APP_DIR="${APP_DIR:-/home/ubuntu/video-processor}"
ARTIFACT_S3_URI="${ARTIFACT_S3_URI:-s3://deploy.richmondsunlight.com/rs-video-processor-master.zip}"
TMP_DIR="${TMP_DIR:-/tmp/rs-video-processor-update}"
ZIP_PATH="${TMP_DIR}/artifact.zip"
ETAG_FILE="${APP_DIR}/.deploy-etag"

if [[ ! -f "$GUARD_FILE" ]]; then
  echo "Guard file not found (${GUARD_FILE}); skipping update."
  exit 0
fi

if ! command -v aws >/dev/null 2>&1; then
  echo "aws CLI is required but not found on PATH." >&2
  exit 1
fi

# Check the remote ETag to see if there's a new version
echo "Checking for updates from ${ARTIFACT_S3_URI}..."
REMOTE_ETAG=$(aws s3api head-object --bucket deploy.richmondsunlight.com --key rs-video-processor-master.zip --query ETag --output text 2>/dev/null || echo "")

if [[ -z "$REMOTE_ETAG" ]]; then
  echo "Could not retrieve remote artifact metadata; skipping update." >&2
  exit 0
fi

# Compare with cached ETag
if [[ -f "$ETAG_FILE" ]]; then
  LOCAL_ETAG=$(cat "$ETAG_FILE")
  if [[ "$REMOTE_ETAG" == "$LOCAL_ETAG" ]]; then
    echo "Already running latest version (ETag: ${REMOTE_ETAG}); no update needed."
    exit 0
  fi
  echo "New version detected (local: ${LOCAL_ETAG}, remote: ${REMOTE_ETAG})"
else
  echo "No local version recorded; will download and install."
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
  --exclude 'storage' \
  --exclude 'includes/settings.inc.php' \
  --exclude '.deploy-etag' \
  "${TMP_DIR}/unpacked/" "$APP_DIR/"

cd "$APP_DIR"

# Restore execute bits that S3 strips from shell scripts.
find "$APP_DIR" -type f -name '*.sh' -print0 | xargs -0 chmod +x

# This shouldn't be necessary, since this was done in the CI/CD pipeline, but it doesn't hurt.
echo "Installing Composer dependencies..."
composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader

# Save the ETag so we know what version we're running
echo "$REMOTE_ETAG" > "$ETAG_FILE"

# Run the deploy script if it exists (for any post-update configuration)
if [[ -x "${APP_DIR}/deploy/deploy.sh" ]]; then
  echo "Running deploy script..."
  "${APP_DIR}/deploy/deploy.sh"
fi

echo "Update complete (now running: ${REMOTE_ETAG})."
