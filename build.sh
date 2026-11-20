#!/bin/bash
#
# Build the LXC v2 plugin package from ./source and refresh the md5 in lxc.plg.
#
# Usage:
#   ./build.sh            build and update lxc.plg
#   ./build.sh --dry-run  build only, do not touch lxc.plg
#
set -euo pipefail

cd "$(dirname "$0")"

PLUGIN="lxc"
PLG="${PLUGIN}.plg"
VERSION="$(sed -n 's/.*<!ENTITY version[[:space:]]*"\([^"]*\)".*/\1/p' "$PLG" | head -1)"

if [ -z "$VERSION" ]; then
  echo "Could not read the version from $PLG" >&2
  exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/usr/local/emhttp/plugins"
cp -R "source/usr/local/emhttp/plugins/${PLUGIN}" "$STAGE/usr/local/emhttp/plugins/"
rm -f "$STAGE/usr/local/emhttp/plugins/${PLUGIN}/README.md"
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 755 {} +

mkdir -p packages
OUT="packages/${PLUGIN}-${VERSION}.txz"

tar -C "$STAGE" --owner=0 --group=0 --numeric-owner -cJf "$OUT" usr
md5sum "$OUT" | awk '{print $1}' > "${OUT}.md5"
MD5="$(cat "${OUT}.md5")"

echo "Built $OUT"
echo "md5: $MD5"

if [ "${1:-}" = "--dry-run" ]; then
  echo "Dry run: lxc.plg left untouched."
  exit 0
fi

sed -i "s/<!ENTITY md5[[:space:]]*\"[0-9a-f]*\">/<!ENTITY md5       \"${MD5}\">/" "$PLG"
echo "Updated the md5 entity in $PLG"
