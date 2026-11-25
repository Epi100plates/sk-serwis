#!/usr/bin/env bash
# Podglad wersji roboczych: rozpakowanie buildu do /var/www/sk-preview.
# Użycie: remote-preview.sh <sciezka-tar.gz> <request_id>
set -euo pipefail

TAR="${1:?Brak pliku tar.gz}"
REQUEST_ID="${2:-0}"
PREVIEW="/var/www/sk-preview"
DATA="/srv/studio-komp-cms/data"
TMP="$PREVIEW.tmp"

if [ ! -f "$TAR" ]; then
  echo "Brak pliku: $TAR" >&2
  exit 1
fi

rm -rf "$TMP"
mkdir -p "$TMP"
tar -xzf "$TAR" -C "$TMP"
rm -f "$TAR"
chown -R www-data:www-data "$TMP"
find "$TMP" -type d -exec chmod 755 {} +
find "$TMP" -type f -exec chmod 644 {} +

if [ -d "$PREVIEW" ]; then
  rm -rf "$PREVIEW.old"
  mv "$PREVIEW" "$PREVIEW.old"
fi
mv "$TMP" "$PREVIEW"
rm -rf "$PREVIEW.old"

# Statyczny podglad musi miec zakaz indeksacji (dodatkowo nginx dodaje naglowek).
if [ -f "$PREVIEW/index.html" ]; then
  sed -i 's#<meta name="robots" content="[^"]*">#<meta name="robots" content="noindex, nofollow">#' "$PREVIEW/index.html" 2>/dev/null || true
fi

FILES=$(find "$PREVIEW" -type f | wc -l | tr -d ' ')
cat > "$DATA/publish-state.json" <<JSON
{
  "status": "done",
  "kind": "preview",
  "request_id": $REQUEST_ID,
  "release": "preview",
  "finished_at": "$(date '+%Y-%m-%dT%H:%M:%S%:z')",
  "files": $FILES,
  "bytes": 0,
  "commit": ""
}
JSON
chmod 644 "$DATA/publish-state.json"

printf 'Podglad gotowy: /podglad/ (plikow: %s)\n' "$FILES"
