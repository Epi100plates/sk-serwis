#!/usr/bin/env bash
# Studio-Komp — konfiguracja panelu edycji /edycja na VPS (idempotentna).
#
# Tworzy:
#   * użytkownika systemowego skcms (właściciel repozytorium /srv/studio-komp),
#   * Node.js 22 w /opt/node22 + pnpm (jeśli brakuje),
#   * usługę studio-komp-cms.service — decap-server (proxy backendu CMS),
#   * studio-komp-publish.service + .path — build i publikacja po commicie,
#   * plik haseł /etc/nginx/sk-edycja.htpasswd (hasło z $PANEL_PASSWORD),
#   * konfigurację nginx-sk z /tmp/sk.mati-it.pl.conf (jeśli została wgrana).
#
# Wywoływane przez deploy/provision.ps1. Wymaga roota.
set -euo pipefail

DOMAIN="${1:-sk.mati-it.pl}"
PANEL_PASSWORD="${PANEL_PASSWORD:-}"
# Hasło może przyjść przez stdin (provision.ps1) — PowerShell dokłada CRLF.
if [ -z "${PANEL_PASSWORD}" ] && [ ! -t 0 ]; then
  PANEL_PASSWORD=$(cat | tr -d '\r\n')
fi
REPO="/srv/studio-komp"
CMS_USER="skcms"
NGX_CONF="/etc/nginx/nginx-sk.conf"
HTPASSWD="/etc/nginx/sk-edycja.htpasswd"
STATE_DIR="/var/lib/studio-komp"

say() { printf '\n==> %s\n' "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "Uruchom jako root." >&2; exit 1; }

# --- 1. Node.js + pnpm -------------------------------------------------------
if ! /usr/local/bin/node --version >/dev/null 2>&1; then
  say "Instalacja Node.js 22 (oficjalne archiwum)"
  cd /tmp
  F=$(curl -fsSL https://nodejs.org/dist/latest-v22.x/ | grep -o 'node-v22\.[0-9.]*-linux-x64\.tar\.xz' | head -1)
  curl -fsSLO "https://nodejs.org/dist/latest-v22.x/${F}"
  mkdir -p /opt/node22
  tar -xJf "${F}" -C /opt/node22 --strip-components=1
  rm -f "${F}"
  for b in node npm npx corepack; do ln -sf "/opt/node22/bin/${b}" "/usr/local/bin/${b}"; done
fi
export PATH=/opt/node22/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
if ! command -v pnpm >/dev/null 2>&1; then
  say "Instalacja pnpm"
  npm config set fund false
  npm install -g --no-fund --no-audit pnpm@10
fi
if ! command -v decap-server >/dev/null 2>&1; then
  say "Instalacja decap-server"
  npm install -g --no-fund --no-audit decap-server@3
fi

# --- 2. Użytkownik i repozytorium -------------------------------------------
say "Użytkownik ${CMS_USER} i repozytorium ${REPO}"
id -u "${CMS_USER}" >/dev/null 2>&1 || useradd --system --create-home --home-dir "${REPO}" --shell /bin/bash "${CMS_USER}"
mkdir -p "${REPO}" "${STATE_DIR}" /var/lib/studio-komp/pnpm-store
chown -R "${CMS_USER}:${CMS_USER}" "${REPO}" "${STATE_DIR}" /var/lib/studio-komp/pnpm-store
if [ -d "${REPO}/.git" ]; then
  /usr/sbin/runuser -u "${CMS_USER}" -- env HOME="${REPO}" PATH="${PATH}" \
    git -C "${REPO}" config receive.denyCurrentBranch updateInstead
fi

# --- 3. Usługa panelu (decap-server) ----------------------------------------
say "Usługa studio-komp-cms.service"
cat > /etc/systemd/system/studio-komp-cms.service <<EOF
[Unit]
Description=Studio-Komp — panel edycji /edycja (decap-server)
After=network.target

[Service]
Type=simple
User=${CMS_USER}
Group=${CMS_USER}
WorkingDirectory=${REPO}
Environment=HOME=${REPO}
Environment=PORT=8081
Environment=BIND_HOST=127.0.0.1
Environment=MODE=git
Environment=GIT_REPO_DIRECTORY=${REPO}
Environment=ORIGIN=https://${DOMAIN}
Environment=LOG_LEVEL=info
Environment=PATH=/opt/node22/bin:/usr/local/bin:/usr/bin:/bin
ExecStart=/opt/node22/bin/decap-server
Restart=always
RestartSec=3s
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF

# --- 4. Build + publikacja po commicie --------------------------------------
say "Usługa studio-komp-publish.service + studio-komp-publish.path"
cat > /etc/systemd/system/studio-komp-publish.service <<EOF
[Unit]
Description=Studio-Komp — build i publikacja strony
After=network.target

[Service]
Type=oneshot
# Chwila oddechu: checkout z git push (receive.denyCurrentBranch=updateInstead)
# musi zdążyć podmienić pliki, zanim wystartuje publish.sh.
ExecStartPre=/bin/sleep 3
ExecStart=/usr/bin/bash ${REPO}/deploy/publish.sh
TimeoutStartSec=900
Nice=5
EOF

cat > /etc/systemd/system/studio-komp-publish.path <<EOF
[Unit]
Description=Studio-Komp — publikacja po commicie w ${REPO}

[Path]
PathChanged=${REPO}/.git/refs/heads/main
Unit=studio-komp-publish.service

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable studio-komp-cms.service studio-komp-publish.path >/dev/null 2>&1 || true
systemctl restart studio-komp-cms.service
systemctl start studio-komp-publish.path

# --- 5. Hasło do panelu ------------------------------------------------------
if [ -n "${PANEL_PASSWORD}" ]; then
  say "Plik haseł ${HTPASSWD}"
  printf 'studio-komp:%s\n' "$(openssl passwd -apr1 "${PANEL_PASSWORD}")" > "${HTPASSWD}"
  chown root:www-data "${HTPASSWD}"
  chmod 640 "${HTPASSWD}"
fi
[ -s "${HTPASSWD}" ] || { echo "BŁĄD: brak ${HTPASSWD} (ustaw PANEL_PASSWORD)." >&2; exit 1; }

# --- 6. nginx-sk -------------------------------------------------------------
if [ -f /tmp/sk.mati-it.pl.conf ]; then
  say "Konfiguracja nginx-sk z /tmp/sk.mati-it.pl.conf"
  install -m 644 -o root -g root /tmp/sk.mati-it.pl.conf "${NGX_CONF}"
  nginx -t -c "${NGX_CONF}"
  systemctl restart nginx-sk
fi

# --- 7. Kontrola -------------------------------------------------------------
say "Stan usług"
systemctl is-active studio-komp-cms.service || true
systemctl is-active studio-komp-publish.path || true
sleep 2
curl -sS -o /dev/null -w "  panel API (bez auth): %{http_code}\n" http://127.0.0.1:8081/api/v1 -X POST -H 'Content-Type: application/json' -d '{"action":"info"}' || true
curl -sS -o /dev/null -w "  /edycja/ przez nginx (bez auth): %{http_code}\n" http://127.0.0.1:8088/edycja/ || true
say "Gotowe."
