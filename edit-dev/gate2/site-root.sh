#!/bin/sh
# Makes a servable site folder from a build's side folder: built pages + links to the repo's
# media, contracts, runtime and React files.   site-root.sh <build/site dir> <out dir>
set -e
src="$1"; out="$2"; repo="$(cd "$(dirname "$0")/../.." && pwd)"
rm -rf "$out"; mkdir -p "$out"
cp "$src"/* "$out"/
for f in media docs vendor support.js send-lead.php .htaccess robots.txt; do ln -s "$repo/$f" "$out/$f"; done
