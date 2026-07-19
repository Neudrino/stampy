#!/usr/bin/env bash
#
# Inject a version string into all files that contain a hardcoded version.
#
# Usage: ./dev/inject-version.sh <version>
# Example: ./dev/inject-version.sh 1.0.0
#
# This script is called by the build-release.yml and deploy-wporg.yml
# GitHub Actions workflows to inject the version from the git tag into
# all source files before building. The repository source always
# carries a placeholder ("0.0.1" or "unreleased"); the real version
# only exists in the built artifact and the git tag.
#
# Files updated:
#   stampy.php                          — Version: header + const VERSION
#   readme.txt                          — Stable tag + changelog section
#   package.json                        — "version" field
#   src/blocks/signup/block.json        — "version" field
#   src/campaign-editor/block.json      — "version" field
#   src/import-export/block.json        — "version" field

set -euo pipefail

if [ $# -lt 1 ]; then
	echo "Usage: $0 <version>" >&2
	echo "Example: $0 1.0.0" >&2
	exit 1
fi

VERSION="$1"

# Resolve the project root from the script location.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${ROOT_DIR}"

echo "[stampy] Injecting version: ${VERSION}"

# stampy.php — plugin header Version field.
sed -i "s/^\( \* Version:\s*\).*/\1${VERSION}/" stampy.php

# stampy.php — const VERSION.
sed -i "s/^const VERSION = '.*';/const VERSION = '${VERSION}';/" stampy.php

# stampy.php — docblock comment (optional, for clarity).
sed -i "s/^\( \* Plugin version\.\).*/\1 Set to ${VERSION} during build./" stampy.php

# readme.txt — Stable tag.
sed -i "s/^Stable tag: .*/Stable tag: ${VERSION}/" readme.txt

# readme.txt — changelog: rename "= unreleased =" to the actual version.
sed -i "s/^= unreleased =/= ${VERSION} =/" readme.txt

# package.json — "version" field.
sed -i "s/\"version\": \"[^\"]*\"/\"version\": \"${VERSION}\"/" package.json

# block.json files — "version" field.
for f in \
	src/blocks/signup/block.json \
	src/campaign-editor/block.json \
	src/import-export/block.json; do
	if [ -f "$f" ]; then
		sed -i "s/\"version\": \"[^\"]*\"/\"version\": \"${VERSION}\"/" "$f"
	fi
done

echo "[stampy] Version injection complete."

# Verify: all version references now match.
echo "[stampy] Verification:"
echo "  stampy.php header:  $(grep -oP 'Version:\s+\K\S+' stampy.php)"
echo "  stampy.php const:   $(grep -oP "const VERSION = '\K[^']+" stampy.php)"
echo "  readme.txt tag:     $(grep -oP 'Stable tag:\s+\K\S+' readme.txt)"
echo "  package.json:       $(grep -oP '"version": "\K[^"]+' package.json)"
