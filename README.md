# Studio-Komp — strona serwisu laptopów + panel CMS

Statyczna strona serwisu laptopów Studio-Komp (Kotlarska 14, Wrocław) zbudowana
w Astro 5, z panelem zarządzania treścią w PHP + SQLite. Publikacja odbywa się
**lokalnie**: komputer buduje stronę i wgrywa gotową wersję na VPS.

- **Działająca wersja:** https://sk.mati-it.pl
- **Panel CMS:** https://sk.mati-it.pl/panel/
- **Dokumentacja dla agentów i właściciela:** [AGENTS.md](AGENTS.md)

## Szybki start

```powershell
pnpm install
pnpm dev            # http://127.0.0.1:4321
pnpm build          # statyczna strona w dist/
pnpm preview        # podgląd builda
pnpm measure        # pomiar mobile (liczba ekranów, overflow)
pnpm qa             # Lighthouse/axe (jeśli skonfigurowane)

# publikacja na VPS (build lokalny + atomowa podmiana wersji)
pnpm publish:remote    # z kolejki panelu
pnpm publish:now       # natychmiast
pnpm publish:preview   # podgląd wersji roboczych → /podglad/
pnpm publisher:install # zadanie Windows co 5 min (automatyczna publikacja)
```

Wymagania: Node.js 20+ / 22, pnpm 9+, Git, OpenSSH, klucz SSH do VPS.

## Co jest w środku

- **Strona główna** — hero „Twój laptop w dobrych rękach.”, statystyki (99%,
  550+, 2013, 0 zł*), marki, opinie Google, usługi z ilustracjami, cennik,
  realizacje, FAQ, kontakt z mapą ładowaną automatycznie.
- **16 podstron usług SEO** + **10 podstron marek** + **realizacje z podstronami**
  (kategorie, galerie, slider przed/po).
- **Poradnik** — 15 artykułów publicznych + 27 szkiców markowych (do zatwierdzenia).
- **Cennik, opinie, kontakt, o serwisie, polityka prywatności, LP Google Ads**.
- **Panel CMS** — treści, media, realizacje, przekierowania, ustawienia,
  użytkownicy, audyt, publikacja i rollback.
- **Status naprawy dla klienta** - publiczna strona `/status/<token>/` wysyłana
  SMS-em po przyjęciu sprzętu i po zmianie statusu na „gotowy do wydania”;
  pokazuje numer zgłoszenia, sprzęt, etap naprawy z datami i publiczną notatkę
  serwisu, bez danych osobowych i cen.
- **Analityka** — własny pomiar (odsłony + 14 zdarzeń, urządzenia, źródła, UTM)
  + gotowy Consent Mode v2 pod GA4/GTM/Ads/Clarity.
- **SEO techniczne** — sitemap, robots, canonical, OG, schema (LocalBusiness,
  Service, FAQPage, Article, Breadcrumb), 301 ze starych adresów.
- **Bezpieczeństwo** — logowanie z 2FA, CSRF, limity, bezpieczne sesje,
  walidacja uploadów, prepared statements, nagłówki.

## Struktura

```
src/            Astro: pages, components, content (usługi/blog/marki/strony), data, styles
public/         logo, ikony, obrazy, .htaccess, _redirects
panel/          panel CMS (PHP 8.3 + SQLite) — serwowany pod /panel/
deploy/         publikacja lokalna + skrypty VPS + konfiguracja nginx
scripts/        QA: zrzuty ekranu, pomiary, testy panelu i analityki
docs/           wdrożenia, design, QA
AGENTS.md       pełna dokumentacja techniczna i future goals
```

## Dokumentacja

- [AGENTS.md](AGENTS.md) — architektura, panel, analityka, bezpieczeństwo, SEO,
  future goals (status napraw + SMS), historia zmian.
- [docs/wdrozenie-vps.md](docs/wdrozenie-vps.md) — VPS, tunnel, publikacja, rollback.
- [docs/status-klienta.md](docs/status-klienta.md) - publiczna strona statusu
  naprawy (`/status/<token>/`): token, etapy, SMS do klienta, panel, nginx.
- [docs/github-repozytoria.md](docs/github-repozytoria.md) - podział projektu na
  dwa repozytoria GitHub (strona serwisu i strona dla klienta) i komendy push.
- [docs/edycja.md](docs/edycja.md) — instrukcja obsługi panelu dla właściciela.
- [docs/wdrozenie-hosting-www.md](docs/wdrozenie-hosting-www.md) — pakiet na
  hosting klasyczny (ZIP, podkatalog /sk/).
- [docs/logo-i-design.md](docs/logo-i-design.md) — logo, paleta, typografia.
- [docs/qa-wyniki.md](docs/qa-wyniki.md) — wyniki testów i audytów.
