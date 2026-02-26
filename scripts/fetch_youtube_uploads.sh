#!/usr/bin/env bash
#
# fetch_youtube_uploads.sh
#
# Downloads Senate YouTube videos listed in the upload manifest and uploads
# them (plus auto-generated captions) to the S3 uploads/ staging area.
#
# The server's generate_upload_manifest.php writes the manifest on each
# pipeline run; this script is run locally where YouTube downloads work
# without cookie issues.
#
# Requirements:
#   - yt-dlp   (brew install yt-dlp)
#   - AWS CLI  (brew install awscli) with credentials for video.richmondsunlight.com
#   - jq       (brew install jq)
#
# Usage:
#   ./scripts/fetch_youtube_uploads.sh

set -euo pipefail

BUCKET="s3://video.richmondsunlight.com"
MANIFEST_KEY="uploads/manifest.json"
TMPDIR_BASE="${TMPDIR:-/tmp}"
MANIFEST_FILE="$TMPDIR_BASE/rs_upload_manifest.json"

# Download manifest from S3
echo "Fetching manifest from S3..."
aws s3 cp "$BUCKET/$MANIFEST_KEY" "$MANIFEST_FILE"

COUNT=$(jq '.count' "$MANIFEST_FILE")
if [[ "$COUNT" -eq 0 ]]; then
    echo "No videos pending upload."
    exit 0
fi

echo "Found $COUNT video(s) to download and upload."
echo ""

jq -c '.videos[]' "$MANIFEST_FILE" | while IFS= read -r entry; do
    youtube_id=$(echo "$entry"  | jq -r '.youtube_id')
    youtube_url=$(echo "$entry" | jq -r '.youtube_url')
    title=$(echo "$entry"       | jq -r '.title')
    date=$(echo "$entry"        | jq -r '.date')

    echo "[$date] $title"
    echo "  YouTube ID : $youtube_id"

    # Skip if already uploaded in a previous run
    if aws s3 ls "$BUCKET/uploads/${youtube_id}.mp4" > /dev/null 2>&1; then
        echo "  Already uploaded — skipping."
        continue
    fi

    video_file="$TMPDIR_BASE/${youtube_id}.mp4"
    caption_file="$TMPDIR_BASE/${youtube_id}.en.vtt"

    # Download video + auto-generated captions via yt-dlp.
    # Use `if !` so a yt-dlp failure skips this video rather than killing the script.
    if ! yt-dlp \
        -f "bestvideo[ext=mp4]+bestaudio[ext=m4a]/bestvideo+bestaudio/best" \
        --merge-output-format mp4 \
        --write-auto-sub --sub-lang en --convert-subs vtt \
        --no-abort-on-error \
        -o "$TMPDIR_BASE/${youtube_id}.%(ext)s" \
        "$youtube_url"
    then
        echo "  WARNING: yt-dlp failed for $youtube_id — skipping."
        continue
    fi

    # Upload video
    if [[ -f "$video_file" ]]; then
        echo "  Uploading video..."
        aws s3 cp "$video_file" "$BUCKET/uploads/${youtube_id}.mp4"
        rm "$video_file"
    else
        echo "  WARNING: No video file produced for $youtube_id — skipping."
        continue
    fi

    # Upload captions if present
    if [[ -f "$caption_file" ]]; then
        echo "  Uploading captions..."
        aws s3 cp "$caption_file" "$BUCKET/uploads/${youtube_id}.en.vtt"
        rm "$caption_file"
    fi

    echo "  Done."
    echo ""
done

echo "All videos uploaded. Run the server pipeline to process them."
