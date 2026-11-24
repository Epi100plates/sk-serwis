# Wyniki testów i audytu — nowa strona Studio-Komp

Data testów: 24 listopada 2026. Środowisko: Chrome (headless) na Windows, produkcyjny build (`pnpm build` + `pnpm preview`).

## 1. Budowa i integralność

| Test | Wynik |
| --- | --- |
| `pnpm build` | OK — 50 stron (51 plików HTML z 404) |
| Zepsute linki wewnętrzne | 0 |
| Duplikaty meta title | 0 (51 unikalnych) |
| Duplikaty meta description | 0 |
| Strony bez H1 | 0 |
| Strony z wieloma H1 | 0 |
| Obrazy bez `alt` | 0 |
| Brakujące pliki obrazów | 0 |
| Wersja podkatalogowa (`BASE_PATH=/nowa/`) | OK — linki `/nowa/...`, automatyczny `noindex, nofollow` |
| Sitemap | 40 URL, bez stron LP, /admin, /dziekujemy, /404, /og-image, /logo-export |
| robots.txt | zawiera Sitemap; blokuje /lp/, /admin/, /dziekujemy/ |

## 2. Wydajność (Local, bez CDN)

| Strona | LCP | FCP | CLS | Żądania | Transfer |
| --- | --- | --- | --- | --- | --- |
| Strona główna | 584 ms | 584 ms | 0,012 | 20 | 274 KB |
| Podstrona usługi | 424 ms | 424 ms | 0,003 | 18 | 207 KB |
| Cennik | 280 ms | 280 ms | 0,002 | 16 | 203 KB |
| Artykuł bloga | ~500 ms | ~512 ms | ~0,002 | 17 | 203 KB |

Wynik: brak JavaScriptowego frameworka, jedno małe JS (~2 KB) na stronę, fonty self-hosted (woff2), brak zewnętrznych zależności poza mapą i analityką (ładowanymi po interakcji/zgodzie). Obrazy realizacji to SVG (~6 KB łącznie).

## 3. Dostępność (axe-core, WCAG 2.1 AA)

| Strona | Naruszenia |
| --- | --- |
| Strona główna | 0 |
| Podstrona usługi | 0 |
| Cennik | 0 |
| Artykuł bloga | 0 |

Naprawione w trakcie testów: kontrast etykiet mono (4,03–4,43 → powyżej 4,5), `aria-label` na ocenie gwiazdek bez roli, kolor logo w stopce na ciemnym tle.

## 4. Testy manualne / funkcjonalne

- [x] Menu „Usługi” (desktop) — panel z 16 usługami, zamykanie Escape/kliknięciem poza.
- [x] Menu mobilne — pełny ekran, blokada scrolla, zamknięcie po kliknięciu linku.
- [x] Stały pasek mobilny „Zadzwoń | Dojazd”.
- [x] Akordeon FAQ — pytania i odpowiedzi widoczne, FAQPage w schema.
- [x] Mapa — podgląd zastępczy, iframe Google dopiero po kliknięciu.
- [x] Formularz — walidacja HTML, pole honeypot, komunikat statusu, Netlify Forms/Web3Forms.
- [x] Wszystkie linki `tel:` prowadzą do +48 500 613 500.
- [x] Brak przepełnienia poziomego na 390 px i 1440 px (wszystkie testowane strony).
- [x] `prefers-reduced-motion` — animacje wyłączone.
- [x] Favicon (SVG/ICO), apple-touch-icon, manifest, OG image 1200×630.
- [x] Podgląd OG — poprawna typografia i treść.

## 5. SEO — checklist

- [x] Jeden H1 na stronę, fraza + Wrocław w H1 i title.
- [x] Meta title 40–60 znaków, description 137–160.
- [x] Canonical na każdej stronie (produkcyjny host).
- [x] Open Graph + Twitter Card + lang="pl".
- [x] Schema: LocalBusiness, ProfessionalService, WebSite, Service, FAQPage, BreadcrumbList, Article.
- [x] Linkowanie wewnętrzne: hub → usługi → powiązane; blog → usługi; usługi → cennik/kontakt.
- [x] `sitemap-index.xml` + `sitemap-0.xml`.
- [x] `robots.txt`.
- [x] Przekierowania 301 dla 13 starych adresów (+ wildcard).
- [x] Strony LP i techniczne noindex.
- [x] Alt teksty / ilustracje dekoracyjne bez alt.

## 6. Do zrobienia po stronie właściciela (przed publikacją)

1. Uzupełnić brakujące ceny (`… zł`) w `src/data/cennik.json` lub CMS.
2. Potwierdzić ocenę i liczbę opinii Google w `src/data/site.json`.
3. Wgrać własne zdjęcia realizacji (podmiana grafik technicznych).
4. Wpisać ID analityki (GTM/GA4/Ads) w `site.json`.
5. Skonfigurować formularz (Netlify Forms lub klucz Web3Forms).
6. Wykonać kopię zapasową starej strony i wdrożyć wg `docs/wdrozenie.md`.

## 7. Weryfikacja wdrożenia na VPS — https://sk.mati-it.pl (24.11.2026)

- [x] 39 adresów z `sitemap-index.xml` + `sitemap-0.xml` → HTTP 200, canonical zgodny z adresem, H1 na każdej stronie.
- [x] 44 unikalne linki wewnętrzne → HTTP 200 (skrypt `pnpm qa:live`).
- [x] 7 przekierowań 301 ze starych adresów (`/cennik.html`, `/o-firmie.html`, `/galeria.html`, `/kontakt.html`, `/aktualnosci/`, `/googleads`, `/mapa/`) → poprawne cele; nieistniejący adres → 404.
- [x] axe-core: brak naruszeń WCAG 2.1 AA (strona główna, podstrona usługi, cennik, artykuł).
- [x] Core Web Vitals: LCP 364–844 ms, CLS ≤ 0,012, transfer 203–274 KB, 16–20 żądań.
- [x] HTTPS z certyfikatem Cloudflare, `http://` → `https://` 301, HTTP/2, gzip, cache `immutable` dla `/_astro/`.
- [x] Pliki ukryte (`.htaccess`) → 403; katalog `lp/`, `admin/` — `noindex`.
- [x] Usługi systemd `nginx-sk` i `studio-komp-tunnel` — `enabled` (przetrwają restart VPS).

