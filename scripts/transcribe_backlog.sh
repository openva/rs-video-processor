#!/usr/bin/env bash
#
# transcribe_backlog.sh
#
# Transcribes, on this Mac and for free, the old videos without captions that
# the server leaves out of the transcript stage rather than pay OpenAI for
# them. The server publishes the list of them to S3 on every pipeline pass and
# stores the transcripts this sends back on the next one. See
# bin/transcribe_backlog.php.
#
# Safe to stop at any time and run again.
#
# Requirements:
#   - mlx-whisper (pip install mlx-whisper), on Apple Silicon
#   - ffmpeg      (brew install ffmpeg)
#   - PHP, with this checkout's Composer packages (composer install)
#   - AWS CLI credentials (~/.aws) that can read and write video.richmondsunlight.com
#
# Usage:
#   ./scripts/transcribe_backlog.sh             # everything waiting
#   ./scripts/transcribe_backlog.sh --limit=3   # just a few

set -euo pipefail

cd "$(dirname "$0")/.."
exec php bin/transcribe_backlog.php --local "$@"
