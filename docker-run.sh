#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

COMPOSE_BINARY=${DOCKER_COMPOSE:-"docker compose"}
SERVICE=${DOCKER_SERVICE:-processor}
CONTAINER_NAME=${CONTAINER_NAME:-rs_video_processor}

$COMPOSE_BINARY build
$COMPOSE_BINARY up -d

# Wait for the processor container to be running so we can exec into it.
until [[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER_NAME" 2>/dev/null)" == "true" ]]; do
  sleep 1
  if ! docker ps -a --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo "Container ${CONTAINER_NAME} is not available." >&2
    exit 1
  fi
 done

# Wait for MariaDB to be available inside the container
echo "Waiting for MariaDB (rs_video_processor_db) to report healthy..."
until docker exec rs_video_processor_db mysqladmin ping -h localhost --silent >/dev/null 2>&1; do
    sleep 1
done

# Install dependencies and prepare the workspace.
deploy/docker-setup.sh

echo "Docker environment is ready. Container: ${CONTAINER_NAME}."
