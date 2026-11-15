#!/usr/bin/env bash
# Build the module zip for PrestaShop Addons and GitHub releases: groundmail.zip
# with a groundmail/ folder holding only what ships (no tests, Composer files or
# build scripts), an index.php in every folder and the guide as docs/readme_en.pdf.
# Usage: bin/build-zip.sh [output-dir]   (default: dist/ next to this package)
set -euo pipefail

HERE="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-${HERE}/dist}"
mkdir -p "${OUT}"
OUT="$(cd "${OUT}" && pwd)"

STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

M="${STAGE}/groundmail"
mkdir -p "${M}/docs"
cp "${HERE}/groundmail.php" "${HERE}/config.xml" "${HERE}/index.php" "${HERE}/.htaccess" \
  "${HERE}/logo.png" "${HERE}/logo.gif" "${HERE}/LICENSE" "${M}/"
cp -R "${HERE}/classes" "${HERE}/views" "${M}/"
cp "${HERE}/index.php" "${M}/docs/index.php"
python3 "${HERE}/bin/readme-pdf.py" "${HERE}/README.md" "${M}/docs/readme_en.pdf"

# PrestaShop Addons rejects a zip with a folder that has no index.php.
missing="$(find "${M}" -type d ! -exec test -e '{}/index.php' ';' -print)"
if [[ -n "${missing}" ]]; then
  echo "Folders without index.php:" >&2
  echo "${missing}" >&2
  exit 1
fi

rm -f "${OUT}/groundmail.zip"
(cd "${STAGE}" && zip -qr -X "${OUT}/groundmail.zip" groundmail)
echo "${OUT}/groundmail.zip"
