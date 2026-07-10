#!/usr/bin/env bash
#
# Idempotently start the two Mailpit containers used for local Stampy dev.
#
# This is wired into .wp-env.json as the `afterStart` lifecycle script, so it
# runs every time `wp-env start` finishes. It must therefore be safe to run
# repeatedly and must NOT abort the wp-env start if Docker Compose is missing.
#
# UIs:
#   development -> http://localhost:8025
#   tests       -> http://localhost:8026

set -euo pipefail

# Resolve this script's directory so the compose file path is stable regardless
# of the caller's working directory.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="${SCRIPT_DIR}/docker-compose.mailpit.yml"

DEV_UI="http://localhost:8025"
TESTS_UI="http://localhost:8026"

# Pick a Compose command: prefer the Docker CLI plugin, fall back to the
# standalone binary. If neither exists, warn and exit 0 so wp-env keeps going.
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
	echo "[stampy] WARNING: 'docker' not found; skipping Mailpit startup." >&2
	echo "[stampy] Start Mailpit manually later with:" >&2
	echo "[stampy]   docker compose -f ${COMPOSE_FILE} up -d" >&2
	exit 0
fi

# Both containers already running? Then there is nothing to do (idempotent).
running_count="$(docker ps --filter 'name=stampy-mailpit-dev' --filter 'name=stampy-mailpit-tests' --filter 'status=running' --format '{{.Names}}' | grep -c . || true)"
if [ "${running_count}" = "2" ]; then
	echo "[stampy] Mailpit already running."
	echo "[stampy]   development: ${DEV_UI}"
	echo "[stampy]   tests:       ${TESTS_UI}"
	exit 0
fi

# Bring the stack up. Wrap so a missing/failed Compose warns instead of aborting.
if compose -f "${COMPOSE_FILE}" up -d; then
	echo "[stampy] Mailpit started."
	echo "[stampy]   development: ${DEV_UI}"
	echo "[stampy]   tests:       ${TESTS_UI}"
else
	rc=$?
	if [ "${rc}" = "127" ]; then
		echo "[stampy] WARNING: Docker Compose not available; skipping Mailpit startup." >&2
	else
		echo "[stampy] WARNING: 'docker compose up' failed (exit ${rc}); skipping Mailpit." >&2
	fi
	exit 0
fi
