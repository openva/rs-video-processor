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

$COMPOSE_BINARY exec "$SERVICE" bash -lc '
  set -euo pipefail
  echo "Ensuring video fixtures are available..."
  bin/fetch_test_fixtures.php || {
    echo "Fixture download failed. Verify RS_VIDEO_FIXTURE_BASE_URL and network access." >&2
    exit 1
  }
  php -d error_reporting="E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED" ./includes/vendor/bin/phpunit --display-skipped --display-warnings
'
