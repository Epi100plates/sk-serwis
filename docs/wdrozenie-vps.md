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
   └─ usługa systemd: nginx-sk.service            (osobna instancja nginx)
          └─ 127.0.0.1:8088  →  /var/www/sk.mati-it.pl  (pliki statyczne)
```

Ważne: **nie ruszamy głównego `/etc/nginx/nginx.conf`** (obsługuje on file-uploader
na `pliki.mati-it.pl`). Strona ma własną, minimalną instancję nginx z własnym
pid-em i logami. Port 8088 słucha wyłącznie na `127.0.0.1`.

## Co zostało utworzone

| Element | Wartość |
|---|---|
| Adres | https://sk.mati-it.pl |
| Katalog na serwerze | `/var/www/sk.mati-it.pl` |
| Tunel Cloudflare | `studio-komp`, ID `c2c23b65-7de1-4e83-8659-f70241d4d59e` |
| Ingress tunelu | `sk.mati-it.pl → http://localhost:8088`, reszta → 404 |
| DNS | CNAME `sk.mati-it.pl` → `c2c23b65-….cfargotunnel.com`, proxied |
| Usługa tunelu | `studio-komp-tunnel.service` (`/usr/local/bin/cloudflared`) |
| Token tunelu | `/etc/studio-komp-tunnel/token` (root:cloudflared, 640) |
| Usługa strony | `nginx-sk.service`, konfiguracja `/etc/nginx/nginx-sk.conf` |
| Logi nginx | `/var/log/nginx/sk.access.log`, `/var/log/nginx/sk.error.log` |
| Log tunelu | `journalctl -u studio-komp-tunnel` |

Obie usługi są `enabled` — startują automatycznie po restarcie VPS.

## Aktualizacja strony (po zmianie treści)

Z komputera, w katalogu projektu:

```powershell
pnpm deploy
```

Skrypt `deploy/deploy.ps1`:
1. buduje stronę z `SITE_URL=https://sk.mati-it.pl` (poprawne canonical, sitemap, robots.txt),
2. pakuje `dist/` do `release/site-sk.tar.gz`,
3. wysyła paczkę przez SCP i rozpakowuje na serwerze,
4. restartuje `nginx-sk` i `studio-komp-tunnel` (idempotentnie),
5. sprawdza odpowiedź HTTP.

Szybkie wdrożenie bez ponownego builda: `pnpm deploy -SkipBuild` (albo `pwsh -File deploy\deploy.ps1 -SkipBuild`).

## Zmiana treści

- **Ceny** — `src/data/cennik.json`
- **Dane firmy, telefon, opinie, analityka** — `src/data/site.json`
- **Opinie** — `src/data/opinie.json`
- **Realizacje** — `src/data/realizacje.json` (pole `image` podmienia grafikę na zdjęcie)
- **FAQ** — `src/data/faq.json`
- **Usługi** — `src/content/uslugi/*.md`
- **Poradnik** — `src/content/blog/*.md`

Po edycji: `pnpm deploy`. Panel CMS `/admin/` (Decap) działa dopiero po podłączeniu
GitHub + Netlify Identity — opis w `docs/edycja.md`.

## Sprawdzenie działającej strony

```powershell
pnpm qa:live
```

Skrypt `scripts/check-live.mjs` przechodzi sitemapę, sprawdza 39 adresów, wszystkie
linki wewnętrzne, canonicale, H1, 301 ze starych adresów i stronę 404.

## Diagnostyka

```bash
# na serwerze
systemctl status nginx-sk studio-komp-tunnel
journalctl -u studio-komp-tunnel -n 50 --no-pager
curl -I http://127.0.0.1:8088/
nginx -t -c /etc/nginx/nginx-sk.conf
```

- **502 / 1033** — tunel nie jest połączony: `systemctl restart studio-komp-tunnel`.
- **403** — uprawnienia plików: `chown -R www-data:www-data /var/www/sk.mati-it.pl`.
- **404 na podstronie** — brak pliku po niepełnym wgraniu; powtórz `pnpm deploy`.

## Przejście na docelową domenę studio-komp.pl (później)

1. Zbuduj wersję domyślną: `pnpm build` (bez `SITE_URL`) — canonical i sitemap wskażą `studio-komp.pl`.
2. Wgraj `dist/` tam, gdzie będzie docelowo stać strona (obecny hosting lub ten VPS).
3. Zmień DNS `studio-komp.pl` na Cloudflare dopiero po akceptacji strony.
4. Alternatywnie: dodaj do tunelu `studio-komp.pl` jako kolejny hostname w ingress.

## Wycofanie

```bash
# na serwerze
systemctl disable --now studio-komp-tunnel nginx-sk
rm -f /etc/systemd/system/studio-komp-tunnel.service /etc/systemd/system/nginx-sk.service
# w Cloudflare: usuń rekord DNS sk.mati-it.pl i tunel "studio-komp"
```

Pliki strony leżą w `/var/www/sk.mati-it.pl` — usunięcie katalogu nie wpływa na
inne usługi na VPS.
