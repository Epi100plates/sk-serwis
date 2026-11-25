#!/usr/bin/env bash
# Przelaczenie wersji strony (rollback). Wywolywane przez panel przez sudo.
# Użycie: sk-switch-release <nazwa-wersji>
set -euo pipefail

NAME="${1:?Brak nazwy wersji}"
RELEASES="/srv/studio-komp-releases"
WEBROOT="/var/www/sk.mati-it.pl"

if ! [[ "$NAME" =~ ^[0-9]{8}-[0-9]{6}$ ]]; then
  echo "Nieprawidlowa nazwa wersji" >&2
  exit 1
fi
if [ ! -d "$RELEASES/$NAME" ]; then
  echo "Wersja nie istnieje: $NAME" >&2
  exit 1
fi

ln -sfn "$RELEASES/$NAME" "$WEBROOT"
printf 'Przelaczono na wersje: %s\n' "$NAME"
