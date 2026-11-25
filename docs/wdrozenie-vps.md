# Wdrożenie na VPS — architektura, publikacja, rollback

Stan na **2026-11-25**. Domena testowa: https://sk.mati-it.pl (Cloudflare
Tunnel → nginx-sk na 127.0.0.1:8088). Domena docelowa `studio-komp.pl` —
DNS i stara strona **nietknięte**.

## 1. Co działa na VPS

| Element | Szczegóły |
|---|---|
| Strona statyczna | `/var/www/sk.mati-it.pl` → symlink na `/srv/studio-komp-releases/<wersja>` |
| nginx | osobna instancja `nginx-sk.service`, konfiguracja `/etc/nginx/nginx-sk.conf`, port 127.0.0.1:8088 |
| Cloudflare Tunnel | `studio-komp-tunnel.service`, token w `/etc/studio-komp-tunnel/token` |
| Panel CMS | `/srv/studio-komp/panel`, PHP-FPM pool `studio-komp` (użytkownik `skcms`, socket `/run/php/php8.3-fpm-sk.sock`) |
| Dane panelu | `/srv/studio-komp-cms/data/panel.sqlite` + `logs/`, `sessions/` |
| Repozytorium treści | `/srv/studio-komp` (git, właściciel `skcms`) |
| Wersje | `/srv/studio-komp-releases` (retencja 6) |
| Podgląd | `/var/www/sk-preview`, URL `/podglad/` (basic auth) |
| Cron | `/etc/cron.d/studio-komp` — opinie co 6 h, porządki 3:23 |

**VPS nie buduje Astro** (ma 1,2 GB RAM). Buduje komputer właściciela, VPS tylko
przyjmuje i atomowo podmienia gotową wersję.

## 2. Pierwsze wdrożenie / odtworzenie

Wymagania: klucz SSH root, repo wysłane na VPS (`/srv/studio-komp`).

```bash
# na VPS (root)
chown -R skcms:skcms /srv/studio-komp
bash /srv/studio-komp/deploy/setup-cms.sh
```

Skrypt (idempotentny) instaluje/weryfikuje:

- pakiet PHP-FPM + rozszerzenia (sqlite3, gd, zip, intl, curl, mbstring, xml),
- katalogi danych i uprawnienia (`skcms`),
- `/srv/studio-komp-cms/config.php` (wzór: `panel/config.example.php`),
- pool PHP-FPM jako `skcms` (sesje poza webrootem, limity uploadu),
- `/etc/nginx/sk-redirects.conf`, `/etc/nginx/sk-preview.htpasswd` (hasło w
  `/srv/studio-komp-cms/data/preview-credentials.txt`),
- konfigurację nginx + test + restart `nginx-sk`,
- `/usr/local/bin/sk-switch-release` + sudoers dla `skcms`,
- wyłączenie starego panelu Decap (`studio-komp-cms.service`),
- cron opinii i porządków,
- konto administratora panelu (losowe hasło w
  `/srv/studio-komp-cms/data/initial-credentials.txt`).

Weryfikacja:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8088/        # 200
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8088/panel/  # 302
php /srv/studio-komp/panel/bin/self-check.php                          # OK
```

## 3. Publikacja z komputera (Windows)

```powershell
pnpm build                 # sam build (dist/)
pnpm publish:now           # build + wgranie (bez kolejki)
pnpm publish:remote        # publikacja, jeśli panel zgłosił żądanie
pnpm publish:preview       # build podglądu z wersjami roboczymi → /podglad/
pnpm publisher:install     # zadanie Windows co 5 min (automatyzacja)
```

Co robi `deploy/publish.ps1`:

1. zapisuje „heartbeat” komputera (widoczny w panelu),
2. domyka zmiany zapisane przez panel (commit na VPS),
3. commit lokalnych zmian, `git fetch`/`merge`, `git push` do repo na VPS,
4. `pnpm build` z `SITE_URL=https://sk.mati-it.pl`,
5. pakuje `dist/` do tar.gz, wysyła przez scp,
6. uruchamia `remote-release.sh`: nowy katalog wersji, uprawnienia, przekierowania,
   test nginx, atomowy symlink, manifest, stan dla panelu,
7. zapisuje log publikacji i weryfikuje HTTP 200.

Kolejka: panel zapisuje `publish-request.json`; zadanie Windows wykrywa plik i
publikuje. Po sukcesie plik jest usuwany, a panel pokazuje status „done”.

## 4. Podgląd wersji roboczych

- Panel → **Podgląd** → „Zbuduj podgląd” (żądanie `kind=preview`).
- Computer: `pnpm publish:preview` (build z `PREVIEW=1` → `dist-preview/`,
  drafty widoczne).
- `remote-preview.sh` podmienia `/var/www/sk-preview`; nginx serwuje
  `/podglad/` z basic auth i `X-Robots-Tag: noindex`.
- Dane logowania: `/srv/studio-komp-cms/data/preview-credentials.txt`.

## 5. Rollback

Panel → **Publikacja → Wersje → Przywróć** (bez przebudowy). Ręcznie na VPS:

```bash
sudo -u skcms sudo /usr/local/bin/sk-switch-release 20261125-110655
```

Wersje są w `/srv/studio-komp-releases`; aktualną wskazuje symlink
`/var/www/sk.mati-it.pl`. Retencja: 6 ostatnich.

## 6. Aktualizacja konfiguracji nginx / PHP

Konfiguracja nginx jest w repo (`deploy/nginx/sk.mati-it.pl.conf`); publikacja
kopiuje ją i przeładowuje nginx po teście. Po ręcznej zmianie:

```bash
cp /srv/studio-komp/deploy/nginx/sk.mati-it.pl.conf /etc/nginx/nginx-sk.conf
nginx -t -c /etc/nginx/nginx-sk.conf && nginx -s reload -c /etc/nginx/nginx-sk.conf
```

PHP-FPM: `/etc/php/8.3/fpm/pool.d/studio-komp.conf`, potem
`systemctl reload php8.3-fpm`.

## 7. Kopie zapasowe

- Kod i treści: git (repo lokalne + VPS), historia wersji panelu.
- Strona: katalogi wersji na VPS.
- Baza panelu: **brak automatycznej kopii** — zalecane:
  `sqlite3 panel.sqlite ".backup ..."` w cronie + kopia poza VPS
  (szczegóły i rekomendacje w `AGENTS.md`).
- Konfiguracja: `/etc/nginx/nginx-sk.conf`, `/etc/php/8.3/fpm/pool.d/studio-komp.conf`,
  `/srv/studio-komp-cms/config.php`, `/etc/cron.d/studio-komp`, sudoers.

## 8. Bezpieczeństwo dostępu

- SSH root tylko z klucza (`C:\Users\Mati\.ssh\id_ed25519_providers`).
- Panel: HTTPS (Cloudflare), hasło + 2FA, CSRF, limity logowania.
- `/podglad/` i stary `/edycja/` są oddzielnie chronione; `/edycja` przekierowuje
  na `/panel/`.
- Nie publikować `.env`, `config.php`, bazy SQLite — są poza webrootem.

## 9. Diagnostyka

| Objaw | Co sprawdzić |
|---|---|
| Strona 502/404 | `systemctl status nginx-sk php8.3-fpm`, `nginx -t` |
| Panel 500 | `/srv/studio-komp-cms/data/logs/php-error.log`, `panel.log` |
| Brak zmian po publikacji | panel → Publikacja (status/log); `pnpm publish:remote` ręcznie |
| Stara wersja w przeglądarce | Ctrl+F5; cache assetów (hashed) |
| Podgląd 401 | hasło z `preview-credentials.txt`, plik htpasswd |
| Opinie się nie odświeżają | klucz Places API, `reviews.log`, cron |

## 10. Czego nie robić

- Nie zmieniać DNS `studio-komp.pl` bez zgody właściciela.
- Nie uruchamiać builda Astro na VPS.
- Nie edytować plików w `/srv/studio-komp` bezpośrednio (poza panelem) —
  nadpisze je kolejna publikacja; używać panelu lub gita lokalnie.
- Nie ruszać innych usług/tuneli na VPS.
