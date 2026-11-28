# AGENTS.md — Studio-Komp (strona + panel CMS)

Dokument dla agentów AI, programistów i właściciela. Opisuje **rzeczywisty**
stan projektu, architekturę, decyzje i ograniczenia. Ostatnia aktualizacja:
**2026-11-28**.

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
    uslugi/              18 podstron usług (Markdown + frontmatter)
    blog/                17 opublikowanych + 25 szkiców artykułów (draft: true)
    marki/               9 podstron marek (ASUS, Lenovo, Apple, Dell, HP, Acer, MSI,
                         Samsung, Toshiba)
    strony/              dowolne podstrony tworzone z panelu (na start brak)
  data/                  site.json, cennik.json, opinie.json, faq.json,
                         realizacje.json (generowany z panelu), landing.json, wideo.json
  layouts/               Base.astro, LandingLayout.astro
  lib/                   url.ts, schema.ts, prices.ts (jedno źródło cen)
  pages/                 trasy: /, /[slug]/ (usługi+marki+podstrony), /uslugi/,
                         /poradnik/, /cennik/, /opinie/, /realizacje/[slug]/,
                         /kontakt/, /o-serwisie/, /polityka-prywatnosci/,
                         /lp/[slug]/, /dziekujemy/, /404, robots.txt.ts
  styles/global.css      tokeny marki i komponenty bazowe
public/
  brand/                 logo (nowe, z pliku źródłowego) + paleta
  images/                OG image, ilustracje realizacji, uploads (media z panelu)
  tracking/              status.css + fonts/* - styl publicznej strony statusu naprawy
  favicon.*, icon-*.png, apple-touch-icon.png, site.webmanifest
  .htaccess              generowany z panelu (hosting klasyczny)
  _redirects             generowany z panelu (Netlify/Cloudflare Pages)
panel/                   panel CMS (PHP, serwowany z /srv/studio-komp/panel)
  bootstrap.php          konfiguracja, sesja, helpery, nagłówki bezpieczeństwa
  index.php              router (login, pulpit, sekcje)
  lib/                   db, auth (hash, CSRF, 2FA, limity), content (frontmatter),
                         publish (kolejka/wersje), media (upload+WebP), analytics,
                         redirects (walidacja+generowanie plików), reviews, realizacje,
                         tickets (zgłoszenia, etapy klienta, token śledzenia), sms
                         (SMSAPI/SerwerSMS), mailer (SMTP)
  pages/                 widoki: dashboard, analytics, content, editor, realizacje,
                         kategorie, media, redirects, reviews, zgloszenia, settings,
                         publish, users, profile, audit, preview, help, login
  views/                 layout.php, status.php (publiczna strona statusu naprawy)
  api/                   e.php (pomiar), form-token.php + form.php (formularz zgłoszeń),
                         status.php (GET /status/<token>/ - HTML i JSON)
  bin/                   create-admin, setup-panel, export-content, reviews-refresh,
                         cleanup, self-check, self-check-status, tickets-worker
                         (kolejka powiadomień)
  assets/                admin.css, admin.js
deploy/
  publish.ps1            build lokalny + wysyłka na VPS (kolejka z panelu)
  remote-release.sh      wydanie nowej wersji na VPS (atomowy symlink)
  remote-preview.sh      podgląd wersji roboczych (/podglad/)
  sk-switch-release.sh   rollback do wcześniejszej wersji
  setup-cms.sh           konfiguracja VPS (PHP-FPM, nginx, cron, konto admina)
  install-publisher-task.ps1  rejestracja zadania Windows co 5 min
  nginx/sk.mati-it.pl.conf    konfiguracja nginx-sk
scripts/                 QA: shot, measure, theme-qa, qa-panel, qa-panel-write,
                         qa-events, qa-inst2, check-live, check-portable, relativize,
                         pack-www, rehype-prices, prices-cache, check-prices, check-seo
docs/                    dokumentacja wdrożeń, designu, QA; ten plik
```

---

## D. Frontend — co jest i jak działa

### Strona główna (`src/pages/index.astro`)

Kolejność sekcji: hero → pasek marek → opinie → (desktop) ekspres → kafelki usług →
(desktop) przewagi/proces → cennik + komunikat diagnostyki →
(desktop) realizacje → FAQ → kontakt z mapą.

- **Hero**: H1 „Twój laptop w dobrych rękach.”, lead, przyciski „Zadzwoń: 500 613 500”
  i „Sprawdź usługi”, pod nimi adres i godziny; 4 statystyki
  (99%, 550+, 2013 / rok założenia, 0 zł*). Treści konfigurowalne w `site.home`.
- **Diagnoza 0 zł**: komunikat „Diagnoza 0 zł przy naprawie*” + pełne
  wyjaśnienie w `site.diagnosis`; powtarzany w hero (mobile), cenniku, FAQ,
  podstronach usług i markach. Nigdy samo „0 zł” bez gwiazdki i wyjaśnienia.
  Powtórzenia na stronie głównej ograniczono 2026-11-27 (usunięto zdublowaną
  notkę w hero desktop, wiersz o ekspresie w cenniku i drugi telefon w kontakcie).
- **Usługi**: `ServicesShowcase` — siatka prostokątnych kafelków: 3 kolumny
  (≥1024 px), 2 (641-1023 px), 2 lub 1 na telefonie (poniżej 380 px jedna).
  Kafelek domyślnie pokazuje zdjęcie i tytuł; po najechaniu (hover) lub
  kliknięciu/Enter rozwija się opis procesu i link do podstrony usługi.
  Na telefonie widocznych jest 6 kafelków + „Wszystkie usługi”.
  Usługi na kafelki wybiera się w panelu (pole `tile`, opis `tileText`).
- **Marki**: `BrandStrip` — linki do 9 podstron marek.
- **Opinie**: `Reviews` — na desktopie 3 prawdziwe cytaty z Google i ocena;
  na mobile kompaktowy pasek z oceną i **jedną prawdziwą opinią** (2026-11-27).
  Element mobilny jest ukryty na desktopie (poprawka `display`).
- **Mapa**: `MapCard` — Google Maps osadzone **automatycznie** (bez klucza API,
  `output=embed`), pinezka Kotlarska 14, przycisk „Wyznacz trasę” i link do
  wizytówki z dokładnym URL.
- **Mobile**: strona główna ma ok. **4,8 ekranu** (390×844), maksymalnie 5.
  Sekcje ekspres/przewagi/proces/realizacje są ukryte na telefonie (desktop-only),
  cennik pokazuje 3 pozycje, FAQ 3 pytania, stopka i opinie są skrócone,
  sekcja usług pokazuje 6 kafelków. Hamulec: nie zwiększać ponownie liczby
  sekcji na mobile bez pomiaru `pnpm measure`.
- **Liczniki/animacje**: brak ciężkich bibliotek; jedyne JS to menu, CMP,
  analityka, mapa, slider przed/po, filtry realizacji, lightbox, formularz.

### Nagłówek i stopka

- Górna belka ma tło **grafitu `#2d343a`** (kolor z tła logo), jasny tekst i
  żółty akcent. Menu: Usługi | Cennik | Opinie | Poradnik | Realizacje | Kontakt,
  logo po lewej, telefon po prawej. Na mobile: burger + numer w pasku dolnym.
- Stopka: grafit, logo, opis, telefon, kolumny Usługi/Informacje (z listą
  marek w dwóch kolumnach)/Kontakt, „Ustawienia cookies”, polityka prywatności.
  Na telefonie (≤899 px) sekcje są akordeonem: Usługi, Informacje i Marki
  domyślnie zwinięte, kolumna kontaktowa zawsze widoczna. Bez JS treść jest
  widoczna, a przyciski akordeonu ukryte (progressive enhancement).

### Logo i kolory

- Źródło: `assets-src/logo-source.jpg` (498×168). Tło **#2D343A**, żółty
  **#FFD400**, jasny tekst #F5F6F5. Assety: `public/brand/studio-komp-logo*.png`,
  wersja transparentna na ciemne tła, wersja przycięta do widocznego tuszu
  `studio-komp-logo-trim.png` (nagłówek), `studio-komp-mark-square.png`,
  favicony SK (`scripts/favicon.mjs` kadruje sam znak z logo).
- Nagłówek: logo przycięte, wysokość 38 px (telefon 30 px), proporcje z pliku
  (1386×246) - bez rozciągania. Stopka i LP używają wersji z marginesem.
- Favicony: `favicon.ico` (16/32/48), `favicon-16.png`, `favicon-32.png`,
  `favicon.svg`, `apple-touch-icon.png` (180), `icon-192.png`, `icon-512.png`,
  `brand/sk-mark.png` (1024). W HTML linki mają `?v=2` (cache-busting).
- Tokeny: `--graphite: #2d343a`, `--yellow: #ffd400`, `--ink: #242a30`.
  Nie używać czystej czerni jako głównego ciemnego koloru.

### Tryb ciemny

- Przełącznik w nagłówku (desktop i mobile) oraz na landingach `/lp/`. Zapis
  wyboru w `localStorage` (`sk-theme`), domyślnie obowiązuje preferencja
  systemowa; `ThemeInit.astro` ustawia `data-theme` na `<html>` przed
  pierwszym malowaniem (brak migotania), `ThemeToggle.astro` obsługuje klik,
  `aria-pressed`, kolor paska przeglądarki i zmianę preferencji systemowej.
- Paleta ciemna jest wzorowana na wizytówce serwisu (prawie czarny grafit,
  mikro-siatka, żółty akcent): `--paper #101417`, `--surface #151a1e`,
  `--surface-2 #1b2227`, `--graphite #1a2126`, `--header-bg #0c1012`,
  `--line #242c31`, `--line-strong #364048`, `--muted #a7aea9`,
  `--muted-2 #8d958f`, `--ink #f3f5f2`, `--on-dark-muted #b0b8b3`,
  `--dark-line #313a41`. Żółty `#ffd400` bez zmian.
- Tokeny o stałym znaczeniu (nie zmieniają się między motywami):
  `--on-yellow` (tekst/ikona na żółtym tle), `--on-light` (tekst na jasnej
  powierzchni), `--header-bg`, `--grid-line`, `--grid-size`.
- Zasady: tekst na żółtym zawsze `var(--on-yellow)` (`--ink` w trybie
  ciemnym jest jasny), ciemne panele przez `var(--graphite)` (nie
  `var(--ink)`), żadnych nowych twardych kolorów w komponentach - tylko
  tokeny. Dekoracyjna mikro-siatka: utility `.grid-surface` albo reguły
  `:global(html[data-theme='dark'])` w stylach strony (hero strony głównej).
- Test: `pnpm theme:qa` (zrzuty w `shots/theme`, kontrast axe, overflow,
  błędy konsoli) dla obu motywów.

### Realizacje

- Dane: `src/data/realizacje.json` (generowany z panelu, źródło prawdy = SQLite).
- Lista `/realizacje/` z filtrami kategorii: siatka 3 kolumny (≥1024 px),
  2 (641-1023 px), 1 na telefonie; filtry ukrywają kafelki przez `[hidden]`
  (reguła `.case[hidden] { display: none !important; }`), „Wszystkie”
  przywraca pełny zbiór, aktywny przycisk ma `is-active` i `aria-pressed`.
  Pusta kategoria pokazuje komunikat zamiast pustej siatki.
- Podstrony `/realizacje/<slug>/`: normalny kontener (bez `container--narrow`),
  desktop 2 kolumny (treść + sticky aside), tablet 1 kolumna z aside pod treścią,
  telefon 1 kolumna z aside nad treścią; galeria 3/2/1 kolumny; slider
  **przed/po** (`BeforeAfter.astro`).
- Kategorie: Chłodzenie, Wydajność, Matryce, Gniazda, Obudowy, Zalania,
  Płyty główne, Inne — edytowalne w panelu.

### Marki

- 9 podstron `/serwis-<marka>-wroclaw/` z unikalnym H1, meta, wstępem,
  typowymi problemami, naprawami, modelami, FAQ, linkami do usług i CTA.
  Marka Microsoft Surface została usunięta z listy i z podstron (decyzja
  właściciela); adres `/serwis-microsoft-surface-wroclaw/` ma 301 na `/uslugi/`.
- Oznaczone w panelu `review: pending` (do zatwierdzenia przez właściciela),
  ale opublikowane. Nie są autoryzowanym serwisem producentów — treści tego
  nie sugerują.

### Poradnik

- 17 artykułów publicznych + 25 szkiców markowych (`draft: true`). Szkice nie
  trafiają na produkcję; są widoczne w podglądzie `/podglad/`.
- Wszystkie opublikowane artykuły przeszły audyt językowy (2026-11-25 i
  2026-11-27), ceny w tekstach nie są wpisane na sztywno - pochodzą z
  `src/data/cennik.json` przez tokeny `{{cena:klucz}}`.
- Duplikat artykułu o cenach naprawy (slug z literówką `ile-kostuje-...`) został
  usunięty, a stary adres ma 301 na poprawny `/poradnik/ile-kosztuje-naprawa-laptopa/`.

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
- Cron: `/etc/cron.d/studio-komp` — opinie co 6 h, porządki codziennie 3:23,
  worker kolejki powiadomień formularza co minutę (`tickets-worker.php`).

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
| Zgłoszenia | lista z filtrami/wyszukiwaniem, liczniki statusów, szczegóły, historia, kolejka powiadomień SMS/e-mail z ponawianiem, eksport CSV (admin/editor; usuwanie tylko admin) |
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
- **Konwersja (`generate_lead`)** — potwierdzone przyjęcie zgłoszenia przez
  `/api/zgloszenie` (sukces), nie próba wysłania formularza — to nie to samo co
  kliknięcie telefonu.
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
  Usunięta podstrona marki Surface ma 301 na `/uslugi/`.
- **Robots**: `src/pages/robots.txt.ts` (host + sitemap).
- **Dane strukturalne**: LocalBusiness/ProfessionalService, WebSite, Service,
  FAQPage, BreadcrumbList, Article, Brand. Godziny, geo, aggregateRating z site.json.
- **Przekierowania**: stare adresy (o-firmie.html, cennik.html, galeria.html,
  aktualnosci, googleads, mapa, oferta/…) → 301 na właściwe podstrony.
- **Meta**: title/description per podstrona; OG/Twitter; geo meta; brak duplikacji.
- **Zdjęcia**: ALT wymagany w panelu; ilustracje SVG; upload konwertuje miniatury.
- **Cennik jako jedno źródło**: każda pozycja `src/data/cennik.json` ma stały
  `key`; treści używają tokenów `{{cena:klucz}}`, a `scripts/rehype-prices.mjs`
  podmienia je przy buildzie. `scripts/prices-cache.mjs` czyści cache treści
  Astro, gdy zmieni się sam cennik. Panel -> Ustawienia -> Cennik zachowuje
  klucze i po publikacji aktualizuje cenę na stronie głównej, w cenniku,
  usługach, markach, FAQ, landingach i poradniku. Brak ceny (`… zł`) pokazuje
  „wycena po diagnozie” - bez wymyślania kwoty.
- **Long-tail (2026-11-27)**: dodane podstrony `naprawa-laptopow-gamingowych-wroclaw`
  i `serwis-komputerowy-wroclaw`; rozbudowane sekcje o gniazdo ładowania,
  płytę główną laptopa, czyszczenie laptopa gamingowego i odzyskiwanie danych
  z SSD; nowe artykuły o cenie naprawy płyty głównej i ładowaniu baterii.
- **Audyt**: `node scripts/check-prices.mjs` (ceny/tokeny) i
  `node scripts/check-seo.mjs` (duplikaty title/description, H1, canonical, OG,
  sitemap, linki wewnętrzne).

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
- Formularz: dane trafiają do własnego endpointu `/api/zgloszenie` i bazy panelu
  (tabele `tickets`, `notifications`), nie do bazy analityki. Opcjonalnie
  powiadomienie SMS do serwisu (domyślnie tryb testowy bez wysyłki) i kopia
  e-mail przez SMTP. Szczegóły: `docs/formularz-zgloszen.md`.
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
| SMS API | zaimplementowane: SMSAPI / SerwerSMS, **domyślnie tryb testowy** (bez wysyłki) | token API, numer odbiorcy, decyzja właściciela o realnej wysyłce (`docs/formularz-zgloszen.md`) |

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

## L. Status napraw i SMS - zrealizowane

Cel zrealizowany: klient sprawdza postęp naprawy przez unikalny link
(`https://<site_url>/status/<token>/`, obecnie testowo
`https://sk.mati-it.pl/status/<token>/`), a serwis wysyła dwa SMS-y z linkiem -
po przyjęciu sprzętu i po zmianie statusu na „gotowy do wydania”. Stronę
renderuje PHP z bazy panelu (strona publiczna pozostaje statyczna, bez bazy
przy żądaniu). Dokumentacja: `docs/status-klienta.md`.

Zrobione: token śledzenia, strona `/status/<token>/` (HTML + `?format=json`),
mapowanie statusów na etapy klienta, publiczna notatka, SMS `client_accepted`
i `client_ready` w istniejącej kolejce `notifications`, limit tempa, `noindex`
i `no-store`, konfiguracja nginx, dokumentacja.

Wymaga decyzji właściciela: realna wysyłka SMS (konto, token, nadawca),
ważność linku, kopia klucza tokenów, domena docelowa, retencja zgłoszeń.

### L.1 Model bazy - użyto istniejącej tabeli `tickets`

Nie powstała osobna tabela `repairs` ani `sms_log`. Uzasadnienie: zgłoszenie
z formularza i naprawa to ten sam byt (ten sam numer `SK-ROK-000123`, ta sama
historia statusów i ta sama kolejka powiadomień), więc druga tabela oznaczałaby
duplikację danych klienta i ręczne wiązanie rekordów.

| Element | Stan | Opis |
|---|---|---|
| `tickets.status` | istniejąca kolumna | `new`, `read`, `in_progress`, `done`, `closed`, `cancelled` (dla zleceń anulowanych w aplikacji) |
| `tickets.public_note` | nowa (`db_ensure_column`) | publiczna notatka serwisu, do 500 znaków, bez danych osobowych |
| `tickets.status_token_version` | nowa | wersja tokenu w linku (unix time); nowa wartość = nowy link, stary przestaje działać |
| `tickets.archived` | istniejąca | `1` = zgłoszenie niedostępne publicznie (404) |
| `ticket_history` | istniejąca | historia statusów, źródło dat etapów |
| `notifications` | istniejąca | SMS do klienta (`client_accepted`, `client_ready`) tą samą kolejką i workerem |
| `status_rate` | nowa | `ip_hash`, `created_at` - limit tempa publicznej strony statusu |

Dane wrażliwe: telefon klienta i opis usterki zostają w `tickets`, ale nie są
nigdzie renderowane na stronie statusu - `ticket_public_view()` zwraca wyłącznie
numer, sprzęt, etapy z datami, publiczną notatkę i dane kontaktowe firmy.
Kolumny z danymi osobowymi nie są szyfrowane (baza poza webrootem, dostęp przez
PHP-FPM jako `skcms`).

### L.2 Token i link

- Token: `<id zgłoszenia>.<wersja>.<podpis>`; podpis to HMAC-SHA256 (base64url)
  z `ticket|<id>|<wersja>`, liczony kluczem serwera. W bazie nie ma tokenu ani
  jego hasha - tylko `status_token_version`, więc kopia bazy nie daje linków.
- Klucz: `tracking_secret` w konfiguracji panelu albo `data/tracking.key`
  (64 znaki hex, 0600, poza repozytorium i webrootem). Utrata klucza unieważnia
  wszystkie wysłane linki - trzeba wtedy wygenerować nowe w panelu.
- Link: `https://<site_url>/status/<token>/` (`site_url` z konfiguracji panelu).
- Unieważnienie: `tickets_tracking_token($id, true)` zmienia wersję; brak
  automatycznej ważności (decyzja właściciela).
- Strona statusu: numer zgłoszenia, sprzęt, trzy etapy + „Odebrany” z datami,
  publiczna notatka, dane kontaktowe firmy; **bez** imienia, telefonu, adresu,
  ceny, notatek wewnętrznych.
- Rate limiting: 30 żądań/min na hash IP (`status_rate`), `X-Robots-Tag:
  noindex, nofollow`, `Cache-Control: no-store`, `Referrer-Policy: no-referrer`,
  `Content-Security-Policy` (`default-src 'none'`, styl i fonty tylko z
  `/tracking/` - brak inline i CDN), `access_log off` dla `/status/` (token nie
  trafia do logów). Obsługiwane metody: GET i HEAD (HEAD zwraca nagłówki bez
  treści), pozostałe - 405 z `Allow: GET, HEAD`.

### L.3 Endpointy - co powstało, co nie

```
GET /status/<token>/              (publiczne, GET) - HTML w stylu marki
GET /status/<token>/?format=json  (publiczne, GET) - {"ok":true,"status":{...}}
POST /api/service-sync.php        (tylko POST + HMAC) - sync zlecenia z serwis.mati-it.pl
```

Nie powstały: `POST /api/repairs`, `PATCH /api/repairs/:id/status`,
`POST /api/repairs/:id/token`, `POST /api/sms/webhook`. Zamiast nich działa
panel (sesja + CSRF): status zmienia `tickets_change_status()`, nowy link daje
`tickets_tracking_token($id, true)`, publiczną notatkę zapisuje
`ticket_set_public_note()`. Webhook doręczeń nie jest potrzebny, dopóki
wysyłka działa w trybie testowym; `delivery_status` uzupełnia worker.

### L.4 SMS API - dostawcy (stan faktyczny)

Zaimplementowani dostawcy: SMSAPI i SerwerSMS (`panel/lib/sms.php`), domyślnie
tryb testowy (bez połączenia z dostawcą i bez kosztów). SMS Planet, Twilio
i Vonage/Nexmo **nie są** zaimplementowane - tabela porównawcza z poprzedniej
wersji tego dokumentu była projektem i nie została zweryfikowana u dostawców,
dlatego nie podajemy tu cen.

Do włączenia realnej wysyłki: konto u dostawcy, token API, zatwierdzony nadawca,
numer odbiorcy i wyłączenie trybu testowego (Ustawienia -> Formularz).

### L.5 Szablony wiadomości - dwa, nie sześć

| Szablon | Kiedy | Domyślna treść |
|---|---|---|
| `client_accepted` | po przyjęciu sprzętu (`tickets_create`) | `Studio-Komp: przyjelismy sprzet ({sprzet}). Status naprawy: {link}` |
| `client_ready` | po zmianie statusu na `done` | `Studio-Komp: {sprzet} jest gotowy do odbioru. Kotlarska 14, pon.-pt. 7:00-15:00. Status: {link}` |

Placeholdery: `{numer}` (alias `{number}`), `{imie}`, `{sprzet}`, `{link}`,
`{serwis}`, `{telefon}`. Uwaga: w szablonie `new_ticket` (SMS do serwisu)
`{telefon}` to numer klienta, a w szablonach klienta - numer serwisu.

Treści są edytowalne w panelu (Ustawienia -> Formularz), polskie znaki są
zamieniane na ASCII (GSM-7), treść jest ograniczana do 459 znaków. Zrezygnowano
z sześciu etapowych SMS-ów: klient ma jeden link i widzi na nim wszystkie etapy,
a dwa SMS-y ograniczają koszt i liczbę wiadomości. Zgoda na kontakt w sprawie
zgłoszenia jest zbierana w formularzu (`consent_version`), a SMS-y dotyczą
wyłącznie realizacji naprawy (nie marketingu).

### L.6 Koszty i ryzyka

- Koszt SMS: 2 wiadomości na naprawę zamiast 6; ceny wg cennika dostawcy
  (`docs/formularz-zgloszen.md` świadomie nie podaje cen).
- Koszt Places API: bez zmian, patrz sekcja K.
- Ryzyka: utrata `data/tracking.key` (stare linki przestają działać, klient
  dostaje nowy), błędny numer klienta (SMS nie dojdzie - link jest w panelu),
  koszty SMS przy błędnych danych, RODO (w SMS-ach tylko numer zgłoszenia,
  sprzęt i link).
- Link jest sekretem: przesłanie go dalej ujawnia status naprawy. Ograniczają to
  `noindex`, `no-store` i limit tempa, ale nie zastępują logowania.

### L.7 Integracja z aplikacją serwisową (serwis.mati-it.pl)

Aplikacja warsztatu (Laravel, `D:\Deepseekapp\serwis`, repozytorium `sk-core`)
jest źródłem prawdy o zleceniu. Po przyjęciu i każdej zmianie statusu wysyła
podpisane HMAC-em żądanie `POST /api/service-sync.php`; CMS prowadzi jedno
zgłoszenie na zlecenie (`tickets.external_ref = SRV-ROK-000000`), zwraca link do
strony statusu i kolejkuje SMS-y (`client_accepted` przy utworzeniu,
`client_ready` przy pierwszym `done`).

- Endpoint: tylko POST, podpis `HMAC-SHA256(secret, timestamp."\n".rawBody)`,
  timestamp ±300 s, limit 60/min/IP-hash. Sekret `service_sync_secret` w
  `/srv/studio-komp-cms/config.php` musi być ten sam co `CLIENT_STATUS_SECRET`
  w `/srv/sk-serwis/.env`; pusty = integracja wyłączona (503), aplikacja działa
  dalej bez linku.
- Mapowanie: `pending` -> `new`, `in_progress`/`awaiting_parts` -> `in_progress`,
  `ready_for_pickup` -> `done` (+ `client_ready`), `released` -> `closed`,
  `cancelled` -> `cancelled`.
- Linki: nowe tokeny są krótsze (MAC 16 B, id/wersja base36), stare 43-znakowe
  nadal działają. `status_base_url` w konfiguracji panelu przenosi linki na
  `status.mati-it.pl/<token>` bez zmian w kodzie (wymaga vhosta).
- Dokumentacja i diagnostyka: `docs/status-klienta.md` §11; test regresyjny
  `panel/bin/self-check-service-sync.php` (151 warunków).

#### L.7.1 Zgłoszenia z formularza -> aplikacja warsztatu (kierunek odwrotny)

Formularz na stronie tworzy zgłoszenie `SK-...` w CMS. CMS wysyła je podpisanym
HMAC-em żądaniem `POST /api/intake-tickets` do aplikacji warsztatu (na VPS
`http://127.0.0.1:8090`), która pokazuje je w lewym menu pod „Wydane” jako
„Zgłoszenia”. Sekret `service_intake_secret` w CMS musi być ten sam co
`INTAKE_SYNC_SECRET` w `/srv/sk-serwis/.env`; pusty = integracja wyłączona.
Nieudana wysyłka jest ponawiana przez `tickets-worker.php` (max 8 prób, ostatnie
30 dni). Do skrzynki trafiają wyłącznie formularze (`external_ref` puste);
zlecenia `SRV-...` z aplikacji nie są dublowane. Szczegóły:
`docs/formularz-zgloszen.md` §14.

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
| 2026-11-25 | Formularz zgłoszeń: własny endpoint `/api/form-token` i `/api/zgloszenie` (walidacja, token double-submit, honeypot, filtr botów, limit 3/10 min), zapis w SQLite panelu z numerem `SK-ROK-000123`, historia statusów, kolejka powiadomień SMS (SMSAPI/SerwerSMS, domyślnie tryb testowy bez wysyłki) i opcjonalny e-mail SMTP z retry, panel Zgłoszenia, worker cron co minutę, dokumentacja `docs/formularz-zgloszen.md`. |
| 2026-11-25 | Instrukcja aktualizacyjna nr 2: logo w nagłówku większe (wersja przycięta do tuszu, 38 px / 30 px mobile) i favicony wyłącznie ze znakiem SK (ICO 16/32/48, 16/32/180/192/512, SVG, manifest, cache-busting `?v=2`). |
| 2026-11-25 | Instrukcja nr 2: wszystkie długie myślniki U+2013/U+2014 w treściach, meta, danych CMS i panelu zamienione na `-`; nowe ceny (czyszczenie laptopa i stacjonarnego 200-250 zł, instalacja i konfiguracja systemu 250 zł z opisem „ze sterownikami”, wymiana matrycy 600-700 zł) ujednolicone w cenniku, usługach, FAQ, stronie głównej, landingach i poradniku. |
| 2026-11-25 | Instrukcja nr 2: usunięto Microsoft Surface z listy marek, podstron marek i szkiców (301 z `/serwis-microsoft-surface-wroclaw/` na `/uslugi/`). |
| 2026-11-25 | Instrukcja nr 2: sekcja „Co najczęściej naprawiamy” przebudowana na 9 prostokątnych kafelków (3/2/1-2 kolumny) ze zdjęciem i tytułem, opisem procesu po hover/kliknięciu, obsługą klawiatury i limitem 6 kafelków na mobile; panel: pola `tile`, `tileTitle`, `tileText`, `image`, `order` w edytorze usług. |
| 2026-11-25 | Instrukcja nr 2: naprawione filtry realizacji (CSS `[hidden]`), siatka 3/2/1 bez wyróżnionego kafelka, szerszy desktopowy widok szczegółów realizacji (zwykły kontener, 2 kolumny, galeria 3 kolumny), stopka z listą marek w dwóch kolumnach i akordeonem na mobile. |
| 2026-11-25 | Instrukcja nr 2: audyt językowy wszystkich 15 opublikowanych artykułów poradnika (krótsze, konkretniejsze teksty, bez schematów marketingowych, poprawione ceny), bez zmian wyglądu listy `/poradnik/`. |
| 2026-11-25 | Status naprawy dla klienta: `/status/<token>/`, tokeny podpisane (HMAC-SHA256, w bazie tylko `status_token_version`), 3 etapy + „Odebrany”, SMS `client_accepted`/`client_ready`, publiczna notatka, limit tempa, panel, nginx, dokumentacja `docs/status-klienta.md`. |
| 2026-11-25 | Dwa repozytoria GitHub (`sk-serwis` - strona i panel, `sk-klient` - strona dla klienta czytająca JSON API). `sk-klient` wdrożona na VPS pod `/klient/` (`/srv/sk-klient`, `open_basedir` poolu rozszerzony), dokumentacja `docs/github-repozytoria.md`. |
| 2026-11-25 | Numeracja zgłoszeń bez powtórek: tabela `ticket_counters` (licznik roczny podnoszony w transakcji zapisu); usunięcie zgłoszenia nie oddaje już jego numeru. Test w `panel/bin/self-check-status.php` (56 warunków). |
| 2026-11-25 | Trzecie repozytorium GitHub `sk-core` - kod źródłowy aplikacji dla serwisantów (Laravel 12, katalog główny projektu `D:\Deepseekapp\serwis`); 295 plików, bez `vendor/`, `.env` i danych z `storage/app/data`. `docs/github-repozytoria.md` opisuje trzy repozytoria i komendy push. |
| 2026-11-26 | Aplikacja warsztatu (`sk-core`, serwis.mati-it.pl): kreator przyjęcia z presetami terminu 1/2/3/7 dni, własną liczbą dni i inną datą, sekcje formularza, przebudowany protokół wydania (2 kolumny, 1366×768 bez przewijania), większe chipy filtrów kolejki, przycisk „Przyjmij” → „Kolejka”, karta „Status dla klienta” z kopiowaniem linku. Synchronizacja zleceń do CMS przez podpisany HMAC endpoint `POST /api/service-sync.php` (`external_ref`, `client_status_url`, komenda `services:sync-status`); w panelu zgłoszenia z badge „Z serwisu”, status `cancelled`, krótsze tokeny i `status_base_url`. |
| 2026-11-27 | Cennik jako jedno źródło: stałe `key` w `cennik.json`, tokeny `{{cena:klucz}}` w treściach, rehype plugin, czyszczenie cache po zmianie cennika, zachowanie kluczy w panelu. Braki cen pokazują „wycena po diagnozie” zamiast „… zł”. |
| 2026-11-27 | Strona główna: statystyka „2013 / rok założenia”, wcięcie etykiet statystyk na desktopie, jedna prawdziwa opinia Google na mobile, mniej powtórzeń (diagnoza, ekspres, telefon). Poprawione „problema”, „zlecenej” i inne literówki. |
| 2026-11-27 | SEO: usunięty duplikat artykułu o cenach (301), nowe strony „naprawa laptopów gamingowych Wrocław” i „serwis komputerowy Wrocław”, rozbudowane usługi (gniazdo ładowania, płyta główna, czyszczenie gamingowe, SSD), dwa nowe artykuły, `scripts/check-seo.mjs`. |
| 2026-11-27 | Formularz -> panel warsztatu: podpisany HMAC endpoint `POST /api/intake-tickets`, skrzynka „Zgłoszenia” pod „Wydane”, przekazywanie/przyjęcie/odrzucenie, retry w workerze; `.env`/config po obu stronach. |
| 2026-11-27 | Aplikacja warsztatu: akceptacja wydania (statusy `awaiting_approval` i `with_remarks`, rola Szef, powiadomienia w panelu, „Potwierdź”/„Zwróć z uwagami”), kompaktowy kafelek „Status klienta”, usunięty podpis o 15:00 w formularzu przyjęcia. |
| 2026-11-28 | Tryb ciemny wzorowany na wizytówce serwisu (grafit `#101417`, mikro-siatka, żółty akcent): tokeny `data-theme`, przełącznik w nagłówku i na LP z zapisem wyboru, domyślnie preferencja systemowa, brak migotania; stałe tokeny `--on-yellow`/`--on-light`, audyt kontrastu komponentów i stron, `scripts/theme-qa.mjs` (`pnpm theme:qa`). |

---

## N. Uruchomienie lokalne i testy

```powershell
pnpm install
pnpm dev                     # http://127.0.0.1:4321
pnpm build                   # dist/
pnpm preview                 # podgląd builda
pnpm measure                 # pomiar mobile (liczba ekranów)
pnpm qa                      # axe/CLS/LCP (jeśli skonfigurowane)
pnpm theme:qa                # oba motywy: zrzuty, kontrast, overflow (shots/theme)
```

Testy panelu (na żywo, wymagają konta):

```powershell
$env:SITE_URL='https://sk.mati-it.pl'; $env:PANEL_USER='...'; $env:PANEL_PASS='...'
node scripts/qa-panel.mjs        # wszystkie widoki + mobile + błędy JS
node scripts/qa-panel-write.mjs  # zapisy: strona, przekierowanie, media, ustawienia
node scripts/qa-events.mjs       # CMP, mapa, zdarzenia, brak tagów bez zgody
node scripts/qa-inst2.mjs        # kafelki, realizacje, stopka, ceny, myślniki, ikony
node scripts/check-live.mjs      # crawl live (sitemap, 301, 404)
```

Testy zawartości i parsera na serwerze:

```bash
runuser -u skcms -- php /srv/studio-komp/panel/bin/self-check.php
runuser -u skcms -- php /srv/studio-komp/panel/bin/self-check-status.php
```

`self-check-status.php` to test regresyjny publicznej strony statusu naprawy: 75
warunków (mapowanie statusów na etapy, tokeny śledzenia, podrobiony podpis,
unieważnianie linku, kolejka `client_accepted`/`client_ready`, tryb testowy,
model widoku bez danych osobowych, limit tempa, numeracja zgłoszeń bez powtórek,
status „Anulowane” i oba formaty tokenów).
Działa na tymczasowym katalogu danych - nie dotyka bazy produkcyjnej i nie wysyła
SMS. Lokalnie na Windows:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 panel/bin/self-check-status.php
php -d extension=pdo_sqlite -d extension=sqlite3 panel/bin/self-check-service-sync.php
```

`self-check-service-sync.php` to test integracji z `serwis.mati-it.pl`: 151
warunków (podpis HMAC, timestamp, idempotencja po `external_ref`, mapowanie
statusów, kolejka `client_accepted`/`client_ready`, krótkie tokeny, migracja
starej bazy i prawdziwe HTTP na wbudowanym serwerze PHP).

## O. Znane ograniczenia i rzeczy do zrobienia przez właściciela

1. **ID analityki** — GA4/GTM/Ads/Clarity puste; wpisać w panelu.
2. **Formularz zgłoszeń** — działa bez zewnętrznych kluczy (zapis w bazie panelu); do włączenia prawdziwych powiadomień SMS/e-mail patrz punkt 10.
3. **Klucz Google Places API** — opcjonalny; bez niego ocena/liczba opinii ręczna.
4. **Ceny „… zł”** w cenniku — cztery pozycje (modernizacja, wymiana klawiatury,
   naprawa zawiasów, wymiana portów) nie mają jeszcze ustalonej kwoty; pokazują
   „wycena po diagnozie”. Właściciel wpisuje cenę w Ustawienia -> Cennik i po
   publikacji zaktualizuje się na całej stronie.
5. **Zdjęcia realizacji/usług** — obecnie ilustracje techniczne; wgrać prawdziwe
   zdjęcia przez panel Media i przypisać.
6. **Artykuły markowe** — 25 szkiców do przeglądu i publikacji w panelu.
7. **Mapa a zgody** — patrz sekcja J (decyzja właściciela).
8. **Kopie bazy panelu** — dodać backup SQLite (np. cron + kopia poza VPS).
9. **2FA** — zalecane włączenie po pierwszym logowaniu.
10. **SMS i SMTP formularza** — powiadomienia działają w trybie testowym (bez wysyłki); aby włączyć prawdziwe SMS-y: konto u dostawcy, token, numer odbiorcy i wyłączenie trybu testowego w Ustawieniach → Formularz. E-mail opcjonalny (dane SMTP).
11. **Panel warsztatu (serwis.mati-it.pl)** — konto `admin` ma rolę **Szef** (`owner`)
    i zatwierdza wydanie. Kolejne konto dla serwisanta tworzy się w panelu
    Użytkownicy jako „Serwisant admin” (pełne uprawnienia warsztatowe, bez
    akceptacji wydania). Jeśli aktywnego Szefa brak, aplikacja pokazuje
    ostrzeżenie; rolę nadaje `php artisan users:owner <login>`.
12. **Powiadomienia o akceptacji wydania** — na razie tylko w aplikacji (badge,
    licznik, znacznik w tytule). E-mail/push wymagają osobnej integracji
    (kolejka/driver); szczegóły w README aplikacji warsztatu.
