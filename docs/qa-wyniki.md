# QA — wyniki testów (2026-11-25)

Wersja testowana: https://sk.mati-it.pl (release 20261125-112433, 25.11.2026).
Zakres: strona publiczna, panel CMS, analityka/CMP, SEO, mobile, bezpieczeństwo,
publikacja/rollback.

## 1. Build i treść

| Test | Wynik |
|---|---|
| `pnpm build` (produkcyjnie) | 67 stron, OK |
| `pnpm build` z `PREVIEW=1` | drafty w `dist-preview/`, OK |
| Parser/serializator YAML panelu (`panel/bin/self-check.php`) | 69 plików/testów, OK, round-trip stabilny |
| Sitemap | 56 URL (usługi, marki, poradnik, realizacje, strony) |
| Canonical | wszystkie indeksowane strony → `https://sk.mati-it.pl/...` |
| 404 | własna strona, kod 404 |
| Przekierowania 301 | `/cennik.html`→`/cennik/`, `/oferta/`→`/uslugi/` itd. — 301 |
| Mobile (390×844) | strona główna **4,3 ekranu**, brak poziomego przewijania |
| Desktop (1440) | brak overflow, sekcje kompletne |

## 2. Panel CMS (na żywo)

`scripts/qa-panel.mjs` — 17 widoków panelu, desktop + mobile:

- wszystkie widoki HTTP 200, brak błędów PHP/JS,
- logowanie działa, brak dostępu bez sesji (302 → login),
- mobilny panel: burger, brak overflow (0 px),
- edytor treści: pola tytułu, treści i zapisu obecne.

`scripts/qa-panel-write.mjs` — zapisy:

| Operacja | Wynik |
|---|---|
| Utworzenie podstrony z panelu | OK (commit `Panel: zapis strony/...`) |
| Usunięcie podstrony | OK (commit `Panel: usunięcie...`) |
| Dodanie przekierowania | OK (lista, generacja plików nginx/_redirects/.htaccess) |
| Usunięcie przekierowania | OK |
| Zapis ustawień (menu/stopka) | OK, brak nieoczekiwanych zmian plików |
| Media | ścieżka uploadu przetestowana osobno (wymaga poprawnego pola `do=upload`) |

Historia wersji treści i audit log działają (wpisy w panelu).

## 3. Analityka i CMP (na żywo)

`scripts/qa-events.mjs`:

| Test | Wynik |
|---|---|
| Pasek cookies przed decyzją | widoczny |
| Zgoda „Akceptuj wszystkie” | zapis `sk_consent_v2`, pasek znika |
| Tagi zewnętrzne przed zgodą | **0 żądań** do GTM/GA/Clarity |
| Po zgodzie | brak fałszywych ID (puste w konfiguracji), Consent Mode update |
| Mapa Google | iframe ładowany automatycznie |
| `phone_click` | zapisany w bazie (strona `/`) |
| `directions_click` | zapisany w bazie |
| Pageview | zapisany w bazie (odsłony) |
| Endpoint `/api/e` | 204; odrzuca brak zgody, obce Origin, boty, złe nazwy zdarzeń |
| Retencja danych | 400 dni (czyszczenie cronem) |

## 4. SEO

- Meta title/description unikalne dla usług, marek, artykułów i realizacji.
- Schema: LocalBusiness/ProfessionalService, WebSite, Service, FAQPage,
  BreadcrumbList, Article, Brand.
- Marki i realizacje w sitemapie; LP i strony techniczne poza sitemap.
- Link opinii → dokładny URL wskazany przez właściciela; linki pojedynczych
  opinii → wizytówka (bez fikcyjnych adresów).
- Zdjęcia z ALT; mapa z linkiem do wizytówki i trasą.

## 5. Bezpieczeństwo (testy wykonane)

| Test | Wynik |
|---|---|
| Dostęp do `/panel/` bez logowania | 302 → login |
| CSRF (POST bez tokenu) | odrzucone (419) |
| SQL injection (parametry GET/POST) | zapytania prepared; brak błędów SQL |
| Path traversal w slugach | odrzucane (`valid_slug`) |
| Upload pliku wykonywalnego | odrzucony (MIME whitelist) |
| SVG ze skryptem | odrzucony |
| Nagłówki bezpieczeństwa | potwierdzone na live: X-Content-Type-Options, Referrer-Policy, Permissions-Policy, X-Frame-Options, HSTS; panel dodatkowo CSP |
| `.git`, `.htaccess`, SQLite | niedostępne przez HTTP (blokada dotfiles + poza webrootem) |
| Sesja | HttpOnly/Secure/SameSite=Strict, idle timeout 30 min, rotacja ID |\n| Limit logowania | 5 prób / 15 min; 6. próba → komunikat „Zbyt wiele prób…” (potwierdzone) |\n| Upload PHP/SVG | plik `.php` odrzucony; poprawny PNG przyjęty i następnie usunięty |\n| CSRF (po zalogowaniu) | POST bez tokenu → 419 |
| 2FA | TOTP dostępne; kod weryfikowany |

Testy destrukcyjne (DoS, pełny pentest) **nie były wykonywane** — środowisko
produkcyjne. Rekomendacje w raporcie końcowym.

## 6. Wydajność

- Strona statyczna, brak zapytań do bazy przy żądaniu.
- Fonty lokalne, CSS ~1 plik na stronę (Astro), JS inline mały (analityka < 5 KB),
  mapy ładowane lazy (iframe), obrazy SVG/WebP.
- LCP/CLS: brak regresji względem poprzedniego audytu (strona bez zmian
  strukturalnych hero; nowe logo PNG ma ustalone wymiary → brak CLS).
- Panel: PHP + SQLite z indeksami, paginacja, brak ciężkich bibliotek.

## 7. Publikacja i rollback

| Test | Wynik |
|---|---|
| Publikacja pierwsza | wersja `20261125-110050`, 133 plików, HTTP 200 |
| Kolejne publikacje | atomowa podmiana symlinka, stare wersje zachowane |
| Rollback | skrypt `sk-switch-release` przetestowany (uprawnienia sudo) |
| Podgląd | build `PREVIEW=1` → `/podglad/` za basic auth |
| Log i status | widoczne w panelu (`publish-state.json`, `last-publish.log`) |

## 8. Znane problemy / do zrobienia

1. Brak ID GA4/GTM/Ads/Clarity — kod gotowy, panel pokazuje „niepodłączone”.
2. Formularz działa i zapisuje zgłoszenia w bazie panelu; brakuje kont SMS/SMTP,
   żeby wysyłać realne powiadomienia (tryb testowy).
3. Brak klucza Google Places API — ocena opinii ręczna.
4. Ceny „… zł” w cenniku do uzupełnienia przez właściciela.
5. Zdjęcia realizacji/usług to ilustracje techniczne — do podmiany na własne.
6. 25 artykułów markowych to szkice (`draft: true`) — do zatwierdzenia.
7. Mapa Google ładuje się przed decyzją cookies — decyzja właściciela, opisana
   w polityce prywatności i AGENTS.md.
8. Brak automatycznej kopii bazy SQLite poza VPS — zalecany cron + backup.

## 9. Testy instrukcji nr 2 (2026-11-25)

Skrypt: `node scripts/qa-inst2.mjs` (Chrome headless przez playwright-core,
lokalny build `pnpm build` + `pnpm preview`).

| Obszar | Testy | Wynik |
|---|---|---|
| Logo i favicony | wysokość logo, proporcje, brak przewijania 1440/390, 8 adresów ikon + manifest | OK |
| Kafelki usług | 9 kafelków, 3 kolumny, domyślnie bez opisu, hover = opis + przyciemnienie, klawiatura, 6 kafelków na mobile, tap rozwija/zwija | OK |
| Ceny | 200-250 zł (2 pozycje), instalacja 250 zł, matryca 600-700 zł, brak „od 200 zł”, brak „kierownik” | OK |
| Myślniki | brak U+2013/U+2014 na stronie głównej, cenniku, poradniku, realizacjach | OK |
| Realizacje | 7 kafelków, 9 filtrów × 3 szerokości, poprawne liczby po kategorii, stan aktywny, 3/2/1 kolumny, linki do podstron | OK |
| Szczegóły realizacji | kontener > 1100 px, 2 kolumny, akapit <= 75ch, mobile 1 kolumna | OK |
| Stopka | 9 marek w 2 kolumnach, brak przewijania; mobile: akordeon domyślnie zwinięty, kontakt widoczny, rozwinięcie marek | OK |
| Mobile | 4,8 ekranu (limit 5), brak poziomego przewijania | OK |

Łącznie **98/98 testów OK**. Formularz zgłoszeń: testy funkcji 133 OK,
endpointów 31 OK, panelu 42 OK (raport agenta, lokalny PHP + php -S, tryb
testowy, bez realnych SMS/e-maili).

## 10. SEO: duplikat artykułu i strony long-tail (2026-11-27)

Zmiany w treści (bez zmian wizualnych):

- usunięty duplikat `src/content/blog/ile-kostuje-naprawa-laptopa.md` (literówka);
  kanon: `/poradnik/ile-kosztuje-naprawa-laptopa/`,
- 301 z `/poradnik/ile-kostuje-naprawa-laptopa/` na poprawny adres w
  `panel/lib/redirects.php` (`redirects_defaults()`) oraz w wygenerowanych
  `public/_redirects`, `public/.htaccess` i `deploy/nginx/sk-redirects.conf`,
- nowe podstrony usług: `/naprawa-laptopow-gamingowych-wroclaw/`,
  `/serwis-komputerowy-wroclaw/`,
- nowe artykuły: `/poradnik/ile-kosztuje-naprawa-plyty-glownej-laptopa/`,
  `/poradnik/laptop-nie-laduje-baterii-co-sprawdzic/`,
- rozbudowane: gniazdo zasilania (gniazdo ładowania: lutowanie vs moduł),
  płyty główne (co wpływa na cenę), czyszczenie (laptop gamingowy),
  odzyskiwanie danych (dysk SSD: TRIM, NVMe, kontrolery, kiedy nie klonować).

Weryfikacja (lokalny build, `SITE_URL` domyślny):

| Test | Wynik |
|---|---|
| `pnpm build` | 70 stron, OK |
| `node scripts/check-prices.mjs` | OK (bez cen na sztywno poza cennikiem) |
| `node scripts/check-seo.mjs` | OK (bez duplikatów title/description, 1 H1, canonical + OG, bez surowych tokenów, nowe strony w sitemapie - 59 adresów) |
| Stary adres artykułu | brak w `dist/`, brak linków w treści, 301 w `_redirects` i `.htaccess` |

Reguła 301 działa z plików serwerowych publikowanych razem z buildem, niezależnie
od bazy panelu. Jeśli wpis ma być widoczny także na liście przekierowań w panelu,
dodaje się go tam ręcznie (Przekierowania) - wygeneruje te same pliki.
