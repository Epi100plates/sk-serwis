# Studio-Komp — nowa strona serwisu laptopów we Wrocławiu

Kompletna, statyczna strona serwisu laptopów Studio-Komp (Kotlarska 14, Wrocław), zbudowana w Astro 5. Zaprojektowana pod konwersję (telefony, wizyty), lokalne SEO i kampanie Google Ads.

**Wersja online (wdrożona): https://sk.mati-it.pl** — VPS + Cloudflare Tunnel, patrz [Wdrożenie na VPS](docs/wdrozenie-vps.md).

## Szybki start

```powershell
pnpm install
pnpm dev          # http://127.0.0.1:4321
pnpm build        # statyczna strona w katalogu dist/
pnpm preview      # podgląd builda
pnpm deploy       # build + wgranie na VPS (sk.mati-it.pl)
pnpm qa:live      # test działającej strony (sitemapa, linki, 301, 404)
```

Wymagania: Node.js 20+ i pnpm 9+.

## Co jest w środku

- **Strona główna** — hero ze „zgłoszeniem serwisowym”, opinie Google, sekcja ekspresu, usługi, przewagi, proces, cennik, realizacje, FAQ, kontakt z mapą.
- **16 podstron usług SEO** pod czystymi adresami (`/czyszczenie-laptopa-wroclaw/`, `/naprawa-plyt-glownych/`, …).
- **Poradnik** — 15 artykułów z linkowaniem do usług, FAQ i schema Article.
- **Cennik** — edytowalny plik JSON, ceny znane z obecnej strony, miejsca na ceny do uzupełnienia.
- **Opinie** — prawdziwe cytaty z Google + link do wizytówki; brak fikcyjnych opinii.
- **Realizacje** — galeria z autorskimi ilustracjami technicznymi (łatwe podmienienie na zdjęcia), obsługa wideo YouTube.
- **Kontakt** — dane NAP, godziny, mapa ładowana po kliknięciu, formularz zgłoszenia.
- **6 landing page Google Ads** (`/lp/...`) — noindex, zoptymalizowane pod telefon i formularz.
- **SEO techniczne** — sitemap, robots.txt, canonical, Open Graph, schema LocalBusiness/Service/FAQPage/Article/BreadcrumbList, przekierowania 301 ze starych adresów.
- **Analityka** — GTM/GA4 + Consent Mode v2, zdarzenia: `call_click`, `directions_click`, `cta_click`, `form_submit`, `generate_lead`.
- **Panel edycji** — Decap CMS pod `/admin/` (opcjonalnie, wymaga Git + Netlify).
- **Identyfikacja** — nowe logo (SVG/PNG), favicon, paleta i typografia; pliki w `public/brand/`.

## Struktura

```
src/
  components/      komponenty sekcji i UI
  content/uslugi/  16 podstron usług (Markdown)
  content/blog/    15 artykułów (Markdown)
  data/            site.json, cennik.json, opinie.json, realizacje.json, faq.json, wideo.json, landing.json
  layouts/         Base.astro, LandingLayout.astro
  lib/             pomocnicze funkcje (URL, schema)
  pages/           trasy: /, /uslugi/, /[usluga]/, /poradnik/, /cennik/, /lp/[slug]/ itd.
  styles/          global.css (tokeny, typografia, komponenty bazowe)
public/
  admin/           panel Decap CMS
  brand/           logo SVG/PNG
  images/          OG image, ilustracje realizacji
  .htaccess        przekierowania i cache dla obecnego hostingu
  _redirects       301 dla Netlify/Cloudflare
  robots.txt, sitemap generowany przy buildzie
docs/
  audyt-i-strategia.md   audyt starej strony, konkurencja, strategia
  logo-i-design.md       identyfikacja i system designu
  edycja.md              instrukcja edycji treści
  wdrozenie.md           środowisko testowe, publikacja, analityka
  wdrozenie-vps.md       wdrożona wersja: VPS + Cloudflare Tunnel (sk.mati-it.pl)
  brief-tresci.md        zasady redakcyjne (dla kolejnych treści)
deploy/
  deploy.ps1             jedno polecenie: build + wgranie na VPS
  server-setup.sh        konfiguracja nginx-sk i usługi cloudflared na VPS
  nginx/                 konfiguracja osobnej instancji nginx (127.0.0.1:8088)
```

## Dokumentacja

- [Audyt i strategia](docs/audyt-i-strategia.md)
- [Identyfikacja wizualna](docs/logo-i-design.md)
- [Edycja treści](docs/edycja.md)
- [Wdrożenie](docs/wdrozenie.md)
- [Wdrożenie na VPS (sk.mati-it.pl)](docs/wdrozenie-vps.md)
- [Wyniki testów](docs/qa-wyniki.md)

## Stan i uwagi

- Ceny pochodzą z obecnej strony Studio-Komp. Brakujące ceny oznaczono jako `… zł` — do uzupełnienia przez właściciela.
- Opinie (3 cytaty) pochodzą z wizytówki Google. Liczbę i ocenę aktualizuje się w `src/data/site.json`.
- Ilustracje realizacji są generowane proceduralnie (skrypt `scripts/generate-art.mjs`) i służą jako placeholdery do czasu dostarczenia zdjęć z warsztatu.
- Formularz działa przez Netlify Forms lub Web3Forms (wymaga klucza w `site.json`).
- Strona nie zmienia DNS `studio-komp.pl`, nie publikuje nic na tej domenie i nie usuwa starej wersji — nowa strona stoi pod `sk.mati-it.pl` (szczegóły w `docs/wdrozenie-vps.md`).
- Wersja testowa w podkatalogu (`BASE_PATH=/nowa/`) automatycznie ustawia `noindex, nofollow`.

## Skrypty pomocnicze

```powershell
pnpm art      # regeneracja ilustracji realizacji (SVG)
pnpm brand    # eksport logo PNG 3x
pnpm icons    # favicon.ico z SVG
pnpm og       # OG image 1200×630 (wymaga działającego dev servera)
pnpm shots    # zrzuty ekranu + pomiar przepełnienia layoutu
```
