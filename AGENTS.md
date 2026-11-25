# AGENTS.md — Studio-Komp (strona + panel CMS)

Dokument dla agentów AI, programistów i właściciela. Opisuje **rzeczywisty**
stan projektu, architekturę, decyzje i ograniczenia. Ostatnia aktualizacja:
**2026-11-25**.

---

## A. Informacje ogólne

| | |
|---|---|
| Firma | Studio-Komp — serwis laptopów i komputerów |
| Adres | Kotlarska 14, 50-150 Wrocław (Stare Miasto) |
| Telefon | 500 613 500 |
| Godziny | poniedziałek–piątek 7:00–15:00 |
| Działalność od | 2013 |
| Opinie | 550+ w Google, ocena ok. 4,9/5 (dane pobierane, patrz sekcja K) |
| Domena docelowa | https://studio-komp.pl (DNS i stara strona **nietknięte**) |
| Wersja testowa / wdrożona | https://sk.mati-it.pl (VPS + Cloudflare Tunnel) |
| Panel CMS | https://sk.mati-it.pl/panel/ |
| Cel biznesowy | pozyskiwanie klientów z Google i Google Ads (telefon, dojazd, formularz) |

Zasady nadrzędne projektu:

1. Strona publiczna pozostaje **statyczna** (Astro SSG) — brak bazy danych przy
   żądaniu użytkownika.
2. Treścią zarządza panel PHP + SQLite, ale **build wykonuje się lokalnie**
   na komputerze właściciela (VPS nie buduje strony).
3. Nie zmieniamy DNS `studio-komp.pl`, nie ruszamy starej strony produkcyjnej,
   nie modyfikujemy innych usług na VPS (file-uploader, filman, inne tunele).
4. Żadnych fikcyjnych danych: cen, opinii, realizacji, statystyk, integracji.

---

## B. Technologie

| Warstwa | Technologia |
|---|---|
| Frontend | Astro 5.18 (SSG), TypeScript, czysty CSS (tokeny w `src/styles/global.css`) |
| Fonty | `@fontsource-variable/schibsted-grotesk`, `@fontsource/ibm-plex-mono` (lokalnie, bez CDN) |
| Obrazy | SVG (ilustracje techniczne), PNG/WebP; konwersja WebP w panelu (GD) |
| Panel | PHP 8.3 (FPM), SQLite 3 (PDO), bez frameworka |
| Serwer | Ubuntu 24.04, nginx 1.24 (osobna instancja `nginx-sk.service`, port 127.0.0.1:8088), Cloudflare Tunnel |
| Publikacja | lokalny build (Windows, pnpm/Node 22) + scp/ssh → atomowa podmiana wersji na VPS |
| Wersjonowanie | Git — repozytorium `/srv/studio-komp` na VPS, lokalna kopia na komputerze |
| Analityka | własny endpoint pierwszostronny + opcjonalnie GTM/GA4/Ads/Clarity (Consent Mode v2) |

Brak: WordPressa, MODX, frameworków PHP, composerowych zależności, Node na VPS
do budowania.

---

## C. Struktura projektu

```
src/
  components/            komponenty UI (Header, Footer, Logo, MapCard, CMP, analityka,
                         CaseGrid, BeforeAfter, ServicesShowcase, DiagnosisNote, ...)
  components/templates/  szablony: ServiceTemplate, BrandTemplate, PageTemplate
  content/
    uslugi/              16 podstron usług (Markdown + frontmatter)
    blog/                15 opublikowanych + 27 szkiców artykułów (draft: true)
    marki/               10 podstron marek (ASUS, Lenovo, Apple, Dell, HP, Acer, MSI,
                         Samsung, Toshiba, Microsoft Surface)
    strony/              dowolne podstrony tworzone z panelu (na start brak)
  data/                  site.json, cennik.json, opinie.json, faq.json,
                         realizacje.json (generowany z panelu), landing.json, wideo.json
  layouts/               Base.astro, LandingLayout.astro
  lib/                   url.ts, schema.ts
  pages/                 trasy: /, /[slug]/ (usługi+marki+podstrony), /uslugi/,
                         /poradnik/, /cennik/, /opinie/, /realizacje/[slug]/,
                         /kontakt/, /o-serwisie/, /polityka-prywatnosci/,
                         /lp/[slug]/, /dziekujemy/, /404, robots.txt.ts
  styles/global.css      tokeny marki i komponenty bazowe
public/
  brand/                 logo (nowe, z pliku źródłowego) + paleta
  images/                OG image, ilustracje realizacji, uploads (media z panelu)
  favicon.*, icon-*.png, apple-touch-icon.png, site.webmanifest
  .htaccess              generowany z panelu (hosting klasyczny)
  _redirects             generowany z panelu (Netlify/Cloudflare Pages)
panel/                   panel CMS (PHP, serwowany z /srv/studio-komp/panel)
  bootstrap.php          konfiguracja, sesja, helpery, nagłówki bezpieczeństwa
  index.php              router (login, pulpit, sekcje)
  lib/                   db, auth (hash, CSRF, 2FA, limity), content (frontmatter),
                         publish (kolejka/wersje), media (upload+WebP), analytics,
                         redirects (walidacja+generowanie plików), reviews, realizacje
  pages/                 widoki: dashboard, analytics, content, editor, realizacje,
                         kategorie, media, redirects, reviews, settings, publish,
                         users, profile, audit, preview, help, login
  api/e.php              endpoint pomiaru (sendBeacon)
  bin/                   create-admin, setup-panel, export-content, reviews-refresh,
                         cleanup, self-check
  assets/                admin.css, admin.js
deploy/
  publish.ps1            build lokalny + wysyłka na VPS (kolejka z panelu)
  remote-release.sh      wydanie nowej wersji na VPS (atomowy symlink)
  remote-preview.sh      podgląd wersji roboczych (/podglad/)
  sk-switch-release.sh   rollback do wcześniejszej wersji
  setup-cms.sh           konfiguracja VPS (PHP-FPM, nginx, cron, konto admina)
  install-publisher-task.ps1  rejestracja zadania Windows co 5 min
  nginx/sk.mati-it.pl.conf    konfiguracja nginx-sk
scripts/                 QA: shot, measure, qa-panel, qa-panel-write, qa-events,
                         check-live, check-portable, relativize, pack-www
docs/                    dokumentacja wdrożeń, designu, QA; ten plik
```

---

## D. Frontend — co jest i jak działa

### Strona główna (`src/pages/index.astro`)

Kolejność sekcji: hero → pasek marek → opinie → (desktop) ekspres → usługi z
ilustracjami → (desktop) przewagi/proces → cennik + komunikat diagnostyki →
(desktop) realizacje → FAQ → kontakt z mapą.

- **Hero**: H1 „Twój laptop w dobrych rękach.”, lead, przyciski „Zadzwoń: 500 613 500”
  i „Sprawdź usługi”, pod nimi adres i godziny; 4 statystyki
  (99%, 550+, 2013, 0 zł*). Treści konfigurowalne w `site.home`.
- **Diagnoza 0 zł**: komunikat „Diagnoza 0 zł przy naprawie*” + pełne
  wyjaśnienie w `site.diagnosis`; powtarzany w hero (desktop), cenniku, FAQ,
  podstronach usług i markach. Nigdy samo „0 zł” bez gwiazdki i wyjaśnienia.
- **Usługi**: `ServicesShowcase` — lista z ilustracjami (desktop) / krótka lista
  (mobile). Każda pozycja prowadzi na podstronę usługi.
- **Marki**: `BrandStrip` — linki do 10 podstron marek.
- **Opinie**: `Reviews` — na desktopie 3 prawdziwe cytaty z Google i ocena;
  na mobile kompaktowy pasek (ocena + „Opinie Google”).
- **Mapa**: `MapCard` — Google Maps osadzone **automatycznie** (bez klucza API,
  `output=embed`), pinezka Kotlarska 14, przycisk „Wyznacz trasę” i link do
  wizytówki z dokładnym URL.
- **Mobile**: strona główna ma ok. **4,3 ekranu** (390×844) zamiast dawnych ~11.
  Sekcje ekspres/przewagi/proces/realizacje są ukryte na telefonie (desktop-only),
  cennik pokazuje 3 pozycje, FAQ 3 pytania, stopka i opinie są skrócone.
  Hamulec: nie zwiększać ponownie liczby sekcji na mobile bez pomiaru
  `pnpm measure`.
- **Liczniki/animacje**: brak ciężkich bibliotek; jedyne JS to menu, CMP,
  analityka, mapa, slider przed/po, filtry realizacji, lightbox, formularz.

### Nagłówek i stopka

- Górna belka ma tło **grafitu `#2d343a`** (kolor z tła logo), jasny tekst i
  żółty akcent. Menu: Usługi | Cennik | Opinie | Poradnik | Realizacje | Kontakt,
  logo po lewej, telefon po prawej. Na mobile: burger + numer w pasku dolnym.
- Stopka: grafit, logo, opis, telefon, kolumny Usługi/Informacje/Marki/Kontakt,
  „Ustawienia cookies”, polityka prywatności.

### Logo i kolory

- Źródło: `assets-src/logo-source.jpg` (498×168). Tło **#2D343A**, żółty
  **#FFD400**, jasny tekst #F5F6F5. Assety: `public/brand/studio-komp-logo*.png`,
  wersja transparentna na ciemne tła, `studio-komp-mark-square.png`, favicony.
- Tokeny: `--graphite: #2d343a`, `--yellow: #ffd400`, `--ink: #242a30`.
  Nie używać czystej czerni jako głównego ciemnego koloru.

### Realizacje

- Dane: `src/data/realizacje.json` (generowany z panelu, źródło prawdy = SQLite).
- Lista `/realizacje/` z filtrami kategorii (JS), podstrony
  `/realizacje/<slug>/` z opisem problemu, diagnozy, naprawy, wyniku, galerią
  i opcjonalnym sliderem **przed/po** (`BeforeAfter.astro`).
- Kategorie: Chłodzenie, Wydajność, Matryce, Gniazda, Obudowy, Zalania,
  Płyty główne, Inne — edytowalne w panelu.

### Marki

- 10 podstron `/serwis-<marka>-wroclaw/` z unikalnym H1, meta, wstępem,
  typowymi problemami, naprawami, modelami, FAQ, linkami do usług i CTA.
- Oznaczone w panelu `review: pending` (do zatwierdzenia przez właściciela),
  ale opublikowane. Nie są autoryzowanym serwisem producentów — treści tego
  nie sugerują.

### Poradnik

- 15 artykułów publicznych + 27 szkiców markowych (`draft: true`). Szkice nie
  trafiają na produkcję; są widoczne w podglądzie `/podglad/`.

---

## E. Backend i publikacja

### Architektura publikacji (ważne!)

```
Panel (VPS, PHP)  --zapis-->  /srv/studio-komp (git, treści)
      |                            ^
      |  żądanie publikacji        | git push/pull
      v                            |
  kolejka (plik + DB)         Komputer właściciela (Windows)
      |                            |
      |  SSH (root)                |  pnpm build (Astro, lokalnie)
      v                            v
  remote-release.sh  <---scp---  deploy/publish.ps1
      |  tworzy /srv/studio-komp-releases/<timestamp>
      v  atomowo podmienia symlink /var/www/sk.mati-it.pl
  nginx-sk (127.0.0.1:8088) <--- Cloudflare Tunnel --- https://sk.mati-it.pl
```

Dlaczego tak: VPS ma mało RAM (1,2 GB) i nie buduje Astro. Dzięki temu VPS
tylko serwuje pliki i uruchamia panel, a strona pozostaje szybka.

### Kolejka publikacji

- Panel → „Poproś o publikację” → wiersz w `publish_requests` + plik
  `/srv/studio-komp-cms/data/publish-request.json`.
- `deploy/publish.ps1` (zadanie Windows „Studio-Komp Publish” co 5 min, albo
  ręcznie `pnpm publish:remote`) czyta kolejkę, robi commit lokalnych zmian,
  pobiera zmiany z panelu (`git merge origin/main`), wysyła repo na VPS,
  buduje i wgrywa paczkę.
- `remote-release.sh` rozpakowuje paczkę do nowego katalogu wersji, ustawia
  uprawnienia, kopiuje przekierowania i konfigurację nginx, testuje nginx,
  podmienia symlink i zapisuje `publish-state.json`.
- Rollback: panel → Publikacja → „Przywróć” (sudo `sk-switch-release`), bez
  przebudowy.

### Front publiczny

- Statyczne HTML w wersjach: `/srv/studio-komp-releases/<YYYYMMDD-HHMMSS>`.
- `/var/www/sk.mati-it.pl` to symlink na aktualną wersję.
- nginx: `try_files`, 404, cache assetów, gzip, nagłówki bezpieczeństwa.
- Przekierowania 301/302: `/etc/nginx/sk-redirects.conf`, generowane z panelu
  (także `public/_redirects` i `public/.htaccess` dla hostingów klasycznych).

### Build lokalny

```powershell
pnpm build                 # produkcyjnie (SITE_URL ustawia publisher)
pnpm publish:remote        # publikacja z kolejki panelu
pnpm publish:now           # publikacja natychmiast (bez kolejki)
pnpm publish:preview       # build podglądu z draftami → /podglad/
pnpm publisher:install     # rejestracja zadania Windows co 5 min
```

Zmienne: `SITE_URL` (canonical/sitemap), `BASE_PATH` (dla paczki na hosting
w podkatalogu), `PREVIEW=1` (build z wersjami roboczymi do `dist-preview/`).

### Panel — uruchomienie i konfiguracja

- Kod: `/srv/studio-komp/panel`, serwowany przez PHP-FPM (pool `skcms`) pod
  `/panel/`. Konfiguracja: `/srv/studio-komp-cms/config.php` (poza repo,
  wzór: `panel/config.example.php`).
- Baza: `/srv/studio-komp-cms/data/panel.sqlite` (WAL).
- Uprawnienia: repo i dane należą do użytkownika `skcms`; nginx czyta jako
  `www-data`; panel zapisuje przez PHP-FPM jako `skcms`.
- Setup: `bash /srv/studio-komp/deploy/setup-cms.sh` (idempotentny).
- Cron: `/etc/cron.d/studio-komp` — opinie co 6 h, porządki codziennie 3:23.

---

## F. CMS — panel `/panel`

### Logowanie i bezpieczeństwo

- Hasła: `password_hash` (bcrypt/argon), brak domyślnego hasła — konto tworzy
  `panel/bin/create-admin.php` (losowe hasło w
  `/srv/studio-komp-cms/data/initial-credentials.txt`, plik 0600).
- Limit prób: 5 nieudanych / 15 min na login+IP-hash, potem blokada.
- Sesja: cookie HttpOnly, Secure, SameSite=Strict, ścieżka `/panel`,
  regeneracja po logowaniu i co 15 min, wygaśnięcie po 30 min bezczynności.
- CSRF: token w sesji, wymagany na każdym POST (`csrf_check()`).
- 2FA (TOTP): opcjonalne, włączane w „Mój profil”; reset przez administratora.
- Role: `admin` (wszystko) i `editor` (treści, media, realizacje, publikacja).
- Dziennik zdarzeń (audit log) widoczny w panelu.

### Moduły

| Moduł | Funkcje |
|---|---|
| Pulpit | KPI, top podstrony, quick actions, status publikacji, opinie, zawartość, kondycja |
| Analityka | zakresy dat, porównanie okresów, wykresy SVG, urządzenia, podstrony, źródła/UTM, zdarzenia, szczegóły podstrony |
| Treści | listy i edytor dla usług, poradnika, marek, podstron: frontmatter + Markdown, slug, draft, historia wersji, przywracanie |
| Realizacje | CRUD, kategorie, galerie, slider przed/po, eksport JSON, podstrony |
| Media | upload (MIME + rozmiar + SVG sanityzacja), miniatury WebP, ALT/tytuł/opis/kategoria, wyszukiwanie, usuwanie |
| Przekierowania | 301/302, walidacja protokołów, wykrywanie pętli, generowanie plików nginx/_redirects/.htaccess |
| Opinie | cache oceny/liczby, odświeżanie Places API, przenoszenie do `site.json`, linki |
| Ustawienia | kontakt, strona główna, menu/stopka, opinie, cennik, FAQ, analityka, formularz, SEO |
| Publikacja | kolejka, status, log, lista wersji, rollback |
| Podgląd | kolejka builda podglądu, URL `/podglad/` (basic auth) |
| Użytkownicy | dodawanie kont, role, reset hasła/2FA |
| Pomoc | instrukcja dla właściciela |

### Format treści (Markdown + YAML)

Pola i typy: `panel/lib/content.php` → `content_types()`. Parser YAML obsługuje
podzbiór zgodny z frontmatterem projektu (skalary, listy, listy map, bool,
liczby, daty). **Nie** obsługuje wieloliniowych bloków `|`/`>` — nie używać ich
w frontmatterze. Test round-trip: `php panel/bin/self-check.php`.

Historia wersji: każdy zapis pliku trafia do `content_versions` (do 40 wersji
na plik) i do gita (`Panel: ...`).

### Media i upload

- Do 12 MB, dozwolone MIME: JPEG/PNG/WebP/AVIF/GIF/SVG/MP4/WebM.
- SVG: odrzucane skrypty/`javascript:`.
- Miniatury WebP (max 640 px) przez GD; nazwy plików losowe z datą.
- Pliki w `public/images/uploads/` (repo → build → produkcja).
- Nginx blokuje wykonywanie PHP w uploadach.

---

## G. Analityka

### Źródła danych

1. **Własny pomiar pierwszo-stronny** (zawsze aktywny): endpoint `POST /api/e`,
   SQLite, bez zewnętrznych skryptów.
2. **GA4 / GTM / Google Ads** — opcjonalne, tylko po podaniu ID w Ustawieniach
   (obecnie **brak ID** — dlatego panel pokazuje „Integracje niepodłączone”).
3. **Microsoft Clarity** — opcjonalny, po zgodzie analitycznej i podaniu ID.
4. **Google Ads API** — nie zaimplementowane (tylko architektura i miejsce na
   konfigurację; wymaga OAuth i zatwierdzenia dewelopera).

### Definicje wskaźników

- **Odsłona** — wyświetlenie podstrony (pageview), mierzone po zgodzie analitycznej.
- **Użytkownik** — unikalny `visitor_id` (pierwszo-stronne cookie, 12 mies.).
- **Sesja** — `session_id` (cookie sesyjne).
- **Kliknięcie telefonu (`phone_click`)** — kliknięcie linku `tel:`. **Nie**
  oznacza zakończonej rozmowy.
- **Kliknięcie dojazdu (`directions_click`)** — kliknięcie linku do mapy/trasy.
- **`map_click`** — kliknięcie w link mapy/wizytówki.
- **Wysłany formularz (`contact_form_submit`)** — zdarzenie submit (próba).
- **Konwersja (`generate_lead`)** — potwierdzone wysłanie formularza (sukces
  Web3Forms) — to nie to samo co kliknięcie telefonu.
- **CTA_click / service_click / price_click / review_click / realization_click /
  article_click** — kliknięcia w odpowiednie elementy; mierzone z atrybutów
  `data-track` i analizy href.
- **`gallery_open`, `before_after_interaction`, `video_play`** — interakcje
  galerii, slidera i wideo.

### Zdarzenia i model danych

Tabele: `pageviews` (odsłony z device/source/UTM), `events` (nazwa, strona,
element, źródło, device, meta). Indeksy po dacie, nazwie i stronie. Retencja
400 dni (`analytics_retention_days`). Brak pełnych IP (hash z solą dzienną,
tylko limit tempa). Endpoint egzekwuje: zgodę (`consent:true`), Origin, limity,
filtr botów, walidację nazw zdarzeń i rozmiarów.

### Dashboard

Zakładki: Przegląd, Podstrony, Źródła, Zdarzenia, Urządzenia + szczegóły
podstrony. Wykresy inline SVG bez bibliotek. Filtr dat: dziś/wczoraj/7/30/90/365/
własny oraz porównanie z poprzednim okresem. Panel informuje, że statystyki
obejmują tylko użytkowników z zgodą analityczną i że GA4/Ads nie są podłączone.

---

## H. SEO

- **URL-e** (bez zmian dla istniejących treści): `/<usluga>/`, `/poradnik/<slug>/`,
  `/cennik/`, `/opinie/`, `/realizacje/`, `/realizacje/<slug>/`, `/kontakt/`,
  `/o-serwisie/`, `/uslugi/`, `/lp/<slug>/` (noindex), `/serwis-<marka>-wroclaw/`.
- **Canonical**: zawsze `https://sk.mati-it.pl/...` na wersji testowej
  (`SITE_URL`), docelowo studio-komp.pl — bez zmian DNS.
- **Sitemap**: `@astrojs/sitemap`, wyklucza LP, politykę, OG, 404, dziekujemy.
  Marki i realizacje są w sitemapie. `sitemap-index.xml` + `sitemap-0.xml`.
- **Robots**: `src/pages/robots.txt.ts` (host + sitemap).
- **Dane strukturalne**: LocalBusiness/ProfessionalService, WebSite, Service,
  FAQPage, BreadcrumbList, Article, Brand. Godziny, geo, aggregateRating z site.json.
- **Przekierowania**: stare adresy (o-firmie.html, cennik.html, galeria.html,
  aktualnosci, googleads, mapa, oferta/…) → 301 na właściwe podstrony.
- **Meta**: title/description per podstrona; OG/Twitter; geo meta; brak duplikacji.
- **Zdjęcia**: ALT wymagany w panelu; ilustracje SVG; upload konwertuje miniatury.

---

## I. Bezpieczeństwo

Zastosowane mechanizmy:

| Obszar | Rozwiązanie |
|---|---|
| Uwierzytelnianie | password_hash, limity prób, blokada, 2FA TOTP, regeneracja sesji |
| Autoryzacja | role admin/editor, sprawdzanie uprawnień w routerze |
| Sesje | HttpOnly/Secure/SameSite=Strict, idle timeout, rotacja ID, save_path poza webrootem |
| CSRF | token na wszystkich formularzach panelu |
| XSS | `htmlspecialchars` (helper `e()`), brak wklejania dowolnego HTML do treści, CSP panelu |
| SQL Injection | wyłącznie prepared statements (PDO) |
| Upload | MIME z finfo, whitelist, limity, sanityzacja SVG, losowe nazwy, blokada PHP w uploadach |
| Path traversal | walidacja slugów (`^[a-z0-9-]+$`), ścieżki budowane z mapy katalogów |
| Open redirect | walidacja protokołów i ścieżek, wykrywanie pętli, brak parametru docelowego z URL |
| Sekrety | konfiguracja poza repo (`/srv/studio-komp-cms/config.php`), `.gitignore` |
| Nagłówki | X-Content-Type-Options, Referrer-Policy, Permissions-Policy, X-Frame-Options, CSP (panel) |
| Rate limiting | analityka (limit/min), logowanie (5/15 min), upload (rozmiar) |
| Logi | PHP error log, panel.log, audit_log, log publikacji |
| Backup/rollback | wersje na VPS (KEEP 6), git, `content_versions`, SQLite (kopia do wykonania cronem) |

Czego **nie ma** (świadomie): WAF aplikacyjnego, pełnego pentestu, szyfrowania
bazy, automatycznych kopii SQLite do zewnętrznego storage. Rekomendacje w
raporcie końcowym.

---

## J. Cookies i prywatność

- CMP: kategorie niezbędne / analityczne / marketingowe / funkcjonalne;
  opcjonalne domyślnie **wyłączone**; „Akceptuj wszystkie” i „Tylko niezbędne”
  jednakowo dostępne; ustawienia i zmiana decyzji w stopce.
- Consent Mode v2: `default denied`, `update` po decyzji; GTM/GA4/Clarity
  ładowane warunkowo; własny pomiar tylko po zgodzie analitycznej.
- Polityka prywatności zaktualizowana 2026-11-25: tabela cookies, dostawcy,
  retencja, informacja o mapie Google i własnym pomiarze.
- **Uwaga prawna:** mapa Google ładuje się automatycznie (decyzja właściciela).
  Osadzony iframe może ustawiać cookies Google przed decyzją użytkownika.
  Jeśli właściciel chce pełnej zgodności, należy włączyć wariant „mapa po
  zgodzie funkcjonalnej” (kod gotowy do modyfikacji w `MapCard.astro`).
- Formularz: dane trafiają do Web3Forms (lub Netlify), nie do bazy analityki.
- Analityka własna nie przechowuje treści formularzy, numerów telefonów ani
  pełnych IP.

---

## K. Integracje

| Integracja | Stan | Wymagania |
|---|---|---|
| Google Maps (iframe) | działa, bez klucza API | brak |
| Wizytówka/opinie (link) | działa — link do przeglądania opinii ustawiony w `site.json` | brak |
| Ocena/liczba opinii Google | ręczne dane + opcjonalny refresh Places API | klucz `google_places_api_key` w configu, włączone Places API |
| Google Analytics 4 / GTM | kod gotowy, **brak ID** | wpisać ID w panelu (Ustawienia → Analityka) |
| Google Ads (konwersje) | kod gotowy, **brak send_to** | wpisać `AW-.../label` |
| Microsoft Clarity | kod gotowy, **brak ID** | wpisać ID |
| Search Console | po stronie właściciela | weryfikacja domeny |
| Web3Forms | kod gotowy | klucz dostępu w Ustawieniach |
| SMS API | **tylko dokumentacja future goals** | konto SMSAPI/inne, decyzja właściciela |

### Opinie Google — decyzja

- **Places API (Place Details)** — zwraca ocenę i liczbę opinii; wymaga klucza
  API, ma limity i koszty; nie zwraca treści opinii ani linków do pojedynczych
  recenzji.
- **Business Profile API** — daje więcej danych (w tym opinie), ale wymaga
  weryfikacji firmy, OAuth, zatwierdzenia dostępu (allowlist) i jest bardziej
  złożone operacyjnie.
- Wybrano **Places API** + cache w SQLite + fallback na ostatnie dane.
  Pojedyncze opinie linkują do wizytówki (link ogólny) — bez fikcyjnych URL.
- Do uruchomienia: klucz API, cron `panel/bin/reviews-refresh.php` (co 6 h),
  zatwierdzenie przeniesienia danych do `site.json` w panelu.

---

## L. Future Goals — status napraw i SMS

Cel: klient sprawdza postęp naprawy przez unikalny, bezpieczny link, a serwis
wysyła statusy SMS-em. **Nie zaimplementowano** (brak decyzji właściciela,
kont API). Poniżej gotowy projekt.

### L.1 Model bazy (MySQL/MariaDB lub SQLite — do decyzji)

```sql
repairs (
  id            INTEGER PK,
  token_hash    CHAR(64) UNIQUE,      -- SHA-256 tokenu, nie sam token
  status        VARCHAR(32),          -- accepted|diagnosis|awaiting_approval|awaiting_parts|in_repair|testing|ready|picked_up
  device_brand  VARCHAR(64),
  device_model  VARCHAR(128),
  customer_phone_encrypted BLOB,      -- opcjonalnie, jeśli SMS
  customer_name VARCHAR(120),
  public_note   TEXT,                 -- widoczne dla klienta, bez danych osobowych
  internal_note TEXT,
  created_at, updated_at, ready_at,
  status_token_expires_at,
  sms_consent   TINYINT
);

repair_status_history (
  id, repair_id FK, status, note, created_by, created_at
);

sms_log (
  id, repair_id, phone_hash, template, status, provider_message_id, error, sent_at
);
```

Dane wrażliwe: telefon klienta nie może być widoczny na stronie statusu;
w panelu — dostęp tylko dla zalogowanych pracowników; szyfrowanie kolumny lub
przechowywanie w oddzielnej tabeli z ograniczonym dostępem.

### L.2 Token i link

- Token: 32 bajty losowe (`random_bytes(32)`), base64url; w bazie wyłącznie
  **hash** (SHA-256). Link: `https://studio-komp.pl/status/<token>/`.
- Ważność: do 30 dni po odbiorze, unieważnienie ręczne; jednorazowe ponowienie
  generuje nowy token.
- Strona statusu: tylko status, marka/model, data przyjęcia, publiczna notatka,
  numer zgłoszenia skrócony; **bez** nazwiska, telefonu, adresu, ceny.
- Rate limiting: 10 żądań/min/IP, brak indeksowania (noindex), brak cache.

### L.3 API (przyszłe endpointy panelu/API)

```
POST /api/repairs              (auth pracownika) — utworzenie naprawy
PATCH /api/repairs/:id/status  (auth) — zmiana statusu + notatka + SMS
POST /api/repairs/:id/token    (auth) — wygenerowanie/odświeżenie linku
GET  /status/:token            (publiczne, GET) — dane statusu (JSON/HTML)
POST /api/sms/webhook          (podpisany) — raport doręczenia
```

Autoryzacja: sesja panelu + CSRF; webhook podpisany sekretem; rate limiting.

### L.4 SMS API — porównanie (do weryfikacji przed wdrożeniem)

| Dostawca | Cena SMS (orientacyjnie) | Nadawca | API | Uwagi |
|---|---|---|---|---|
| SMSAPI (PL) | ok. 0,05–0,09 zł netto / SMS | własny identyfikator po weryfikacji | REST, dobra dokumentacja | popularny, wsparcie 24/7, raporty doręczeń |
| SMS Planet | podobnie (zależne od pakietu) | własny nadawca | REST | polski dostawca, faktury VAT |
| Twilio | wyższe (ok. 0,04–0,08 USD + opłaty PL) | alfanumeryczny | REST, globalny | dobre API, wyższe koszty i konfiguracja |
| Vonage/Nexmo | wyższe niż PL | alfanumeryczny | REST | globalny |

Ceny i limity **zmieniają się** — przed wdrożeniem sprawdzić aktualny cennik i
regulamin. Rekomendacja: SMSAPI lub SMS Planet (polskie, faktury, nadawca
alfanumeryczny, raporty doręczeń).

### L.5 Szablony wiadomości

1. „Przyjęliśmy Twój sprzęt. Status: <link>”
2. „Diagnoza zakończona — czekamy na Twoją decyzję: <link>”
3. „Oczekujemy na części. O nowościach poinformujemy: <link>”
4. „Naprawa rozpoczęta: <link>”
5. „Naprawa zakończona, trwają testy: <link>”
6. „Sprzęt gotowy do odbioru: <link>”

Zgodność: zgoda na komunikację SMS, informacja o kosztach, możliwość rezygnacji.
Limit: max 6 SMS-ów na naprawę; log wysyłek; brak wysyłki bez zgody.

### L.6 Koszty i ryzyka

- Koszt SMS: ok. 0,30–0,60 zł / naprawa przy 6 wiadomościach (zależnie od cennika).
- Koszt Places API: wg cennika Google (kilka tysięcy zapytań/mies. może być
  darmowe w ramach kredytu; sprawdzić aktualne limity).
- Ryzyka: wyciek tokenów (dlatego hash + unieważnianie), błędny numer klienta,
  koszty SMS przy błędnych danych, konieczność RODO (dane w SMS-ach minimalne).

---

## M. Historia zmian

| Data | Zmiana |
|---|---|
| 2026-11-24 | Stan wyjściowy: statyczna strona Astro (50 podstron), wdrożenie na VPS + tunel, panel Decap `/edycja` (prototyp). |
| 2026-11-25 | Nowe logo (grafite #2D343A, żółty #FFD400), górna belka i stopka w graficie, favicony, paleta. |
| 2026-11-25 | UX mobile: strona główna skrócona do ~4,3 ekranu, sekcje desktop-only, kompaktowe opinie/stopka/FAQ/cennik. |
| 2026-11-25 | Hero i statystyki wg briefu („Twój laptop w dobrych rękach.”, 99%, 550+, 2013, 0 zł*), komunikat diagnostyki wszędzie. |
| 2026-11-25 | Mapa Google ładowana automatycznie, dokładna lokalizacja i link do wizytówki, wszystkie CTA dojazdu na ten sam URL. |
| 2026-11-25 | 10 podstron marek + 27 artykułów markowych (szkice) + pasek marek z linkami. |
| 2026-11-25 | Realizacje: kategorie, galerie, podstrony, slider przed/po, dane z panelu. |
| 2026-11-25 | Panel CMS `/panel` (PHP+SQLite): logowanie, 2FA, CSRF, limity, treści, media, realizacje, przekierowania, ustawienia, użytkownicy, audyt, podgląd, publikacja/rollback. |
| 2026-11-25 | Analityka pierwszostronna: pageview + 14 zdarzeń, dashboard z wykresami, urządzenia/źródła/UTM, retencja 400 dni. |
| 2026-11-25 | CMP z kategoriami + Consent Mode v2, GA4/GTM/Ads/Clarity warunkowo, polityka prywatności zaktualizowana. |
| 2026-11-25 | Publikacja: build lokalny + atomowe wersje na VPS, kolejka z panelu, zadanie Windows co 5 min, rollback. |
| 2026-11-25 | Wdrożenie na https://sk.mati-it.pl (release 20261125-112433+), panel aktywny, Decap wyłączony. |

---

## N. Uruchomienie lokalne i testy

```powershell
pnpm install
pnpm dev                     # http://127.0.0.1:4321
pnpm build                   # dist/
pnpm preview                 # podgląd builda
pnpm measure                 # pomiar mobile (liczba ekranów)
pnpm qa                      # axe/CLS/LCP (jeśli skonfigurowane)
```

Testy panelu (na żywo, wymagają konta):

```powershell
$env:SITE_URL='https://sk.mati-it.pl'; $env:PANEL_USER='...'; $env:PANEL_PASS='...'
node scripts/qa-panel.mjs        # wszystkie widoki + mobile + błędy JS
node scripts/qa-panel-write.mjs  # zapisy: strona, przekierowanie, media, ustawienia
node scripts/qa-events.mjs       # CMP, mapa, zdarzenia, brak tagów bez zgody
node scripts/check-live.mjs      # crawl live (sitemap, 301, 404)
```

Testy zawartości i parsera na serwerze:

```bash
runuser -u skcms -- php /srv/studio-komp/panel/bin/self-check.php
```

## O. Znane ograniczenia i rzeczy do zrobienia przez właściciela

1. **ID analityki** — GA4/GTM/Ads/Clarity puste; wpisać w panelu.
2. **Klucz Web3Forms** — puste; bez niego formularz nie wyśle zgłoszenia.
3. **Klucz Google Places API** — opcjonalny; bez niego ocena/liczba opinii ręczna.
4. **Ceny „… zł”** w cenniku — uzupełnić po ustaleniu.
5. **Zdjęcia realizacji/usług** — obecnie ilustracje techniczne; wgrać prawdziwe
   zdjęcia przez panel Media i przypisać.
6. **Artykuły markowe** — 27 szkiców do przeglądu i publikacji w panelu.
7. **Mapa a zgody** — patrz sekcja J (decyzja właściciela).
8. **Kopie bazy panelu** — dodać backup SQLite (np. cron + kopia poza VPS).
9. **2FA** — zalecane włączenie po pierwszym logowaniu.
10. **SMTP/e-mail** — brak; formularz korzysta z Web3Forms.
