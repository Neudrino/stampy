#!/usr/bin/env bash
#
# Idempotently stop the two Mailpit containers used for local Stampy dev.
#
# This is the counterpart to mailpit-up.sh. It should be called whenever
# wp-env is stopped or destroyed so that the Mailpit containers don't
# keep running in the background.
#
# UIs:
#   development -> http://localhost:8025
#   tests       -> http://localhost:8026

set -euo pipefail

# Resolve this script's directory so the compose file path is stable regardless
# of the caller's working directory.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="${SCRIPT_DIR}/docker-compose.mailpit.yml"

# Pick a Compose command: prefer the Docker CLI plugin, fall back to the
# standalone binary. If neither exists, warn and exit 0.
compose() {
	if docker compose version >/dev/null 2>&1; then
		docker compose "$@"
	elif command -v docker-compose >/dev/null 2>&1; then
		docker-compose "$@"
	else
		return 127
	fi
}

if ! command -v docker >/dev/null 2>&1; then
	echo "[stampy] WARNING: 'docker' not found; skipping Mailpit shutdown." >&2
	exit 0
fi

# Check if any Mailpit containers are running.
running_count="$(docker ps --filter 'name=stampy-mailpit-dev' --filter 'name=stampy-mailpit-tests' --filter 'status=running' --format '{{.Names}}' | grep -c . || true)"
if [ "${running_count}" = "0" ]; then
	echo "[stampy] Mailpit already stopped."
	exit 0
fi

# Stop the stack. Wrap so a missing/failed Compose warns instead of aborting.
if compose -f "${COMPOSE_FILE}" down; then
	echo "[stampy] Mailpit stopped."
else
	rc=$?
	if [ "${rc}" = "127" ]; then
		echo "[stampy] WARNING: Docker Compose not available; skipping Mailpit shutdown." >&2
	else
		echo "[stampy] WARNING: 'docker compose down' failed (exit ${rc}); skipping." >&2
	fi
	exit 0
fi
