#!/usr/bin/env bash
# Studio-Komp — build i publikacja strony na VPS.
#
# Uruchamiany jako root przez studio-komp-publish.service. Usługa jest
# wyzwalana przez studio-komp-publish.path, który obserwuje
# /srv/studio-komp/.git/refs/heads/main — czyli każdy commit (z panelu
# /edycja albo z `pnpm deploy`) od razu przebudowuje i publikuje stronę.
#
# Ręcznie:  systemctl start studio-komp-publish
# Logi:     journalctl -u studio-komp-publish -n 50 --no-pager
set -euo pipefail

REPO="${REPO:-/srv/studio-komp}"
DOMAIN="${DOMAIN:-sk.mati-it.pl}"
WEB_ROOT="${WEB_ROOT:-/var/www/${DOMAIN}}"
SITE_URL="${SITE_URL:-https://${DOMAIN}}"
STATE_DIR="${STATE_DIR:-/var/lib/studio-komp}"
CMS_USER="${CMS_USER:-skcms}"

export PATH=/opt/node22/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

log() { printf '[publish %s] %s\n' "$(date +%H:%M:%S)" "$*"; }
as_cms() { /usr/sbin/runuser -u "$CMS_USER" -- env HOME="$REPO" PATH="$PATH" "$@"; }

mkdir -p "$STATE_DIR"
[ -d "$REPO/.git" ] || { log "brak repozytorium $REPO"; exit 1; }

cd "$REPO"
# Commity z panelu i push-e z laptopa tworzą pliki roota/skcms — ujednolicamy
# właściciela, żeby decap-server mógł dalej zapisywać.
chown -R "$CMS_USER":"$CMS_USER" "$REPO"

attempt=0
while :; do
  attempt=$((attempt + 1))
  sha=$(as_cms git rev-parse HEAD)
  log "build $sha (próba $attempt)"

  # SITE_URL trafia do canonical, sitemapy i Open Graph.
  as_cms env SITE_URL="$SITE_URL" BASE_PATH="" pnpm build

  built=$(as_cms git rev-parse HEAD)
  if [ "$built" != "$sha" ]; then
    log "repo zmieniło się w trakcie builda — powtarzam"
    continue
  fi

  log "publikacja dist/ → $WEB_ROOT"
  rsync -a --delete "$REPO/dist/" "$WEB_ROOT/"
  chown -R www-data:www-data "$WEB_ROOT"

  after=$(as_cms git rev-parse HEAD)
  if [ "$after" != "$sha" ]; then
    log "nowy commit w trakcie publikacji — powtarzam"
    continue
  fi
  break
done

printf '%s %s\n' "$sha" "$(date -Is)" > "$STATE_DIR/last-publish"
log "gotowe: $sha"
