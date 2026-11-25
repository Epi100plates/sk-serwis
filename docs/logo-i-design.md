# Identyfikacja wizualna i system designu

## Kierunek: „precyzja”

Nowa strona Studio-Komp wygląda jak dokumentacja precyzyjnego warsztatu, a nie jak typowa strona serwisu komputerowego. Zamiast niebieskich gradientów, ikon laptopów i stockowych zdjęć używamy: bieli, grafitu, cienkich linii technicznych i jednego charakterystycznego elementu — **zgłoszenia serwisowego** w kolorze sygnałowego żółtego.

Założenia:

- **Minimalizm premium** — dużo światła, mocna typografia, cienkie linie zamiast cieni i zaokrągleń.
- **Jeden mocny obiekt na stronę** — na stronie głównej jest nim żółte „zgłoszenie serwisowe” z pieczątką „EKSPRES”. Reszta pozostaje spokojna.
- **Zero stocku** — zdjęcia są zastąpione autorskimi ilustracjami technicznymi (generowane SVG), które można podmienić na prawdziwe zdjęcia w panelu CMS.
- **Mobile first** — projektowany najpierw na 390 px; duże przyciski, czytelne nagłówki, stały pasek „Zadzwoń | Dojazd”.

## Logo

Znak to monogram „S” wycięty z kwadratu — geometryczny, oparty na kącie prostym, z żółtym punktem-padem w prawym dolnym rogu (skojarzenie z płytą drukowaną i punktem lutowniczym). Wordmark „studio-komp” pisany małymi literami, ciasno, w Schibsted Grotesk 600. Pod nim techniczny dopisek „serwis laptopów · wrocław” w IBM Plex Mono.

Pliki:

| Plik | Zastosowanie |
| --- | --- |
| `public/brand/studio-komp-logo.svg` | wersja wektorowa na jasne tło |
| `public/brand/studio-komp-logo-light.svg` | wersja wektorowa na ciemne tło |
| `public/brand/studio-komp-logo-dark@3x.png` | PNG 3x do reklam i materiałów |
| `public/brand/studio-komp-logo-light@3x.png` | PNG 3x na ciemne tło |
| `public/brand/studio-komp-mark.svg` / `mark-light.svg` | sam znak (avatar, favicon, pieczątka) |
| `public/brand/studio-komp-mark@3x.png` | znak PNG 3x |
| `public/favicon.svg`, `public/favicon.ico`, `public/apple-touch-icon.png`, `public/icon-192.png`, `public/icon-512.png` | favicon i ikony PWA |

Zasady:

- Minimalna szerokość logo w wersji poziomej: 120 px (ekran) / 30 mm (print).
- Pole ochronne: wysokość znaku z każdej strony.
- Na żółtym tle używaj samego znaku w wersji ciemnej (czarny kwadrat + biały monogram).
- Nie rozciągaj, nie obracaj, nie dodawaj cieni ani gradientów.

## Kolory

| Token | Wartość | Rola |
| --- | --- | --- |
| `--ink` | `#0E100F` | tekst, tła ciemnych sekcji |
| `--graphite` | `#1A1D1B` | sekcje premium (opinie, kontakt) |
| `--paper` | `#FFFFFF` | tło bazowe |
| `--surface` | `#F3F4F1` | delikatne sekcje |
| `--line` | `#DCDFD9` | cienkie linie i obramowania |
| `--muted` | `#5F645E` | tekst drugorzędny |
| `--yellow` | `#FFD400` | akcent marki: CTA, pieczątka, wyróżnienia |
| `--yellow-soft` | `#FFF4B8` | tło wyróżnień w treści |

Żółty jest akcentem, nie tłem strony. Występuje maksymalnie w 2–3 miejscach na ekran i zawsze z czarnym tekstem (kontrast 13:1).

## Typografia

- **Nagłówki i tekst:** `Schibsted Grotesk Variable` (400–900), ciasny tracking w nagłówkach (-0,035 do -0,045 em), interlinia 0,95–1,05 w dużych rozmiarach.
- **Etykiety techniczne, ceny, numery:** `IBM Plex Mono` 400/500, wielkie litery, letter-spacing 0,06–0,12 em.
- Skala: H1 `clamp(40px, 6,2vw, 88px)`, H2 `clamp(31px, 4,4vw, 62px)`, body 16–17 px, lead 17–21 px.

Skala odstępów: sekcje `clamp(64px, 8vw, 124px)`, wewnętrzne odstępy 8/12/16/20/24/32/48/64.

## Komponenty

- **ServiceDocket** — żółte zgłoszenie serwisowe (hero). Jedyne miejsce z obrotem i animacją wejścia.
- **PageHero** — nagłówek podstron z breadcrumbem, chipami (cena, czas, przyjęcie) i dwoma CTA.
- **PriceTable** — cennik jak karta katalogowa: nazwa + cena mono po prawej, notka pod nazwą.
- **Reviews** — ciemna sekcja z oceną 4,9 i cytatami z Google.
- **CaseGrid** — realizacje z ilustracjami technicznymi, pierwsza karta szeroka.
- **Faq** — akordeon `details/summary`, lewa kolumna przyklejona.
- **ContactBlock** — ciemna sekcja z telefonem, adresem i mapą ładowaną po kliknięciu.
- **MobileBar** — stały dolny pasek „Zadzwoń | Dojazd” do 899 px.

## Ruch

Jedna animacja wejścia (żółte zgłoszenie + pieczątka), delikatne przejścia hover. Wszystkie animacje wyłączone przy `prefers-reduced-motion: reduce`. Brak animacji przy scrollu, brak autoplay wideo.

## Dostępność

- Kontrast tekstu min. 4,5:1 (żółty tylko z czarnym tekstem).
- Widoczny focus (`:focus-visible`, 2 px outline).
- Przyciski min. 54 px wysokości, na mobile 58 px.
- `aria-*` w nawigacji, akordeonach, formularzu; skip link.
- Formularz: etykiety widoczne, błędy przy polach, poprawna semantyka.

## Materiały do podmiany

Ilustracje realizacji (`public/images/realizacje/*.svg`) i grafiki sekcji można zastąpić zdjęciami:

1. Wgraj zdjęcie w CMS (Realizacje → dane realizacji → Zdjęcie) lub wrzuć plik do `public/images/uploads/`.
2. Uzupełnij pole `image` w `src/data/realizacje.json` (np. `/images/uploads/moje-zdjecie.jpg`).
3. Jeśli `image` jest puste, strona użyje grafiki technicznej.

## Nowe logo (2026-11-25)

Nowe logo Studio-Komp zastępuje dotychczasowy monogram „S” (`public/brand/*.svg`).
Źródłem jest `assets-src/logo-source.jpg` (498×168): kontur laptopa z żółtym obramowaniem,
litery „SK”, napis „STUDIO-KOMP” i podpis „Serwis laptopów”.

Assety generuje skrypt `assets-src/_logo-work.py` (Pillow). Mata alfa powstaje z odległości
koloru od tła, kształt jest przenoszony przez kanał alfa, a kolor piksela to zawsze kolor
palety (dzięki temu nie ma halo). Upscale 3× robiony jest na premultiplied RGBA filtrem
Lanczos, z feather 1 px na alfie i lekkim wyostrzeniem (`UnsharpMask`) wersji na tle.
Proporcje i kształty liter pochodzą wprost z pliku źródłowego — nic nie jest rysowane od nowa.

### Paleta logo

| Token | Wartość | Rola |
| --- | --- | --- |
| `graphite` | `#2D343A` | tło logo, znaku i ikon |
| `yellow` | `#FFD400` | obramowanie laptopa, podpis „Serwis laptopów” |
| `light` | `#F5F6F5` | litery „SK” i napis „STUDIO-KOMP” |

Zmierzone kolory pliku źródłowego (`public/brand/palette.json`, sekcja `measured`):
tło `#2D343A`, żółty `#C1C154` (rdzeń kreski `#EFF06E`), jasny tekst `#F1F5F6`
(rdzeń `#FDFFFF`). JPEG 4:2:0 desaturuje cienkie linie, dlatego assety renderowane są
tokenami marki (`#FFD400`, `#F5F6F5`) — spójnie z `--yellow` w CSS.

### Pliki

| Plik | Wymiary | Zastosowanie |
| --- | --- | --- |
| `public/brand/studio-komp-logo.png` | 1494×504 | wersja główna 3×, tło `#2D343A` — reklamy, materiały, podgląd |
| `public/brand/studio-komp-logo@2x.png` | 996×336 | ekrany HiDPI, tło `#2D343A` |
| `public/brand/studio-komp-logo@1x.png` | 498×168 | wersja bazowa 1×, tło `#2D343A` |
| `public/brand/studio-komp-logo-transparent.png` | 1494×504 | 3× bez tła (RGBA), tylko na ciemne tła |
| `public/brand/studio-komp-logo-trim.png` | 1386×246 | wersja przycięta do widocznego tuszu (bez przezroczystego marginesu) - używana w nagłówku strony |
| `public/brand/studio-komp-mark-square.png` | 512×512 | kwadratowy znak SK na tle `#2D343A` (margines 8%), avatar/pieczątka |
| `public/brand/sk-mark.png` | 1024×1024 | master znaku SK do faviconów i materiałów |
| `public/brand/palette.json` | — | tokeny marki + realnie zmierzone kolory źródła |
| `public/icon-512.png` | 512×512 | ikona PWA/Android |
| `public/icon-192.png` | 192×192 | ikona PWA/Android |
| `public/apple-touch-icon.png` | 180×180 | iOS |
| `public/favicon.ico` | 16/32/48 | favicon klasyczny (z znaku SK, PNG w ICO) |
| `public/favicon-16.png`, `public/favicon-32.png` | 16, 32 | favicony PNG (nowoczesne przeglądarki) |
| `public/favicon.svg` | 64 (PNG 64 w base64) | favicon wektorowy z osadzonym znakiem SK |

### Zasady użycia

- **Ciemne tła.** Wersja pozioma i `studio-komp-logo-transparent.png` są projektowane na
  tło graphitowe/czarne (`#2D343A`, `#1A1D1B`, `#0E100F`). Na jasnym tle używaj wersji
  z wbudowanym tłem `#2D343A`.
- **Nie rozciągaj.** Proporcje 498:168 (≈ 2,964:1). Skaluj tylko proporcjonalnie; nie obracaj,
  nie dodawaj cieni, gradientów ani obrysu.
- **Minimalna szerokość:** 120 px (ekran), 30 mm (print). Poniżej — użyj samego znaku
  (`studio-komp-mark-square.png`).
- **Pole ochronne:** wysokość znaku (wersja pozioma) z każdej strony.
- **Nagłówek** używa przyciętej wersji (`studio-komp-logo-trim.png`): 38 px wysokości
  na desktopie, 30 px na telefonie, zawsze z proporcji 1386:246. Dzięki przycięciu
  napis jest czytelny bez zwiększania belki.
- **Favicony i ikony** generowane są wyłącznie ze znaku SK (przez `scripts/favicon.mjs`,
  który wykrywa granicę znaku i napisu analizą pikseli) - nie kadruj ich z pełnego
  logo, bo napis „STUDIO-KOMP” przestaje być czytelny. Linki w HTML mają `?v=2`
  (cache-busting); po podmianie ikon podnieś wersję.
- Kształt liter jest wierną kopią pliku źródłowego: nie przerysowuj napisów, nie podmieniaj
  fontu i nie „poprawiaj” proporcji.
- Po zmianie pliku źródłowego wygeneruj assety ponownie:
  `python assets-src/_logo-work.py`.
- Stare pliki `public/brand/studio-komp-logo*.svg`, `studio-komp-mark*.svg` i ich warianty
  PNG zawierają poprzedni monogram „S” i są przestarzałe — usuń je po aktualizacji
  referencji w `src/`.

