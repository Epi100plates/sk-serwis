# Wdrożenie na hosting www (bez VPS, bez Node na serwerze)

Strona Studio-Komp to **statyczny zestaw plików** (HTML + CSS + JS + obrazy). Nie potrzebuje PHP,
bazy danych, Node'a ani SSH. Wystarczy wgrać pliki przez FTP (albo ZIP przez panel hostingu) do
katalogu `public_html/` i włączyć certyfikat SSL.

**Paczka jest przenośna:** ten sam build działa w katalogu głównym domeny
(`https://studio-komp.pl/`) **i w podkatalogu** (`https://epii.pl/sk/`, `.../nowa/`, dowolnym
innym). Nie trzeba dwóch buildów ani parametru `BASE_PATH` — skrypt zamienia ścieżki na
względne, więc HTML, CSS, fonty i linki działają pod każdym montowaniem.

Wariant VPS (Cloudflare Tunnel, `sk.mati-it.pl`) jest opisany w [wdrozenie-vps.md](wdrozenie-vps.md).

## 1. Wybierz wariant

| Wariant | Kiedy | Wymaga |
| --- | --- | --- |
| **A. Obecny hosting (FTP)** — domena `studio-komp.pl` | Chcesz zostać na hostingu, który obsługuje dziś starą stronę | Dostęp FTP/SFTP, panel hostingu, certyfikat SSL |
| **B. Podkatalog** `public_html/sk/` | Szybki podgląd u klienta bez ruszania starej strony | to samo (jeden podkatalog) |
| **C. Cloudflare Pages** (darmowe) | Nie chcesz FTP ani VPS | Konto Cloudflare, `wrangler` |
| **D. Netlify Drop** (darmowe) | Najszybszy podgląd dla klienta | Konto Netlify |

## 2. Paczka do wgrania — jedna komenda

```powershell
pnpm pack:www
```

Efekt: `release/studio-komp-www.zip` (~1 MB, 109 plików, 50 stron HTML). Skrypt
[pack-www.ps1](../deploy/pack-www.ps1) robi trzy rzeczy:

1. **build** (`SITE_URL=https://studio-komp.pl` — canonical, sitemap, robots),
2. **ścieżki względne** (`scripts/relativize.mjs`) — `href="/cennik/"` → `href="./cennik/"`,
   `/_astro/x.css` → `./_astro/x.css`, strony w podkatalogach → `../`,
3. **ZIP + weryfikacja**: na najwyższym poziomie archiwum leży `index.html`, `.htaccess`,
   `robots.txt`, `sitemap-index.xml`.

Weryfikacja przenośności (przed wgraniem, po każdym buildzie):

```powershell
pnpm qa:portable
```

Skrypt przechodzi wszystkie strony w **obu montowaniach** (`/` i `/sk/`), rozwiązuje każdy
odnośnik i `url()` w CSS jak przeglądarka i sprawdza, czy plik istnieje. Wynik referencyjny:
`Strony: 100/100, odnosniki: 5954, rozwiazane pliki: 5954, odnosniki absolutne: 0`.

`404.html` celowo zostaje z ścieżkami absolutnymi (jest serwowany dla dowolnego błędnego adresu).

## 3. Krok po kroku — hosting (FTP/panel)

**0. Kopia zapasowa starej strony.** FileZilla → pobierz całe `public_html/` do
`backup-stara-strona/`. To warunek szybkiego wycofania zmian.

**1. Zbuduj paczkę:** `pnpm pack:www` (sekcja 2).

**2. Wgraj zawartość paczki** — do `public_html/` (produkcja) **albo** do `public_html/sk/`
(podgląd):
- **panel hostingu:** wgraj ZIP do wybranego katalogu → „rozpakuj” → usuń ZIP,
- **FTP (FileZilla):** rozpakuj ZIP lokalnie, wejdź do rozpakowanego folderu, zaznacz **całą
  zawartość** i przeciągnij do `public_html/` (lub `public_html/sk/`). Nie wgrywaj folderu
  nadrzędnego jako katalogu.
- Uprawnienia: pliki `644`, katalogi `755`.

**3. SSL.** Panel hostingu → certyfikat (Let's Encrypt / AutoSSL). Kolejność: najpierw
działający certyfikat, potem wymuszanie HTTPS (panel + `.htaccess`) — inaczej pętla przekierowań.

**4. Wyczyść cache hostingu** (LiteSpeed Cache), jeśli włączony.

**5. Sprawdź adresy** — sekcja 5. Dla podkatalogu: `https://twoja-domena.pl/sk/`.

## 4. Co działa w podkatalogu, a co tylko w katalogu głównym

| Element | W katalogu głównym | W `/sk/` |
| --- | --- | --- |
| Wygląd, CSS, fonty, obrazy, linki wewnętrzne | działa | działa |
| Przekierowania 301 ze starych adresów (`.htaccess`) | działa | reguły dotyczą roota — w podglądzie nieistotne |
| `robots.txt`, sitemap, canonical | `studio-komp.pl` | `studio-komp.pl` (kanoniczny adres to produkcja) |
| Manifest / ikony | działa | działa (ścieżki względne) |
| Formularz, analityka | jak w `site.json` | tak samo |

Podgląd pod `/sk/` ma `canonical` na `studio-komp.pl`, więc Google traktuje go jako kopię
roboczą produkcji, a nie osobną stronę do wypozycjonowania.

## 5. Checklista po wgraniu

| Adres | Oczekiwany wynik |
| --- | --- |
| `/` (lub `/sk/`) | 200, nowa strona z pełnym wyglądem |
| `/cennik/` | 200 |
| `/o-firmie.html` | **301** → `/o-serwisie/` (tylko katalog główny) |
| `/cennik.html` | **301** → `/cennik/` (tylko katalog główny) |
| `/oferta/` | **301** → `/uslugi/` (tylko katalog główny) |
| `/cokolwiek-nieistniejace/` | **404** z ładną stroną błędu |
| `/sitemap-index.xml`, `/robots.txt` | 200 |

Dodatkowo: telefon i przycisk „Zjazd” na telefonie, formularz (sekcja 6), brak ostrzeżeń
o mieszanej treści, poprawny manifest (`application/manifest+json`).

Po publikacji na docelowej domenie: `node scripts/check-live.mjs https://studio-komp.pl`.

## 6. Czego hosting FTP nie zrobi sam

- **Formularz** — Netlify Forms działają tylko na Netlify. Na hostingu FTP potrzebny **Web3Forms**:
  klucz w `src/data/site.json` (`form.accessKey`), potem `pnpm pack:www` i ponowne wgranie.
  Bez klucza zgłoszenia nie dotrą — lepiej wtedy formularz ukryć.
- **Panel CMS `/admin/`** — bez Netlify/Git Gateway nie zapisze zmian. Edycja = plik +
  `pnpm pack:www` + wgranie.
- **Analityka** — `gtm` / `ga4` w `src/data/site.json`, ponowny build (Consent Mode v2).

## 7. Wariant bez FTP i bez VPS (darmowy)

### Cloudflare Pages

1. Panel Cloudflare → **Workers & Pages → Create → Pages → Upload assets** → wskaż katalog `dist/`.
2. Adres: `https://studio-komp.pages.dev`.
3. Kolejne wersje: `pnpm dlx wrangler pages deploy dist --project-name studio-komp`.
4. Własna domena: Pages → Custom domains → `studio-komp.pl`.

### Netlify Drop

`app.netlify.com/drop` → przeciągnij folder `dist/`. Uwaga: **Netlify Forms nie działają przy
wgrywaniu folderu** (tylko deploy z GitHub) — formularz wymaga Web3Forms albo repozytorium.

## 8. Gdy hosting nie obsługuje `.htaccess`

Na hostingu nginx bez Apache/LiteSpeed `.htaccess` jest ignorowany: strona i linki działają,
ale **301 i własna strona 404 nie**. Wtedy: przekierowania w panelu hostingu, `AllowOverride All`
u obsługi, albo wariant Cloudflare Pages/Netlify (reguły w `_redirects`).
Wzór reguł nginx: `deploy/nginx/sk.mati-it.pl.conf`.

## 9. Aktualizacja i wycofanie

```powershell
pnpm pack:www     # po każdej zmianie treści
pnpm qa:portable  # kontrola przenośności
```

…i wgraj ponownie zawartość (albo tylko zmienione pliki z `dist/`). Wycofanie: wgraj z powrotem
`backup-stara-strona/`.

## 10. Diagnostyka

**Objaw: strona bez CSS, „gołe” HTML, brak wyglądu** (np. `https://epii.pl/sk/`). Pliki CSS są
na serwerze, ale HTML linkuje je pod `/_astro/...` zamiast `./_astro/...` — wgrany był build
z absolutnymi ścieżkami. Sprawdzenie:

```powershell
# w HTML zły odnośnik: href="/_astro/...
# działający:          href="./_astro/...  (strona główna)
#                      href="../_astro/... (podstrona)
```

Naprawa: `pnpm pack:www`, `pnpm qa:portable`, wgraj nowy ZIP. Diagnoza w przeglądarce:
DevTools → Network → plik CSS ma 404 przy `/_astro/…`, a `/_astro/…` pod `/sk/` daje 200.

## 11. Różnica względem VPS

| | VPS (`pnpm deploy`) | Hosting www (`pnpm pack:www`) |
| --- | --- | --- |
| Publikacja | `pnpm deploy` przez SSH | ZIP + FTP/panel |
| Adres | `https://sk.mati-it.pl` | `https://studio-komp.pl` lub `/sk/` |
| Przekierowania | nginx | `.htaccess` (Apache/LiteSpeed) |
| Ścieżki w HTML | absolutne (serwer = root) | względne (działa pod każdym katalogiem) |
