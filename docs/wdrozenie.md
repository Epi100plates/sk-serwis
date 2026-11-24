# Wdrożenie — środowisko testowe i publikacja

Domena i DNS pozostają bez zmian: **studio-komp.pl**. Obecna strona działa na hostingu LiteSpeed (shared hosting, PHP + `.htaccess`). Nowa strona to statyczny zestaw plików — nie wymaga PHP, bazy danych ani VPS.

## Co jest potrzebne

- Node.js 20+ i pnpm (`npm i -g pnpm`) — tylko do zbudowania strony na komputerze.
- Dostęp FTP/SFTP do obecnego hostingu (lub panel plików w hostingu).
- Do wariantu z panelem CMS: konto GitHub + Netlify lub Cloudflare Pages (darmowe).

## 1. Środowisko testowe (bez zmian DNS i bez ruszania starej strony)

### Wariant A — podgląd lokalny (już działa)

```powershell
pnpm install
pnpm dev
# http://127.0.0.1:4321
```

To najszybszy sposób, żeby zobaczyć i zaakceptować stronę. Działa bez internetu poza pobraniem paczek.

### Wariant B — podkatalog na obecnym hostingu

1. Zbuduj wersję pod adres `https://studio-komp.pl/nowa/`:

```powershell
$env:BASE_PATH="/nowa/"
pnpm build
Remove-Item Env:BASE_PATH
```

2. Wgraj **zawartość** katalogu `dist/` (nie sam katalog) do `public_html/nowa/` przez FTP.
3. Wejdź na `https://studio-komp.pl/nowa/`.

W trybie podkatalogu strona automatycznie dodaje `noindex, nofollow` i nie konkuruje ze starą stroną w Google. Linki, CSS i obrazy działają poprawnie dzięki `BASE_PATH`.

> Uwaga: nie wgrywaj wersji testowej do `public_html/` (katalogu głównego), dopóki nie zdecydujesz się na publikację. Stara strona ma tam zostać nietknięta.

### Wariant C — szybki podgląd na darmowym hostingu

Zbuduj (`pnpm build`), a następnie wgraj katalog `dist/` przez Netlify Drop (app.netlify.com/drop) lub Cloudflare Pages. Działa bez domeny i bez zmian DNS — otrzymasz adres testowy.

### Wariant D — VPS + Cloudflare Tunnel (wdrożony)

Strona jest już opublikowana pod **https://sk.mati-it.pl** na VPS (`root@olga322.mikrus.xyz:10322`)
przez Cloudflare Tunnel. To wersja do oglądania i akceptacji — domena `studio-komp.pl`
i stara strona pozostają nietknięte.

Aktualizacja jednym poleceniem:

```powershell
pnpm deploy
```

Pełna dokumentacja (architektura, usługi systemd, logi, rollback, przejście na docelową
domenę): [wdrozenie-vps.md](wdrozenie-vps.md).

## 2. Publikacja produkcyjna

### Wariant 1 — obecny hosting, wgranie plików (bez zmiany DNS)

Najprostsza droga, jeśli chcesz zostać na obecnym hostingu.

1. **Zrób kopię zapasową starej strony** — pobierz całe `public_html/` przez FTP do folderu `backup-stara-strona/` na dysku. To warunek szybkiego wycofania zmian.
2. Zbuduj stronę: `pnpm build`.
3. Wgraj zawartość `dist/` do `public_html/`, nadpisując pliki. Plik `.htaccess` z `dist/` zawiera przekierowania 301 i cache.
4. Sprawdź:
   - `https://studio-komp.pl/` — strona główna,
   - `https://studio-komp.pl/o-firmie.html` — powinno przekierować na `/o-serwisie/`,
   - `https://studio-komp.pl/sitemap-index.xml`,
   - telefon i przycisk „Zjazd” na telefonie,
   - formularz (jeśli włączony — patrz sekcja 4).
5. W Google Search Console dodaj `sitemap-index.xml` i sprawdź indeksowanie po 48 h.

**Edycja po wdrożeniu:** zmiany wprowadzasz w plikach lokalnie (albo w panelu `/edycja/` na wdrożonej stronie) i publikujesz przez `pnpm deploy` — patrz [Edycja treści](edycja.md).

### Wariant 2 — Netlify/Cloudflare Pages + GitHub (zalecany, jeśli chcesz CMS)

1. Wrzuć projekt do repozytorium GitHub (prywatnego).
2. Netlify: „Add new site” → „Import from Git” → wybierz repo. Build command: `pnpm build`, publish directory: `dist`. Plik `netlify.toml` już to ustawia.
3. Panel `/edycja/` jest już wdrożony na VPS (decap-server + hasło) i nie wymaga Netlify Identity. Jeśli jednak hostujesz na Netlify, musisz mieć osobny backend CMS (np. Netlify Identity + Git Gateway) — konfiguracja w `public/edycja/config.yml`.
4. Po akceptacji strony podmień domenę na Netlify (rekordy DNS u rejestratora — dopiero na tym etapie). Do tego czasu DNS zostaje bez zmian.
5. Cloudflare Pages: alternatywa zamiast Netlify (build `pnpm build`, output `dist`); panel CMS wymaga wtedy Git Gateway z Netlify albo innego backendu (np. Pages CMS).

## 3. Przekierowania 301 (kluczowe dla SEO)

Stara strona ma indeksowane adresy `.html`. Nowa ma czyste adresy. Przekierowania są przygotowane w dwóch plikach:

- `public/.htaccess` — dla obecnego hostingu LiteSpeed/Apache (działa po wgraniu),
- `public/_redirects` — dla Netlify/Cloudflare Pages.

Mapa przekierowań znajduje się w `docs/audyt-i-strategia.md`. Po publikacji sprawdź pojedyncze adresy (np. `/cennik.html`, `/oferta/`) i upewnij się, że zwracają `301` na nowy adres.

## 4. Formularz kontaktowy

Formularz na `/kontakt/` działa w jeden z dwóch sposobów:

1. **Netlify Forms** (domyślnie, zero konfiguracji) — jeśli strona jest na Netlify, zgłoszenia trafiają na panel Netlify (Forms → `zgloszenie-naprawy`).
2. **Web3Forms** (dowolny hosting) — załóż darmowy klucz na web3forms.com i wpisz go w `src/data/site.json` w polu `form.accessKey` (albo w CMS: Dane firmy → Formularz → Web3Forms access key). Po uzupełnieniu klucza formularz wysyła zgłoszenia na e-mail i nie wymaga Netlify.

Jeśli nie chcesz formularza, pozostaw klucz pusty i hostuj na LiteSpeed — formularz będzie widoczny, ale wysyłka nie zadziała. W takim wypadku lepiej go ukryć: w `src/components/kontakt/index.astro` usuń `<RepairForm />` albo zastąp sekcję blokiem z telefonem.

## 5. Analityka (GA4 / GTM / Ads / Search Console)

1. W `src/data/site.json` (lub CMS → Dane firmy → Analityka) wpisz:
   - `gtm`: `GTM-XXXXXXX` — jeśli używasz Google Tag Manager (zalecane),
   - `ga4`: `G-XXXXXXXXXX` — jeśli bez GTM,
   - `adsConversionSendTo`: `AW-XXXXXXXXX/xxxxxxxx` — jeśli chcesz wysyłać konwersję z formularza.
2. Skrypty ładują się **dopiero po zgodzie** użytkownika (Consent Mode v2, domyślnie `denied`).
3. Zdarzenia wysyłane do `dataLayer`: `call_click` (klik w telefon), `directions_click` (nawigacja), `cta_click` (przyciski), `form_submit`, `generate_lead`.
4. W GTM utwórz wyzwalacze na te zdarzenia i oznacz `call_click` oraz `generate_lead` jako konwersje w Google Ads.
5. Search Console: dodaj domenę (weryfikacja DNS TXT lub plikiem), prześlij `sitemap-index.xml`.

## 6. Checklista przed publikacją

- [ ] Kopia zapasowa starej strony wykonana.
- [ ] Telefon i adres zgodne z wizytówką Google (NAP).
- [ ] Ocena i liczba opinii aktualne (`site.json`).
- [ ] Ceny w `cennik.json` potwierdzone przez właściciela.
- [ ] Podmienione grafiki realizacji (opcjonalnie — do czasu zdjęć działają ilustracje).
- [ ] Formularz przetestowany (jeśli włączony).
- [ ] Podglądy na telefonie: iPhone + Android.
- [ ] Sprawdzone 301 dla `/cennik.html`, `/oferta/`, `/o-firmie.html`.
- [ ] Wgrane `robots.txt`, `sitemap-index.xml`, `.htaccess`.
- [ ] GA4/GTM podpięte, zdarzenia widoczne w DebugView.
- [ ] SSL działa (https, brak ostrzeżeń o mieszanej treści).
- [ ] Polityka prywatności podlinkowana w stopce i przy formularzu.
- [ ] Backup `dist/` zachowany.

## 7. Wycofanie zmian (rollback)

Jeśli coś pójdzie nie tak: skopiuj z powrotem pliki z `backup-stara-strona/` do `public_html/`. Stara strona wróci natychmiast, bez zmian DNS.

## 8. Konserwacja

- Aktualizacja treści: panel `/edycja/` na wdrożonej stronie albo edycja plików; publikacja: `pnpm deploy` (build lokalny + wgranie na VPS).
- Raz w miesiącu: sprawdź opinie w Google, zaktualizuj liczbę w `site.json`.
- Raz na kwartał: `pnpm update astro` (testowo), sprawdź `pnpm build`.
- Kopie zapasowe: trzymaj ostatnią wersję `dist/` i repozytorium Git.
