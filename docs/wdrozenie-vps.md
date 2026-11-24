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
   └─ usługa systemd: studio-komp-cms.service    (decap-server, 127.0.0.1:8081)
          └─ panel /edycja/ — czyta i zapisuje pliki w /srv/studio-komp (git)

laptop (Windows)
   └─ pnpm deploy: pull z /srv/studio-komp → build Astro → scp dist → /var/www
```

**Build dzieje się na laptopie, nie na VPS.** Serwer ma 1 vCPU i jest współdzielony
(inne usługi: file-uploader, filman, docker) — build Astro trwający tam ~11 minut
zawieszał całą maszynę. Dlatego na serwerze nie ma żadnej usługi budującej.

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
| Hasło panelu | `/etc/nginx/sk-edycja.htpasswd` (root:www-data, 640) |
| Node.js / decap-server | `/opt/node22`, globalny `decap-server@3` |
| Logi nginx | `/var/log/nginx/sk.access.log`, `/var/log/nginx/sk.error.log` |
| Log tunelu | `journalctl -u studio-komp-tunnel` |
| Log panelu | `journalctl -u studio-komp-cms` |

Wszystkie usługi są `enabled` — startują automatycznie po restarcie VPS.

## Panel edycji `/edycja/`

- Adres: **https://sk.mati-it.pl/edycja/** (dostęp chroniony hasłem, basic auth).
- Stary adres `/admin/` przekierowuje na `/edycja/` (301).
- Panel obsługuje Decap CMS: cennik, opinie, realizacje, wideo, dane firmy,
  landing page, podstrony usług i artykuły poradnika.
- Serwer proxy (`decap-server`, tryb `git`) zapisuje zmiany w `/srv/studio-komp`
  i robi commit. **Nie buduje strony** — publikacja to `pnpm deploy` na laptopie.
- Hasło jest trzymane poza repozytorium: na komputerze, z którego uruchamiano
  `pnpm provision`, w `%LOCALAPPDATA%\studio-komp-deploy\edycja-password.txt`.
  Zmiana hasła: `pnpm provision -PanelPassword "nowe-haslo"`.

### Co jest potrzebne, żeby panel działał

| Wymóg | Gdzie |
| --- | --- |
| `decap-server` na 127.0.0.1:8081 | `studio-komp-cms.service` |
| `config.yml` z typem `text/yaml` | `deploy/nginx/sk.mati-it.pl.conf` (`location = /edycja/config.yml`) |
| Proxy `/edycja/api/` z `Host: 127.0.0.1` | `deploy/nginx/sk.mati-it.pl.conf` |
| Hasło | `/etc/nginx/sk-edycja.htpasswd` |
| Uprawnienia repo dla `skcms` | `pnpm deploy` robi `chown -R skcms:skcms /srv/studio-komp` po pushu |
| `HOME` poza repo | `/var/lib/studio-komp-cms` (inaczej Node tworzy `.config/`, `.cache/` w repo) |

## Publikacja (z laptopa)

```powershell
pnpm pull      # jeśli treść była zmieniana w panelu
pnpm deploy    # commit lokalnych zmian → push → pull z panelu → build → wgranie dist/
```

`pnpm deploy`:

1. commituje lokalne zmiany (jeśli są),
2. pobiera commity z panelu (`git pull`),
3. wypycha całość do `/srv/studio-komp` (`git push`) i porządkuje właściciela plików,
4. buduje stronę lokalnie (`pnpm build`, `SITE_URL=https://sk.mati-it.pl`) — ~15 s,
5. pakuje `dist/` i wysyła na serwer, gdzie `rsync --delete` podmienia
   `/var/www/sk.mati-it.pl`,
6. sprawdza odpowiedź strony i to, że panel jest pod hasłem.

Build lokalny: 4 s + ~10 s narzutu. Build na VPS: ~11 minut i obciążenie całej
maszyny — dlatego zrezygnowaliśmy z budowania na serwerze.

### Ręczne wgranie bez zmian w treści

```powershell
pnpm deploy -SkipBuild
```

## Zmiana konfiguracji infrastruktury

Po zmianie `deploy/nginx/sk.mati-it.pl.conf` lub `deploy/editor-setup.sh`:

```powershell
pnpm provision
```

Skrypt wgrywa konfiguracje, instaluje/odświeża usługę `studio-komp-cms`, usuwa
stare jednostki budujące, ustawia hasło do panelu i przeładowuje `nginx-sk`.
Jest idempotentny. Źródła wgrywa tylko przy pierwszej instalacji (później
obowiązuje `pnpm deploy`).

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
systemctl status nginx-sk studio-komp-tunnel studio-komp-cms
journalctl -u studio-komp-cms -n 50 --no-pager
curl -I http://127.0.0.1:8088/
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1:8081/api/v1 \
  -H 'Host: 127.0.0.1' -H 'Content-Type: application/json' -d '{"action":"info"}'
nginx -t -c /etc/nginx/nginx-sk.conf
```

- **502 / 1033 (strona)** — tunel nie jest połączony: `systemctl restart studio-komp-tunnel`.
- **502 w panelu** — `studio-komp-cms` nie działa: `systemctl restart studio-komp-cms`.
- **401 w panelu** — złe hasło; patrz `%LOCALAPPDATA%\studio-komp-deploy\edycja-password.txt`.
- **403 (strona)** — uprawnienia plików: `chown -R www-data:www-data /var/www/sk.mati-it.pl`.
- **404 na podstronie** — brak pliku po niepełnym wgraniu; powtórz `pnpm deploy`.
- **Panel: „insufficient permission for adding an object”** — root zostawił pliki
  w repo; `pnpm deploy` robi `chown -R skcms:skcms /srv/studio-komp`.
- **Panel: „Host … is not allowed”** — brak `proxy_set_header Host 127.0.0.1`
  w `location /edycja/api/`; uruchom `pnpm provision`.

## Odzyskanie poprzedniej wersji treści

```bash
# na laptopie
git log --oneline -10
git revert <sha>
pnpm deploy
```

## Przejście na docelową domenę studio-komp.pl (później)

1. Zmień `SITE_URL` w `deploy/deploy.ps1` (parametr `-SiteUrl`) na
   `https://studio-komp.pl` i przebuduj: `pnpm deploy -SiteUrl https://studio-komp.pl`.
2. Zmień DNS `studio-komp.pl` na Cloudflare dopiero po akceptacji strony.
3. Alternatywnie: dodaj do tunelu `studio-komp.pl` jako kolejny hostname w ingress.

## Wycofanie

```bash
# na serwerze
systemctl disable --now studio-komp-tunnel nginx-sk studio-komp-cms
rm -f /etc/systemd/system/studio-komp-{tunnel,cms}.service
systemctl daemon-reload
```

W Cloudflare: usuń rekord DNS `sk.mati-it.pl` i tunel `studio-komp`.
Pliki strony leżą w `/var/www/sk.mati-it.pl`, źródła w `/srv/studio-komp` — usunięcie
tych katalogów nie wpływa na inne usługi na VPS.
