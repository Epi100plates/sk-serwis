# Audyt, strategia i decyzje projektowe — Studio-Komp

Dokument zbiera ustalenia z etapu 1 i 2: audyt obecnej strony, analizę konkurencji, decyzje technologiczne i plan nowej witryny.

## 1. Audyt obecnej strony (studio-komp.pl)

### Co działa

- Domena i hosting działają (LiteSpeed, HTTPS, poprawny certyfikat).
- Firma ma mocny, weryfikowalny atut: ponad 550 opinii Google i działalność od 2013 roku.
- Strona zawiera prawdziwe ceny części usług (cennik), które przenosimy do nowej wersji.
- Istnieją adresy, które mogą mieć wartość SEO: `/o-firmie.html`, `/cennik.html`, `/oferta/`, `/galeria.html`, `/kontakt.html`, `/aktualnosci/`, `/opinie`, `/mapa`, `/googleads`.

### Główne problemy

| Obszar | Problem | Konsekwencja |
| --- | --- | --- |
| Treść | Strona to wizytówka: kilka zdań o firmie, lista usług bez opisów | Brak odpowiedzi na pytania klientów, niska konwersja |
| SEO | Brak dedykowanych podstron usług, brak FAQ, brak schema.org | Brak widoczności na frazy typu „czyszczenie laptopa Wrocław” |
| SEO techniczne | Brak `robots.txt` (404), sitemap bez priorytetów, brak canonical | Słabsze indeksowanie, duplikaty |
| UX | Szablon z 2016 roku, brak mobile-first, mikre zdjęcia, cookie banner z 2016 | Wysoka porzucalność na telefonie |
| CRO | Brak stałego CTA na mobile, brak wyróżnionego telefonu, brak sekcji zaufania | Mniej telefonów |
| Wydajność | Nieoptymalne obrazy (JPG 1 MB), brak nowoczesnych formatów, brak cache | Wolne ładowanie, gorsze Ads |
| Analityka | Brak GA4/GTM, brak sledzenia kliknięć w telefon | Brak danych o źródłach konwersji |
| CMS | Strona na starym szablonie PHP, edycja wymaga programisty | Trudność w aktualizacji cen i treści |

### Obecne adresy URL (do przekierowania)

```
/                                  → /
/o-firmie.html                     → /o-serwisie/
/oferta/                           → /uslugi/
/oferta/czyszczenie-i-konserwacja-ukladu-chlodzenia.html → /czyszczenie-laptopa-wroclaw/
/cennik.html                       → /cennik/
/galeria.html                      → /realizacje/
/kontakt.html                      → /kontakt/
/aktualnosci/                      → /poradnik/
/aktualnosci/dlaczego-warto-nas-wybrac.html → /o-serwisie/
/aktualnosci/zalany-laptop-…       → /poradnik/zalany-laptop-czego-nie-robic/
/aktualnosci/…laptop-sie-grzeje…   → /poradnik/laptop-sie-przegrzewa-przyczyny/
/opinie                            → /opinie/
/googleads                        → /lp/naprawa-laptopow/
/mapa                             → /kontakt/
/aktualnosci/strona-w-przebudowie.html → /poradnik/
```

Pełna mapa znajduje się w `public/_redirects` (Netlify/Cloudflare) i `public/.htaccess` (obecny hosting LiteSpeed).

## 2. Analiza konkurencji

### naprawlapka.pl (główna inspiracja strukturalna)

**Mocne strony:**

- Bardzo rozbudowane podstrony usług z listą czynności i opisem „co obejmuje usługa”.
- Sekcja zaufania: 12 lat, 580 opinii, 10 000+ napraw, gwarancja.
- Bezpłatna diagnoza wyeksponowana jako osobna usługa.
- Rozbudowane menu usług (płyty główne, zalania, matryce, MacBook, Surface).
- Wielokanałowy kontakt: formularz, naprawa wysyłkowa, trzy punkty.
- Lokalne SEO: adresy, NIP, godziny, linki do miast.

**Słabe strony (przestrzeń dla Studio-Komp):**

- Dużo tekstu i elementów, strona jest gęsta i przytłacza na telefonie.
- Ciężka wizualnie, brak spójnej identyfikacji premium.
- Deklaracja „do 5 dni” na diagnozę — Studio-Komp może wygrać komunikacją „z dnia na dzień”.
- Skomplikowany proces (formularz, wysyłka, punkty) — Studio-Komp ma przewagę „przyjdź bez umawiania”.

### techwroclaw.pl

- Silny blog i poradniki z cenami — dobra praktyka contentowa.
- Konkretne widełki cenowe w treści (np. czyszczenie 80–150 zł).
- Wniosek: artykuły poradnikowe z konkretnymi widełkami przyciągają ruch; budujemy blog z linkowaniem do usług.

### olimp.wroclaw.pl, aviary.pl, katalogi firmowe

- Występują w listingach lokalnych; część ma dużo opinii, ale słabszą stronę.
- Wniosek: lokalne SEO (NAP, kategorie, mapy) jest ważne, ale decyduje jakość strony docelowej.

### Wnioski dla Studio-Komp

1. **Szybkość jako główny wyróżnik.** „99% napraw z dnia na dzień” + „ekspres tego samego dnia” to najmocniejsze argumenty, których konkurencja nie komunikuje tak wprost.
2. **Bez umawiania** — przewaga procesowa, którą trzeba pokazać na każdym ekranie.
3. **Prawdziwe opinie** — 550+ opinii Google jest wiarygodniejsze niż deklaracje o gwarancji.
4. **Uczciwa diagnostyka** — jasne „0 zł przy naprawie, płatna bez naprawy” buduje zaufanie i odróżnia od „darmowej diagnozy” bez warunków.
5. **Minimalizm premium** — konkurencja jest gęsta i krzykliwa; spokojna, techniczna estetyka wyróżni markę.

## 3. Strategia nowej strony

### Technologia

- **Astro 5, statyczne generowanie (SSG)** — brak serwera aplikacji, brak bazy danych, szybkie ładowanie, niskie koszty hostingu, bezpieczeństwo (brak PHP/WordPressa).
- **Content collections + pliki Markdown/JSON** — treść oddzielona od kodu; edycja przez panel CMS albo bezpośrednio w plikach.
- **Decap CMS** (`/admin`) — lekki panel oparty na Git; alternatywnie edycja plików w VS Code lub GitHubie.
- **Zero zależności frontendowych** — własny CSS, brak frameworka JS, minimalny JavaScript (nawigacja, formularz, mapa, zgoda cookies).

### Architektura informacji

```
/                          strona główna (sprzedażowa)
/uslugi/                   hub wszystkich usług
/<usluga>/                 16 podstron SEO (m.in. /czyszczenie-laptopa-wroclaw/)
/cennik/                   pełny cennik
/opinie/                   opinie Google
/realizacje/               galeria przypadków + miejsce na wideo
/o-serwisie/               historia, zasady, dane firmy
/kontakt/                  kontakt, mapa, formularz
/poradnik/                 blog + 15 artykułów
/lp/<usluga>/              6 landing page pod Google Ads (noindex)
/polityka-prywatnosci/     dokument prawny
```

### CRO — zasady konwersji

1. Telefon widoczny w nagłówku, w hero, w sekcji ekspres, w FAQ, w stopce i w stałym pasku mobilnym.
2. Jedna dominująca akcja na ekranie: „Zadzwoń”, drugorzędna: „Sprawdź usługi”.
3. Sekcje zaufania wcześnie: opinie zaraz po hero.
4. Cennik i zasada diagnostyki podana explicite — usuwa główną obawę.
5. Mikro-konwersje: klik w telefon, klik w dojazd, formularz, klik w usługę.
6. Mobile-first: duże przyciski (min. 54 px), czytelne nagłówki, stały pasek „Zadzwoń | Dojazd”.

### SEO

- Jeden H1 na stronę, fraza główna w H1, title, pierwszym akapicie.
- Frazy docelowe: serwis laptopów Wrocław, naprawa laptopów Wrocław, czyszczenie laptopa Wrocław, przyspieszanie laptopa Wrocław, wymiana matrycy Wrocław, naprawa płyty głównej Wrocław, naprawa laptopa po zalaniu Wrocław, naprawa gniazda zasilania Wrocław, naprawa zawiasów.
- Linkowanie wewnętrzne: hub `/uslugi/` → podstrony → powiązane usługi; blog → usługi; podstrony → cennik/kontakt.
- Schema.org: LocalBusiness, WebSite, Service, FAQPage, BreadcrumbList, Article.
- Sitemap, robots.txt, canonical, Open Graph, geo meta.
- Przekierowania 301 ze starych adresów (patrz wyżej) — bez utraty ruchu.

### Google Ads

- 6 dedykowanych landing page (`/lp/...`) z jednym celem: telefon lub formularz.
- Brak pełnej nawigacji, mniej rozpraszaczy, mocny nagłówek dopasowany do frazy reklamowej.
- Eventy: `call_click`, `form_submit` (generate_lead), `directions_click`, `cta_click`.
- Zalecane: kampanie Search (frazy lokalne) + Performance Max z feedem usług; remarketing z GA4.

### Analityka

- GTM (preferowane) lub GA4 bezpośrednio; Consent Mode v2 domyślnie `denied`.
- Zdarzenia: `call_click`, `directions_click`, `cta_click`, `form_submit`, `generate_lead`.
- Konwersje w Google Ads: kliknięcie telefonu i wysłanie formularza.
- Search Console: weryfikacja DNS lub plikiem, monitorowanie 301 i błędów indeksowania.

## 4. Identyfikacja wizualna

Szczegóły w `docs/logo-i-design.md`. Skrót: biel + grafit + sygnałowy żółty (#FFD400), typografia Schibsted Grotesk + IBM Plex Mono, cienkie linie techniczne, jeden mocny obiekt na stronę („zgłoszenie serwisowe”), brak stockowych zdjęć i niebieskich gradientów.

## 5. Miary sukcesu (do monitorowania po wdrożeniu)

- Wzrost liczby kliknięć `tel:` o min. 30% w 3 miesiące.
- Wzrost konwersji formularza (jeśli włączony).
- Wzrost ruchu organicznego na frazy usługowe.
- Core Web Vitals: LCP < 2,5 s, CLS < 0,1, INP < 200 ms.
- Pozycje na 9 frazach priorytetowych w promieniu Wrocławia.
