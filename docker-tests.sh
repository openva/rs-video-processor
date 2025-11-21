#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

COMPOSE_BINARY=${DOCKER_COMPOSE:-"docker compose"}
SERVICE=${DOCKER_SERVICE:-processor}
CONTAINER_NAME=${CONTAINER_NAME:-rs_video_processor}

STATE=$(docker inspect -f '{{.State.Running}}' "$CONTAINER_NAME" 2>/dev/null || echo "false")
if [[ "$STATE" != "true" ]]; then
  echo "Container ${CONTAINER_NAME} is not running. Run ./docker-run.sh first." >&2
  exit 1
fi

$COMPOSE_BINARY exec "$SERVICE" bash -lc './includes/vendor/bin/phpunit'
