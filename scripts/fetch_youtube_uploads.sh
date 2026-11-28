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
# Seconds to wait after YouTube answers HTTP 429 before trying again once.
RATE_LIMIT_WAIT="${RATE_LIMIT_WAIT:-300}"

# Note: cookies are read directly from Firefox for each download via
# --cookies-from-browser firefox. Firefox cookie storage does not require
# keychain access, so no prompts are expected.

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

    # Download video + auto-generated captions via yt-dlp. Its errors are also
    # copied to $error_log, to tell why it failed; stdout stays on the terminal.
    # Captions are fetched first, so a refused caption download costs no video.
    error_log="$TMPDIR_BASE/${youtube_id}.yt-dlp.log"
    fetch() {
        { yt-dlp \
            -f "bestvideo[ext=mp4]+bestaudio[ext=m4a]/bestvideo+bestaudio/best" \
            --merge-output-format mp4 \
            --write-auto-sub --sub-lang en --convert-subs vtt \
            --sleep-subtitles 5 --sleep-requests 1 \
            --cookies-from-browser "firefox:$HOME/Library/Application Support/Firefox/Profiles/4gctenat.default-release" \
            -o "$TMPDIR_BASE/${youtube_id}.%(ext)s" \
            "$youtube_url" 2>&1 1>&3 | tee "$error_log" >&2; } 3>&1
    }

    if ! fetch; then
        if grep -q 'HTTP Error 429' "$error_log"; then
            # YouTube is limiting requests. Without captions the video would be
            # transcribed by Whisper at OpenAI's expense, so don't upload it
            # without them: wait once, then stop, since every later video
            # would be refused too.
            echo "  YouTube refused a request (HTTP 429). Waiting ${RATE_LIMIT_WAIT}s and trying once more..."
            sleep "$RATE_LIMIT_WAIT"
            if ! fetch; then
                if grep -q 'HTTP Error 429' "$error_log"; then
                    rm -f "$error_log"
                    echo ""
                    echo "YouTube is still refusing requests. Stopping; run this again in an hour or two."
                    echo "Videos already uploaded will be skipped then."
                    exit 1
                fi
                echo "  WARNING: yt-dlp failed for $youtube_id — skipping."
                rm -f "$error_log"
                continue
            fi
        elif grep -q -E 'This video is private|Video unavailable' "$error_log"; then
            echo "  This video is private or unavailable on YouTube — skipping. It stays in the"
            echo "  manifest until it is made public or its files row is deleted."
            rm -f "$error_log"
            continue
        else
            echo "  WARNING: yt-dlp failed for $youtube_id — skipping."
            rm -f "$error_log"
            continue
        fi
    fi
    rm -f "$error_log"

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
