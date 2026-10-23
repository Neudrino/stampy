#!/usr/bin/env bash
#
# Generate the "== Changelog ==" section for readme.txt from Conventional Commits.
#
# Usage: ./dev/generate-changelog.sh
#
# For every version tag in the repository, the commit subjects since the
# previous tag are collected and grouped:
#   feat / feature -> "Added:"
#   fix            -> "Fixed:"
#   everything else (incl. non-conventional subjects) -> "Changed:"
# Merge commits are skipped, commit scopes are dropped.
#
# The generated section is appended at the end of readme.txt. The repository
# source never contains a changelog — it is generated at build time in the
# release and deployment workflows. Commit messages are the source of truth.
#
# Requires a full git history (actions/checkout with fetch-depth: 0).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${ROOT_DIR}"

echo "[stampy] Generating readme.txt changelog from Conventional Commits"

classify() {
	local subject="$1" type="" message=""
	local re='^([a-z]+)(\([^)]*\))?(!)?:[[:space:]]*(.*)$'
	if [[ "${subject}" =~ ${re} ]]; then
		type="${BASH_REMATCH[1]}"
		message="${BASH_REMATCH[4]}"
	else
		message="${subject}"
	fi
	case "${type}" in
		feat | feature) printf 'Added: %s' "${message}" ;;
		fix)            printf 'Fixed: %s' "${message}" ;;
		*)              printf 'Changed: %s' "${message}" ;;
	esac
}

tags="$(git tag --list | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' | sort -V || true)"

if [ -z "${tags}" ]; then
	echo "[stampy] No version tags found — readme.txt changelog skipped."
	exit 0
fi

# Collect one changelog section per tag (oldest tag first).
prev=""
sections=()
while IFS= read -r tag; do
	if [ -n "${prev}" ]; then
		range="${prev}..${tag}"
	else
		range="${tag}"
	fi
	prev="${tag}"

	entries=()
	while IFS= read -r subject; do
		[ -z "${subject}" ] && continue
		entries+=( "$(classify "${subject}")" )
	done < <(git log "${range}" --no-merges --pretty=tformat:%s)

	if [ "${#entries[@]}" -eq 0 ]; then
		continue
	fi
	sections+=( "$(printf '= %s =\n\n' "${tag}"; printf '* %s\n' "${entries[@]}")" )
done <<< "${tags}"

if [ "${#sections[@]}" -eq 0 ]; then
	echo "[stampy] No changelog entries found — readme.txt changelog skipped."
	exit 0
fi

# Drop a previously generated changelog section so the script is idempotent.
sed -i '/^== Changelog ==/,$d' readme.txt

# Trim trailing blank lines so the section gets exactly one blank line before it.
sed -i -e :a -e '/^[[:space:]]*$/{$d;N;ba' -e '}' readme.txt

{
	printf '\n== Changelog ==\n\n'
	# Newest version first (WP.org changelog convention).
	for (( i=${#sections[@]} - 1; i >= 0; i-- )); do
		printf '%s\n\n' "${sections[i]}"
	done
} >> readme.txt

echo "[stampy] Changelog for ${#sections[@]} version(s) appended to readme.txt."
