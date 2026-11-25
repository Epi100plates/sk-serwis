#!/usr/bin/env bash
# Konfiguracja panelu CMS i srodowiska publikacji na VPS.
# Uruchamiaj jako root po wgraniu repozytorium: bash /srv/studio-komp/deploy/setup-cms.sh
set -euo pipefail

REPO="/srv/studio-komp"
DATA="/srv/studio-komp-cms"
RELEASES="/srv/studio-komp-releases"
WEBROOT="/var/www/sk.mati-it.pl"
PREVIEW="/var/www/sk-preview"
PHP_SOCK="/run/php/php8.3-fpm-sk.sock"
CRED="/root/sk-setup.log"

log() { echo "[setup] $*"; }

export DEBIAN_FRONTEND=noninteractive
log "Instalacja pakietow PHP (jesli brakuje)"
apt-get install -y -qq php8.3-fpm php8.3-sqlite3 php8.3-gd php8.3-zip php8.3-intl php8.3-curl php8.3-mbstring php8.3-xml >/dev/null 2>&1 || true

id skcms >/dev/null 2>&1 || { log "Brak uzytkownika skcms"; exit 1; }

log "Katalogi danych"
mkdir -p "$DATA"/{data,data/logs,sessions} "$RELEASES" "$PREVIEW"
chown -R skcms:skcms "$DATA"
chmod 750 "$DATA" "$DATA/data" "$DATA/data/logs" "$DATA/sessions"

log "Konfiguracja panelu"
if [ ! -f "$DATA/config.php" ]; then
  cp "$REPO/panel/config.example.php" "$DATA/config.php"
  chown skcms:skcms "$DATA/config.php"
  chmod 640 "$DATA/config.php"
  log "Utworzono $DATA/config.php (uzupelnij klucz Google Places API, jesli potrzebny)"
fi

log "Repozytorium: uprawnienia i git"
chown -R skcms:skcms "$REPO"
git config --global --add safe.directory "$REPO" 2>/dev/null || true
runuser -u skcms -- git config --global --add safe.directory "$REPO" 2>/dev/null || true
runuser -u skcms -- git config --global user.name "Panel Studio-Komp" 2>/dev/null || true
runuser -u skcms -- git config --global user.email "panel@sk.mati-it.pl" 2>/dev/null || true
chmod +x "$REPO"/deploy/*.sh 2>/dev/null || true

log "PHP-FPM pool dla panelu (uzytkownik skcms)"
mkdir -p /run/php
cat > /etc/php/8.3/fpm/pool.d/studio-komp.conf <<'POOL'
[studio-komp]
user = skcms
group = skcms
listen = /run/php/php8.3-fpm-sk.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 4
pm.start_servers = 1
pm.min_spare_servers = 1
pm.max_spare_servers = 2
pm.max_requests = 400
chdir = /srv/studio-komp
catch_workers_output = yes
decorate_workers_output = no
php_admin_value[open_basedir] = /srv/studio-komp:/srv/studio-komp-cms:/var/www:/tmp:/etc/nginx/sk-preview.htpasswd
php_admin_value[session.save_path] = /srv/studio-komp-cms/sessions
php_admin_value[session.use_strict_mode] = 1
php_admin_value[session.cookie_httponly] = 1
php_admin_value[session.cookie_secure] = 1
php_admin_value[session.sid_length] = 48
php_admin_value[upload_max_filesize] = 14M
php_admin_value[post_max_size] = 16M
php_admin_value[max_execution_time] = 120
php_admin_value[expose_php] = Off
php_admin_value[display_errors] = Off
php_admin_flag[log_errors] = On
php_admin_value[error_log] = /srv/studio-komp-cms/data/logs/php-error.log
POOL
php-fpm8.3 -t >/dev/null 2>&1 || /usr/sbin/php-fpm8.3 -t >/dev/null
systemctl enable --now php8.3-fpm >/dev/null
systemctl reload php8.3-fpm

log "Przekierowania: plik dla nginx"
mkdir -p /etc/nginx
if [ ! -f /etc/nginx/sk-redirects.conf ]; then
  printf '# Wygenerowane z panelu Studio-Komp\n' > /etc/nginx/sk-redirects.conf
fi
chmod 644 /etc/nginx/sk-redirects.conf

log "Podglad: basic auth"
if [ ! -f /etc/nginx/sk-preview.htpasswd ]; then
  PREVIEW_PASS=$(openssl rand -base64 24 | tr -d '=+/' | cut -c1-20)
  PREVIEW_HASH=$(openssl passwd -apr1 "$PREVIEW_PASS")
  printf 'studio-komp:%s\n' "$PREVIEW_HASH" > /etc/nginx/sk-preview.htpasswd
  chown root:www-data /etc/nginx/sk-preview.htpasswd
  chmod 640 /etc/nginx/sk-preview.htpasswd
  cat > "$DATA/data/preview-credentials.txt" <<TXT
Podglad wersji roboczych: $(grep -o '"site_url"[^,]*' /dev/null 2>/dev/null || echo 'https://sk.mati-it.pl')/podglad/
Uzytkownik: studio-komp
Haslo: $PREVIEW_PASS
TXT
  chown skcms:skcms "$DATA/data/preview-credentials.txt"
  chmod 640 "$DATA/data/preview-credentials.txt"
fi

log "Nginx: konfiguracja + test + reload"
install -m 644 "$REPO/deploy/nginx/sk.mati-it.pl.conf" /etc/nginx/nginx-sk.conf
nginx -t -c /etc/nginx/nginx-sk.conf
systemctl enable nginx-sk >/dev/null 2>&1 || true
systemctl restart nginx-sk

log "Rollback: skrypt i sudoers"
install -m 755 "$REPO/deploy/sk-switch-release.sh" /usr/local/bin/sk-switch-release
cat > /etc/sudoers.d/studio-komp <<'SUDO'
skcms ALL=(root) NOPASSWD: /usr/local/bin/sk-switch-release
SUDO
chmod 440 /etc/sudoers.d/studio-komp

log "Wylaczenie starego panelu Decap (jesli dziala)"
systemctl disable --now studio-komp-cms.service >/dev/null 2>&1 || true

log "Cron: opinie Google + porzadki"
cat > /etc/cron.d/studio-komp <<'CRON'
SHELL=/bin/sh
PATH=/usr/local/bin:/usr/bin:/bin
17 */6 * * * skcms /usr/bin/php /srv/studio-komp/panel/bin/reviews-refresh.php >> /srv/studio-komp-cms/data/logs/reviews.log 2>&1
23 3 * * * skcms /usr/bin/php /srv/studio-komp/panel/bin/cleanup.php >> /srv/studio-komp-cms/data/logs/cleanup.log 2>&1
CRON
chmod 644 /etc/cron.d/studio-komp

log "Inicjalizacja panelu (dane startowe)"
if [ -f "$REPO/panel/lib/realizacje.php" ]; then
  runuser -u skcms -- /usr/bin/php "$REPO/panel/bin/setup-panel.php" >> "$CRED" 2>&1 || true
fi

log "Konto administratora"
USERS=$(runuser -u skcms -- /usr/bin/php -r '
require "/srv/studio-komp/panel/bootstrap.php";
echo (int) db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
' 2>/dev/null || echo 0)
if [ "${USERS:-0}" = "0" ]; then
  runuser -u skcms -- /usr/bin/php "$REPO/panel/bin/create-admin.php" --username=admin >> "$CRED" 2>&1
  log "Utworzono konto admin — dane w $DATA/data/initial-credentials.txt oraz $CRED"
else
  log "Konta panelu istnieja ($USERS) — hasel nie zmieniano"
fi
chmod 600 "$CRED" 2>/dev/null || true

log "Uprawnienia mediow"
mkdir -p "$REPO/public/images/uploads/thumbs"
chown -R skcms:skcms "$REPO/public/images/uploads"

log "Weryfikacja"
curl -sS -o /dev/null -w 'strona: %{http_code}\n' http://127.0.0.1:8088/ || true
curl -sS -o /dev/null -w 'panel: %{http_code}\n' http://127.0.0.1:8088/panel/ || true
curl -sS -o /dev/null -w 'api: %{http_code}\n' -X POST http://127.0.0.1:8088/api/e || true

log "GOTOWE"
