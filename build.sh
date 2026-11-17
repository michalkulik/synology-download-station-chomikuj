#!/bin/sh
# Builds the Download Station module packages.
#
#   dist/chomikuj.host          - file hosting module (downloading)
#   dist/chomikuj-search.dlm    - search module (searching)
#
# Both are plain gzipped tarballs with INFO and the PHP module at the archive
# root, exactly as Download Station expects.

set -eu

ROOT="$(cd "$(dirname "$0")" && pwd)"
DIST="$ROOT/dist"

rm -rf "$DIST"
mkdir -p "$DIST"

( cd "$ROOT/src/host" && tar -czf "$DIST/chomikuj.host" INFO chomikuj.php )
( cd "$ROOT/src/search" && tar -czf "$DIST/chomikuj-search.dlm" INFO search.php )

echo "Zbudowano:"
ls -l "$DIST"