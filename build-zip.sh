#!/usr/bin/env bash
# Builds dist/resenje-site-mover.zip (the file uploaded to WordPress.org / attached to releases).
set -euo pipefail
cd "$(dirname "$0")"
slug=resenje-site-mover
rm -rf dist && mkdir -p "dist/$slug"
cp -r "$slug.php" uninstall.php readme.txt LICENSE includes assets "dist/$slug/"
if command -v zip >/dev/null 2>&1; then
	( cd dist && zip -qr "$slug.zip" "$slug" )
else
	( cd dist && /c/Windows/System32/tar.exe -a -cf "$slug.zip" "$slug" )
fi
echo "dist/$slug.zip"
