#!/usr/bin/env bash
# Studio-Komp — konfiguracja VPS dla sk.mati-it.pl.
#   * osobna instancja nginx (nginx-sk.service) na 127.0.0.1:8088,
#     NIE dotyka głównego /etc/nginx/nginx.conf (obsługuje file-uploader),
#   * usługa cloudflared łącząca tunel "studio-komp" (token z /tmp/sk.token).
# Idempotentny: można uruchamiać wielokrotnie.
set -euo pipefail

DOMAIN="${1:-sk.mati-it.pl}"
PORT="${2:-8088}"
WEB_ROOT="/var/www/${DOMAIN}"
CF_ETC="/etc/studio-komp-tunnel"
TOKEN_FILE="${CF_ETC}/token"
STATE_DIR="/var/lib/studio-komp-cloudflared"
NGX_CONF="/etc/nginx/nginx-sk.conf"
NGX_UNIT="nginx-sk"
CF_UNIT="studio-komp-tunnel"

say() { printf '\n==> %s\n' "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "Uruchom jako root." >&2; exit 1; }

# --- 0. Porządki po wcześniejszej próbie (sites-enabled nie jest używany) ---
rm -f /etc/nginx/sites-enabled/sk.mati-it.pl.conf /etc/nginx/sites-available/sk.mati-it.pl.conf 2>/dev/null || true

# --- 1. Katalog na stronę ---
say "Katalog strony: ${WEB_ROOT}"
mkdir -p "${WEB_ROOT}"

# --- 2. Osobna instancja nginx ---
if ! command -v nginx >/dev/null 2>&1; then
  say "Instalacja nginx"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq && apt-get install -y -qq nginx
fi

if [ -f /tmp/sk.mati-it.pl.conf ]; then
  say "Konfiguracja nginx-sk (127.0.0.1:${PORT})"
  if ss -ltn 2>/dev/null | grep -q "127.0.0.1:${PORT} "; then
    if ! ss -ltnp 2>/dev/null | grep "127.0.0.1:${PORT} " | grep -q nginx; then
      echo "BŁĄD: port ${PORT} zajęty przez inny proces" >&2
      ss -ltnp | grep ":${PORT} " >&2
      exit 1
    fi
  fi
  install -m 644 -o root -g root /tmp/sk.mati-it.pl.conf "${NGX_CONF}"
  nginx -t -c "${NGX_CONF}"

  cat > "/etc/systemd/system/${NGX_UNIT}.service" <<EOF
[Unit]
Description=nginx (static) — ${DOMAIN}
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/sbin/nginx -c ${NGX_CONF} -g 'daemon off;'
ExecReload=/usr/sbin/nginx -c ${NGX_CONF} -s reload
Restart=always
RestartSec=3s
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
EOF

  systemctl daemon-reload
  systemctl enable "${NGX_UNIT}" >/dev/null 2>&1 || true
  systemctl restart "${NGX_UNIT}"
  sleep 1
  systemctl is-active --quiet "${NGX_UNIT}" && echo "nginx-sk: AKTYWNY" || { journalctl -u "${NGX_UNIT}" -n 30 --no-pager >&2; exit 1; }
else
  say "UWAGA: brak /tmp/sk.mati-it.pl.conf — pomijam nginx"
fi

# --- 3. cloudflared ---
if ! command -v cloudflared >/dev/null 2>&1; then
  say "Instalacja cloudflared"
  case "$(uname -m)" in
    x86_64)        CF_BIN="cloudflared-linux-amd64" ;;
    aarch64|arm64) CF_BIN="cloudflared-linux-arm64" ;;
    *) echo "Nieobsługiwana architektura" >&2; exit 1 ;;
  esac
  curl -fsSL "https://github.com/cloudflare/cloudflared/releases/latest/download/${CF_BIN}" -o /usr/local/bin/cloudflared
  chmod 755 /usr/local/bin/cloudflared
fi

if ! id cloudflared >/dev/null 2>&1; then
  say "Tworzenie użytkownika systemowego cloudflared"
  useradd --system --home "${STATE_DIR}" --shell /usr/sbin/nologin cloudflared
fi

# --- 4. Token tunelu ---
if [ ! -s /tmp/sk.token ]; then
  echo "BŁĄD: brak /tmp/sk.token (token tunelu Cloudflare)." >&2
  exit 1
fi
say "Instalacja tokenu tunelu: ${TOKEN_FILE}"
mkdir -p "${CF_ETC}" "${STATE_DIR}"
chown root:cloudflared "${CF_ETC}" "${STATE_DIR}"
chmod 750 "${CF_ETC}"
chmod 700 "${STATE_DIR}"
install -m 640 -o root -g cloudflared /tmp/sk.token "${TOKEN_FILE}"

# --- 5. Usługa cloudflared ---
say "Konfiguracja usługi ${CF_UNIT}"
cat > "/etc/systemd/system/${CF_UNIT}.service" <<EOF
[Unit]
Description=Cloudflare Tunnel for ${DOMAIN}
Documentation=https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=cloudflared
Group=cloudflared
StateDirectory=${STATE_DIR##*/}
StateDirectoryMode=0700
Environment=HOME=${STATE_DIR}
ExecStart=/usr/local/bin/cloudflared --no-autoupdate tunnel run --token-file ${TOKEN_FILE}
Restart=always
RestartSec=5s
TimeoutStopSec=30s
KillMode=mixed
UMask=0077

NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
RestrictNamespaces=true
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
SystemCallArchitectures=native
SystemCallFilter=@system-service
SystemCallErrorNumber=EPERM
CapabilityBoundingSet=
AmbientCapabilities=
ReadOnlyPaths=${CF_ETC}
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable "${CF_UNIT}" >/dev/null 2>&1 || true
systemctl restart "${CF_UNIT}"
sleep 5

if systemctl is-active --quiet "${CF_UNIT}"; then
  echo "cloudflared (${CF_UNIT}): AKTYWNY"
else
  echo "cloudflared NIE WSTAŁ — log:" >&2
  journalctl -u "${CF_UNIT}" -n 40 --no-pager >&2
  exit 1
fi

say "Test lokalny (nginx-sk):"
curl -sS -o /dev/null -w "  http://127.0.0.1:${PORT}/ -> %{http_code}\n" "http://127.0.0.1:${PORT}/" || true

say "Gotowe."
