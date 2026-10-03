#!/bin/bash
clear

# ==================================================
# Script name: build.sh
# Author: Jörn Gorres
# Date: 03.10.2026
# Description: Builds the release ZIP of the Block Inserter Filter plugin for
#              the WordPress.org directory: checks the version numbers,
#              exports plugin/ without the files listed in .distignore and
#              zips the result into dist/.
# ==================================================

set -euo pipefail

echo "===================================================="
echo "   BLOCK INSERTER FILTER: RELEASE-ZIP ERZEUGEN"
echo "===================================================="
echo ""

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="${REPO_DIR}/plugin"
SLUG="block-inserter-filter"
TARGET_DIR="${REPO_DIR}/dist"
STAGE_DIR="$(mktemp -d)"

trap 'rm -rf "${STAGE_DIR}"' EXIT

# --------------------------------------------------
# 1. Version numbers must agree everywhere.
# --------------------------------------------------
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*\([0-9.]*\).*/\1/p' "${PLUGIN_DIR}/${SLUG}.php")"

if [[ -z "${VERSION}" ]]; then
	echo "Fehler: Version im Plugin-Header nicht gefunden." >&2
	exit 1
fi

echo "Version laut Plugin-Header: ${VERSION}"

check_version() {
	local label="$1"
	local found="$2"

	if [[ "${found}" != "${VERSION}" ]]; then
		echo "Fehler: ${label} steht auf '${found}', erwartet '${VERSION}'." >&2
		exit 1
	fi

	echo "  ok  ${label}"
}

check_version "Konstante JGOR_BIF_VERSION" \
	"$(sed -n "s/^define( 'JGOR_BIF_VERSION', '\([0-9.]*\)' );/\1/p" "${PLUGIN_DIR}/${SLUG}.php")"
check_version "DocBlock @version" \
	"$(sed -n 's/^ \* @version[[:space:]]*\([0-9.]*\).*/\1/p' "${PLUGIN_DIR}/${SLUG}.php")"
check_version "readme.txt Stable tag" \
	"$(sed -n 's/^Stable tag:[[:space:]]*\([0-9.]*\).*/\1/p' "${PLUGIN_DIR}/readme.txt")"
check_version "phpstan-bootstrap.php" \
	"$(sed -n "s/^\tdefine( 'JGOR_BIF_VERSION', '\([0-9.]*\)' );/\1/p" "${REPO_DIR}/phpstan-bootstrap.php")"
check_version "languages/${SLUG}.pot" \
	"$(sed -n 's/^"Project-Id-Version: Block Inserter Filter \([0-9.]*\)\\n"/\1/p' "${PLUGIN_DIR}/languages/${SLUG}.pot")"

# --------------------------------------------------
# 2. Export without the files from .distignore.
# --------------------------------------------------
echo ""
echo "Dateien zusammenstellen ..."

rsync -a --exclude-from="${REPO_DIR}/.distignore" "${PLUGIN_DIR}/" "${STAGE_DIR}/${SLUG}/"

if find "${STAGE_DIR}/${SLUG}" \( -name '*.po' -o -name '*.mo' -o -name '*.l10n.php' -o -name '*.json' \) -path '*/languages/*' -print -quit | grep -q .; then
	echo "Fehler: Übersetzungsdateien im Paket gefunden." >&2
	exit 1
fi

FILE_COUNT="$(find "${STAGE_DIR}/${SLUG}" -type f | wc -l)"
echo "  ok  ${FILE_COUNT} Dateien"

# --------------------------------------------------
# 3. ZIP.
# --------------------------------------------------
echo ""
echo "ZIP schreiben ..."

mkdir -p "${TARGET_DIR}"
ZIP_FILE="${TARGET_DIR}/${SLUG}-${VERSION}.zip"
rm -f "${ZIP_FILE}"

( cd "${STAGE_DIR}" && zip -q -r -X "${ZIP_FILE}" "${SLUG}" )

echo "  ok  ${ZIP_FILE} ($(du -h "${ZIP_FILE}" | cut -f1))"

echo ""
echo "===================================================="
echo "   Fertig!"
echo "===================================================="
