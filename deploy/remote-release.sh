#!/usr/bin/env bash
# Publikacja nowej wersji strony na VPS (uruchamiane jako root przez SSH).
# Użycie: remote-release.sh <nazwa-wersji> <sciezka-tar.gz> <request_id>
set -euo pipefail

NAME="${1:?Brak nazwy wersji}"
TAR="${2:?Brak pliku tar.gz}"
REQUEST_ID="${3:-0}"

RELEASES="/srv/studio-komp-releases"
WEBROOT="/var/www/sk.mati-it.pl"
DATA="/srv/studio-komp-cms/data"
REPO="/srv/studio-komp"
KEEP_RELEASES=6

if ! [[ "$NAME" =~ ^[0-9]{8}-[0-9]{6}$ ]]; then
  echo "Nieprawidlowa nazwa wersji: $NAME" >&2
  exit 1
fi
if [ ! -f "$TAR" ]; then
  echo "Brak pliku: $TAR" >&2
  exit 1
fi

mkdir -p "$RELEASES" "$DATA"
TARGET="$RELEASES/$NAME"
rm -rf "$TARGET"
mkdir -p "$TARGET"
tar -xzf "$TAR" -C "$TARGET"
rm -f "$TAR"

# Uprawnienia: nginx czyta jako www-data, panel jako skcms.
chown -R www-data:www-data "$TARGET"
find "$TARGET" -type d -exec chmod 755 {} +
find "$TARGET" -type f -exec chmod 644 {} +

FILES=$(find "$TARGET" -type f | wc -l | tr -d ' ')
BYTES=$(du -sb "$TARGET" | awk '{print $1}')
COMMIT=$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo '')
CREATED_AT=$(date '+%Y-%m-%d %H:%M:%S')

cat > "$TARGET/manifest.json" <<JSON
{
  "release": "$NAME",
  "created_at": "$CREATED_AT",
  "files": $FILES,
  "bytes": $BYTES,
  "commit": "$COMMIT"
}
JSON

# Przekierowania zarzadzane panelem
mkdir -p /etc/nginx
if [ -f "$REPO/deploy/nginx/sk-redirects.conf" ]; then
  install -m 644 "$REPO/deploy/nginx/sk-redirects.conf" /etc/nginx/sk-redirects.conf
elif [ ! -f /etc/nginx/sk-redirects.conf ]; then
  printf '# Wygenerowane z panelu Studio-Komp\n' > /etc/nginx/sk-redirects.conf
  chmod 644 /etc/nginx/sk-redirects.conf
fi

# Konfiguracja nginx (jesli w repo jest nowsza) + test + reload
NGINX_OK=1
if [ -f "$REPO/deploy/nginx/sk.mati-it.pl.conf" ]; then
  cp "$REPO/deploy/nginx/sk.mati-it.pl.conf" /etc/nginx/nginx-sk.conf.new
  if nginx -t -c /etc/nginx/nginx-sk.conf.new >/tmp/sk-nginx-test.log 2>&1; then
    mv /etc/nginx/nginx-sk.conf.new /etc/nginx/nginx-sk.conf
  else
    echo "UWAGA: test nowej konfiguracji nginx nie powiodl sie:" >&2
    cat /tmp/sk-nginx-test.log >&2
    rm -f /etc/nginx/nginx-sk.conf.new
    NGINX_OK=0
  fi
fi
if [ "$NGINX_OK" = "1" ]; then
  nginx -t -c /etc/nginx/nginx-sk.conf >/tmp/sk-nginx-test.log 2>&1 && \
    nginx -s reload -c /etc/nginx/nginx-sk.conf >>/tmp/sk-nginx-test.log 2>&1 || {
      echo "UWAGA: reload nginx-sk nie powiodl sie:" >&2
      cat /tmp/sk-nginx-test.log >&2
    }
fi

# Atomowa podmiana wersji (symlink)
if [ -L "$WEBROOT" ]; then
  ln -sfn "$TARGET" "$WEBROOT"
else
  if [ -d "$WEBROOT" ]; then
    mv "$WEBROOT" "/var/www/sk.mati-it.pl.legacy-$(date +%s)"
  fi
  ln -s "$TARGET" "$WEBROOT"
fi

# Retencja wersji
ls -1dt "$RELEASES"/[0-9]* 2>/dev/null | tail -n +$((KEEP_RELEASES + 1)) | while read -r old; do
  if [ "$(readlink -f "$WEBROOT")" != "$(readlink -f "$old")" ]; then
    rm -rf "$old"
  fi
done

# Stan dla panelu
cat > "$DATA/publish-state.json" <<JSON
{
  "status": "done",
  "kind": "publish",
  "request_id": $REQUEST_ID,
  "release": "$NAME",
  "finished_at": "$(date '+%Y-%m-%dT%H:%M:%S%:z')",
  "files": $FILES,
  "bytes": $BYTES,
  "commit": "$COMMIT"
}
JSON
chmod 644 "$DATA/publish-state.json"
chown skcms:skcms "$DATA/publish-state.json" 2>/dev/null || true

printf 'Publikacja zakonczona: %s (plikow: %s, bajtow: %s, commit: %s)\n' "$NAME" "$FILES" "$BYTES" "$COMMIT"
