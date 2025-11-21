#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

COMPOSE_BINARY=${DOCKER_COMPOSE:-"docker compose"}
SERVICE=${DOCKER_SERVICE:-processor}

$COMPOSE_BINARY stop "$SERVICE"
$COMPOSE_BINARY rm -f "$SERVICE"
