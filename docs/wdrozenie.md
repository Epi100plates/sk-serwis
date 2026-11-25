# Wdrożenie — przegląd

Aktualna architektura (2026-11-25): strona statyczna Astro + panel CMS PHP/SQLite
na VPS, **build lokalny** na komputerze właściciela.

- Szczegóły VPS, publikacji i rollbacku: [wdrozenie-vps.md](wdrozenie-vps.md)
- Panel i obsługa treści: [edycja.md](edycja.md)
- Hosting klasyczny (ZIP na www): [wdrozenie-hosting-www.md](wdrozenie-hosting-www.md)
- Pełna dokumentacja techniczna: [../AGENTS.md](../AGENTS.md)

## Środowiska

| Środowisko | Adres | Uwagi |
|---|---|---|
| Lokalne (dev) | http://127.0.0.1:4321 | `pnpm dev`, drafty widoczne |
| Testowe (wdrożone) | https://sk.mati-it.pl | VPS + Cloudflare Tunnel, panel `/panel/` |
| Produkcja docelowa | https://studio-komp.pl | DNS/stara strona nietknięte — wymaga decyzji właściciela |

## Codzienny workflow

```powershell
# 1. praca nad treścią w panelu (https://sk.mati-it.pl/panel/)
# 2. publikacja:
pnpm publish:remote        # jeśli panel zgłosił żądanie
pnpm publish:now           # natychmiast, bez kolejki
# 3. weryfikacja:
pnpm qa:live               # crawl live
```

Build zawsze lokalny. VPS nie ma Node do budowania (1,2 GB RAM).

## Zmienne środowiskowe builda

| Zmienna | Znaczenie |
|---|---|
| `SITE_URL` | origin dla canonical/sitemap/OG (domyślnie `https://studio-komp.pl`) |
| `BASE_PATH` | podkatalog dla paczki na hosting (np. `/sk/`) |
| `PREVIEW=1` | build z wersjami roboczymi do `dist-preview/` |

## Publikacja na VPS (skrót)

1. Panel → „Poproś o publikację”.
2. Zadanie Windows „Studio-Komp Publish” (co 5 min) albo `pnpm publish:remote`.
3. Skrypt commituje, scala zmiany z panelem, buduje, wysyła i atomowo podmienia
   wersję; panel pokazuje status i log.
4. Rollback: panel → Publikacja → Wersje → Przywróć.

## Publikacja na hosting klasyczny (bez VPS)

```powershell
pnpm pack:www     # ZIP w release/studio-komp-www.zip (działa w / i /sk/)
```

Szczegóły i diagnostyka „brak CSS”: [wdrozenie-hosting-www.md](wdrozenie-hosting-www.md).

## Analityka

- Własny pomiar działa od razu (bez zewnętrznych narzędzi).
- Aby podłączyć GA4/GTM/Ads/Clarity: panel → Ustawienia → Analityka (wpisać ID).
- Zgody: CMP + Consent Mode v2; szczegóły w AGENTS.md i polityce prywatności.

## Kopie zapasowe

- Kod/treści: git (lokalnie + `/srv/studio-komp` na VPS).
- Strona: wersje w `/srv/studio-komp-releases`.
- Baza panelu: skopiować `/srv/studio-komp-cms/data/panel.sqlite` (zalecany cron).
