# Wdrożenie na VPS + Cloudflare Tunnel (`sk.mati-it.pl`)

Strona działa pod adresem **https://sk.mati-it.pl** — wdrożone 24.11.2026.
Domena `studio-komp.pl`, jej DNS i stara strona **pozostały nietknięte**.

## Architektura

```
przeglądarka
   │  HTTPS (Cloudflare, certyfikat zarządzany przez CF)
   ▼
Cloudflare  ──DNS CNAME sk.mati-it.pl → c2c23b65-….cfargotunnel.com──┐
   │                                                                  │
   │  tunel wychodzący z VPS (bez otwierania portów na serwerze)      │
   ▼                                                                  │
VPS olga322.mikrus.xyz (Ubuntu 24.04)  ◀──────────────────────────────┘
   ├─ usługa systemd: studio-komp-tunnel.service  (cloudflared, user cloudflared)
   ├─ usługa systemd: nginx-sk.service           (osobna instancja nginx)
   │      └─ 127.0.0.1:8088  →  /var/www/sk.mati-it.pl  (pliki statyczne)
   ├─ usługa systemd: studio-komp-cms.service    (decap-server, 127.0.0.1:8081)
   │      └─ panel /edycja/ — czyta i zapisuje pliki w /srv/studio-komp
   └─ usługa systemd: studio-komp-publish.service (build + rsync po commicie)
          └─ wyzwalana przez studio-komp-publish.path (zmiana refs/heads/main)
```

Ważne: **nie ruszamy głównego `/etc/nginx/nginx.conf`** (obsługuje on file-uploader
na `pliki.mati-it.pl`). Strona ma własną, minimalną instancję nginx z własnym
pid-em i logami. Port 8088 słucha wyłącznie na `127.0.0.1`.

## Co zostało utworzone

| Element | Wartość |
|---|---|
| Adres | https://sk.mati-it.pl |
| Katalog na serwerze (pliki strony) | `/var/www/sk.mati-it.pl` |
| Repozytorium źródeł (git) | `/srv/studio-komp` (użytkownik `skcms`, branch `main`) |
| Tunel Cloudflare | `studio-komp`, ID `c2c23b65-7de1-4e83-8659-f70241d4d59e` |
| Ingress tunelu | `sk.mati-it.pl → http://localhost:8088`, reszta → 404 |
| DNS | CNAME `sk.mati-it.pl` → `c2c23b65-….cfargotunnel.com`, proxied |
| Usługa tunelu | `studio-komp-tunnel.service` (`/usr/local/bin/cloudflared`) |
| Token tunelu | `/etc/studio-komp-tunnel/token` (root:cloudflared, 640) |
| Usługa strony | `nginx-sk.service`, konfiguracja `/etc/nginx/nginx-sk.conf` |
| Panel edycji | `studio-komp-cms.service` — decap-server na `127.0.0.1:8081` |
| Publikacja | `studio-komp-publish.service` + `studio-komp-publish.path` |
| Hasło panelu | `/etc/nginx/sk-edycja.htpasswd` (root:www-data, 640) |
| Znacznik ostatniej publikacji | `/var/lib/studio-komp/last-publish` |
| Node.js / pnpm / decap-server | `/opt/node22`, `pnpm@10`, globalny `decap-server@3` |
| Logi nginx | `/var/log/nginx/sk.access.log`, `/var/log/nginx/sk.error.log` |
| Log tunelu | `journalctl -u studio-komp-tunnel` |
| Log panelu / builda | `journalctl -u studio-komp-cms`, `journalctl -u studio-komp-publish` |

Wszystkie usługi są `enabled` — startują automatycznie po restarcie VPS.

## Panel edycji `/edycja/`

- Adres: **https://sk.mati-it.pl/edycja/** (dostęp chroniony hasłem, basic auth).
- Stary adres `/admin/` przekierowuje na `/edycja/` (301).
- Panel obsługuje Decap CMS: cennik, opinie, realizacje, wideo, dane firmy,
  landing page, podstrony usług i artykuły poradnika.
- Serwer proxy (`decap-server`, tryb `git`) zapisuje zmiany bezpośrednio
  w `/srv/studio-komp` i robi commit. Każdy commit uruchamia build i publikację.
- Hasło do panelu jest trzymane **poza repozytorium** — na komputerze, z którego
  uruchamiano `pnpm provision`, w `%LOCALAPPDATA%\studio-komp-deploy\edycja-password.txt`.
  Zmiana hasła: `pnpm provision -PanelPassword "nowe-haslo"`.

## Aktualizacja strony

Źródłem prawdy jest repozytorium git na VPS. Dwa równoważne sposoby:

1. **Panel** — https://sk.mati-it.pl/edycja/ (zmiana → commit → build → publikacja).
2. **Z laptopa**:

```powershell
pnpm pull      # jeśli treść była zmieniana w panelu (inaczej push zostanie odrzucony)
pnpm deploy    # commit lokalnych zmian + git push na VPS + oczekiwanie na publikację
```

`pnpm deploy` nie wysyła już gotowego `dist/` — wysyła commity do repozytorium na
VPS, a serwer sam buduje stronę (`deploy/publish.sh`). Dzięki temu zmiany z panelu
i z laptopa trafiają do tego samego miejsca i nie nadpisują się nawzajem.

Czas builda na tym VPS (1 vCPU, współdzielony serwer) to **kilka–kilkanaście minut**.
Panel nie czeka na build — commit jest natychmiast, strona zmienia się po zakończeniu
publikacji.

### Ręczne przebudowanie strony

```bash
# na serwerze
systemctl start studio-komp-publish          # jednorazowo: build + publikacja
journalctl -u studio-komp-publish -f         # podgląd na żywo
cat /var/lib/studio-komp/last-publish        # sha + data ostatniej publikacji
```

## Zmiana konfiguracji infrastruktury

Po zmianie `deploy/nginx/sk.mati-it.pl.conf` lub `deploy/editor-setup.sh`:

```powershell
pnpm provision
```

Skrypt wgrywa konfiguracje, instaluje/odświeża usługi systemd, ustawia hasło do
panelu i przeładowuje `nginx-sk`. Jest idempotentny. Źródła wgrywa tylko przy
pierwszej instalacji (później obowiązuje `pnpm deploy`).

## Zmiana treści — ściągawka

- **Ceny** — `src/data/cennik.json`
- **Dane firmy, telefon, opinie, analityka** — `src/data/site.json`
- **Opinie** — `src/data/opinie.json`
- **Realizacje** — `src/data/realizacje.json` (pole `image` podmienia grafikę na zdjęcie)
- **FAQ** — `src/data/faq.json`
- **Usługi** — `src/content/uslugi/*.md`
- **Poradnik** — `src/content/blog/*.md`

## Sprawdzenie działającej strony

```powershell
pnpm qa:live
```

Skrypt `scripts/check-live.mjs` przechodzi sitemapę, sprawdza 39 adresów, wszystkie
linki wewnętrzne, canonicale, H1, 301 ze starych adresów i stronę 404.

## Diagnostyka

```bash
# na serwerze
systemctl status nginx-sk studio-komp-tunnel studio-komp-cms studio-komp-publish.path
journalctl -u studio-komp-cms -n 50 --no-pager
journalctl -u studio-komp-publish -n 100 --no-pager
curl -I http://127.0.0.1:8088/
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1:8081/api/v1 \
  -H 'Content-Type: application/json' -d '{"action":"info"}'
nginx -t -c /etc/nginx/nginx-sk.conf
```

- **502 / 1033 (strona)** — tunel nie jest połączony: `systemctl restart studio-komp-tunnel`.
- **502 w panelu** — `studio-komp-cms` nie działa: `systemctl restart studio-komp-cms`.
- **401 w panelu** — złe hasło; patrz `%LOCALAPPDATA%\studio-komp-deploy\edycja-password.txt`.
- **403 (strona)** — uprawnienia plików: `chown -R www-data:www-data /var/www/sk.mati-it.pl`.
- **404 na podstronie** — brak pliku po niepełnym wgraniu; powtórz `pnpm deploy`.
- **Commit jest, strona bez zmian** — build padł: `journalctl -u studio-komp-publish -n 100`.

## Odzyskanie poprzedniej wersji treści

```bash
# na serwerze
cd /srv/studio-komp
git log --oneline -10
git revert <sha> && systemctl start studio-komp-publish
```

## Przejście na docelową domenę studio-komp.pl (później)

1. Zbuduj wersję domyślną: `SITE_URL=https://studio-komp.pl pnpm build` — albo
   zmień `SITE_URL` w `deploy/publish.sh` / `deploy.ps1`, żeby serwer budował
   canonicale pod nową domenę.
2. Zmień DNS `studio-komp.pl` na Cloudflare dopiero po akceptacji strony.
3. Alternatywnie: dodaj do tunelu `studio-komp.pl` jako kolejny hostname w ingress.

## Wycofanie

```bash
# na serwerze
systemctl disable --now studio-komp-tunnel nginx-sk studio-komp-cms studio-komp-publish.path
rm -f /etc/systemd/system/studio-komp-{tunnel,cms,publish}.service \
      /etc/systemd/system/studio-komp-publish.path
systemctl daemon-reload
```

W Cloudflare: usuń rekord DNS `sk.mati-it.pl` i tunel `studio-komp`.
Pliki strony leżą w `/var/www/sk.mati-it.pl`, źródła w `/srv/studio-komp` — usunięcie
tych katalogów nie wpływa na inne usługi na VPS.
