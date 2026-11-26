# Status naprawy dla klienta - dokumentacja

Dokument opisuje publiczną stronę śledzenia naprawy pod adresem
`https://<site_url>/status/<token>/` (obecnie testowo
`https://sk.mati-it.pl/status/<token>/`, docelowo krótko
`https://status.mati-it.pl/<token>`): token, mapowanie statusów zgłoszeń na
etapy widoczne dla klienta, SMS-y z linkiem, panel, konfigurację nginx,
integrację z aplikacją serwisową i wdrożenie na VPS.

Ostatnia aktualizacja: 2026-11-26.

---

## 1. Cel i zakres, słownik

Cel: klient po przyjęciu sprzętu dostaje SMS-em link do strony, na której sam
sprawdza postęp naprawy - bez dzwonienia do serwisu i bez logowania. Drugi SMS
idzie w chwili zmiany statusu na „gotowy do wydania”.

Zakres: strona publiczna, token, mapowanie statusów, treści SMS, ekrany panelu,
konfiguracja nginx, wdrożenie i diagnostyka. Poza zakresem: formularz zgłoszeń
(`docs/formularz-zgloszen.md`) i publikacja strony (`docs/wdrozenie-vps.md`).

| Pojęcie | Znaczenie |
|---|---|
| Zgłoszenie | wiersz w tabeli `tickets` w bazie panelu (`panel.sqlite`), numer `SK-ROK-000123` (formularz) albo `SRV-ROK-000000` (zlecenie z aplikacji serwisowej) |
| Etap klienta | jeden z etapów widocznych publicznie: Przyjęty, W trakcie naprawy, Gotowy do wydania, Odebrany, Anulowane |
| Token śledzenia | podpisany adres zgłoszenia w postaci `<id>.<wersja>.<podpis>` (id i wersja w base36, podpis 22 znaki; stary format dziesiętny z podpisem 43 znaki nadal działa); sekret, który jest jednocześnie adresem strony |
| Publiczna notatka | krótki tekst serwisu widoczny na stronie statusu; bez danych osobowych, bez cen |
| Zlecenie zewnętrzne | zlecenie z aplikacji `serwis.mati-it.pl`, powiązane ze zgłoszeniem kolumną `tickets.external_ref` (`SRV-ROK-000000`) |
| Tryb testowy | konfiguracja SMS bez realnej wysyłki i bez kosztów (domyślna) |

Zasady nadrzędne:

1. Strona publiczna pozostaje statyczna (Astro SSG). Stronę statusu renderuje
   **PHP po stronie serwera**, bo dane muszą pochodzić z bazy panelu w chwili
   żądania - nie dodajemy bazy do statycznego frontu.
2. Link jest sekretem: kto ma link, widzi status. Dlatego token jest podpisany
   kluczem serwera, a strona nie jest indeksowana ani cache'owana.
3. Na stronie statusu nie ma danych osobowych ani cen.

---

## 2. Architektura przepływu

```
Klient wypełnia formularz na stronie (Astro SSG)
  |  POST /api/zgloszenie
  v
panel/api/form.php  ->  panel/lib/tickets.php  ->  SQLite panelu (tickets)
  |                          |
  |                          +-> token śledzenia: <id>.<wersja>.<podpis HMAC-SHA256>
  |                              podpis liczony kluczem z data/tracking.key
  |                          +-> notifications: szablon client_accepted (SMS do klienta)
  v
panel/bin/tickets-worker.php (cron co minutę, flock)
  |  SMS: panel/lib/sms.php (SMSAPI / SerwerSMS, domyślnie tryb testowy)
  v
SMS do klienta z linkiem: https://<site_url>/status/<token>/
  |
  v
Klient otwiera link
  |  GET /status/<token>/                  -> HTML w stylu marki
  |  GET /status/<token>/?format=json      -> {"ok":true,"status":{...}}
  v
nginx-sk: location ^~ /status/  ->  PHP-FPM (pool skcms)
  v
panel/api/status.php -> panel/lib/tickets.php (ticket_public_view) -> panel/views/status.php
  |
  v
Pracownik zmienia status zgłoszenia w panelu (np. done)
  -> tickets_change_status() -> kolejka notifications: szablon client_ready
  -> SMS "sprzęt gotowy do wydania" z tym samym linkiem
  -> ten sam link pokazuje etap "Gotowy do wydania"
```

Drugi kanał wejścia (bez formularza i bez sesji panelu): aplikacja warsztatowa
`serwis.mati-it.pl` (Laravel) jest źródłem prawdy o zleceniu i wysyła jego stan
podpisanym żądaniem. Szczegóły w sekcji 11.

```
Aplikacja serwisowa (serwis.mati-it.pl, Laravel)
  |  POST /api/service-sync.php   HMAC-SHA256, timestamp ±300 s
  v
panel/api/service-sync.php -> tickets_sync_external() -> SQLite (tickets.external_ref)
  |  number = numer zlecenia (SRV-ROK-000000), source_page = 'serwis'
  |  tworzy / aktualizuje zgłoszenie, link i kolejkę SMS (te same szablony)
  v
{"ok":true,"url":"https://status.mati-it.pl/<token>","number":"SRV-...","status":"new","sms_queued":["client_accepted"]}
```

Pliki:

| Plik | Rola |
|---|---|
| `panel/lib/db.php` | tabele `tickets` (kolumny `public_note`, `status_token_version`, `external_ref`), `status_rate`, `sync_rate` (idempotentne `db_init`) |
| `panel/lib/tickets.php` | `ticket_client_stage()`, token śledzenia, `ticket_public_view()`, limit tempa, kolejka SMS do klienta, mapowanie i synchronizacja z aplikacją (`tickets_sync_*`) |
| `panel/api/status.php` | publiczny endpoint `GET /status/<token>/` (HTML, JSON, 404, 429) |
| `panel/api/service-sync.php` | `POST /api/service-sync.php` - podpisany HMAC-em webhook z aplikacji serwisowej (sekcja 11) |
| `panel/views/status.php` | widok strony statusu w stylu marki (HTML, bez JS, jeden arkusz `/tracking/status.css?v=1`) |
| `panel/lib/sms.php` | dostawcy SMS + tryb testowy (bez zmian względem formularza) |
| `panel/bin/tickets-worker.php` | worker kolejki powiadomień (cron co minutę) |
| `panel/bin/self-check-status.php` | samokontrola: mapowanie etapów, tokeny, kolejka SMS, model widoku (katalog tymczasowy) |
| `panel/bin/self-check-service-sync.php` | samokontrola integracji: podpis, idempotencja, mapowanie z aplikacji, krótkie tokeny, endpoint HTTP (katalog tymczasowy) |
| `panel/bin/sms-test.php` | ręczny test dostawcy SMS po wyłączeniu trybu testowego (`--to=...`, bez `--send` tylko podgląd) |
| `panel/pages/zgloszenia.php` | panel: karta „Śledzenie naprawy (dla klienta)” - link, nowy link, publiczna notatka, badge „Z serwisu” |
| `panel/pages/settings.php` | Ustawienia -> Formularz: sekcja „SMS do klienta (śledzenie naprawy)” |
| `public/tracking/status.css` + `public/tracking/fonts/*` | statyczne assety strony statusu (serwowane z roota, nie przez PHP) |
| `deploy/nginx/sk.mati-it.pl.conf` | publiczne `location` dla `/status/` i `/api/service-sync.php` |

Kolejność jest istotna: zgłoszenie, jego historia, wpis w kolejce powiadomień do
serwisu i SMS do klienta powstają w **jednej transakcji**, więc nie ma stanu
„zgłoszenie jest, ale linku nie ma”.

---

## 3. Mapowanie statusów

Mapowanie jest **jedno**, w `panel/lib/tickets.php`: stała `CLIENT_STAGE_BY_STATUS`
i funkcja `ticket_client_stage()`. Strona publiczna, JSON i SMS korzystają
wyłącznie z nich - nigdzie indziej nie ma listy etapów.

| `tickets.status` | Etap klienta | Etykieta (`CLIENT_STAGES`) | Co widzi klient |
|---|---|---|---|
| `new`, `read` | `accepted` | Przyjęty | sprzęt przyjęty, data i godzina przyjęcia, numer zgłoszenia |
| `in_progress` | `in_progress` | W trakcie naprawy | „diagnoza i naprawa w toku” - bez szczegółów technicznych |
| `done` | `ready` | Gotowy do wydania | sprzęt gotowy, zaproszenie do odbioru |
| `closed` | `picked_up` | Odebrany | stan końcowy po wydaniu sprzętu |
| `cancelled` | `cancelled` | Anulowane | naprawa anulowana - bez etapów „Gotowy do wydania” i „Odebrany” |

`ticket_public_view()` buduje z tego listę kroków (`steps`) dla widoku:

- do etapu `picked_up` lista ma **3 kroki** (Przyjęty, W trakcie naprawy, Gotowy
  do wydania), po zamknięciu zgłoszenia dochodzi czwarty krok „Odebrany”,
- przy anulowaniu lista ma **3 kroki** (Przyjęty, W trakcie naprawy, Anulowane);
  kroki bez wpisu w historii są oznaczone jako `todo`, więc anulowanie przed
  rozpoczęciem naprawy nie udaje jej przebiegu, a etap „Odebrany” nigdy się nie
  pojawia,
- krok ma stan `done` (miniony), `current` (bieżący) albo `todo` (przyszły),
- data etapu „Przyjęty” pochodzi z `tickets.created_at` (sprzęt przyjęto
  w chwili utworzenia zgłoszenia), a pozostałych etapów - z pierwszego wpisu
  w `ticket_history` o statusie mapowanym na ten etap. Dzięki temu data
  „Przyjęty” nie ginie, gdy historia zaczyna się od późniejszego statusu
  (zgłoszenie zsynchronizowane z aplikacji już jako „gotowy do wydania”),
- zgłoszenie zsynchronizowane w dalszym statusie dostaje w historii dwa wpisy:
  `new` z datą przyjęcia z aplikacji (`accepted_at`) i bieżący status z czasem
  synchronizacji - inaczej „Gotowy do wydania” nie miałby daty.

Uwagi:

- Klient widzi **trzy etapy procesu** i ewentualny stan końcowy „Odebrany”.
  Wszystko pomiędzy „Przyjęty” a „Gotowy do wydania” pokazuje się jako
  „W trakcie naprawy”.
- Zmiana etapów pośrednich w bazie (np. dodanie statusu `awaiting_parts`) **nie
  wymaga zmian na stronie klienta** - wystarczy dopisać nowy status do
  `CLIENT_STAGE_BY_STATUS` z wartością `in_progress`.
- Statusy wewnętrzne panelu (`new`, `read`, `in_progress`, `done`, `closed`,
  `cancelled`) są etykietowane z `TICKET_STATUSES`; `cancelled` („Anulowane”)
  ma własny etap klienta, bo anulowanie nie jest ani gotowością, ani wydaniem.
- Pełna historia statusów (`ticket_history`) jest wewnętrzna - na stronie
  statusu widać tylko etapy i ich daty, nie notatki pracowników.

### Mapowanie statusów aplikacji serwisowej

Aplikacja `serwis.mati-it.pl` ma własne nazwy statusów; mapowanie na
`tickets.status` jest w jednym miejscu w kodzie: `TICKET_SYNC_STATUS_MAP`
i `tickets_sync_status_map()` (`panel/lib/tickets.php`).

| Status aplikacji | `tickets.status` | Etap klienta |
|---|---|---|
| `pending` | `new` | Przyjęty |
| `in_progress` | `in_progress` | W trakcie naprawy |
| `awaiting_parts` | `in_progress` | W trakcie naprawy |
| `ready_for_pickup` | `done` | Gotowy do wydania (SMS `client_ready`) |
| `released` | `closed` | Odebrany |
| `cancelled` | `cancelled` | Anulowane |

Nieznany status jest **odrzucany** (400 `invalid_status`), a nie mapowany
domyślnie - literówka w aplikacji nie może po cichu zmienić stanu naprawy.

---

## 4. Adresy i odpowiedzi endpointu

Token parsowany jest z `REQUEST_URI` (prefiks `/status/`, końcowy `/` opcjonalny),
dlatego nginx przekazuje żądanie do `panel/api/status.php` przez
`fastcgi_param SCRIPT_FILENAME` - bez `try_files` i bez przepisywania adresu.
Gdy w konfiguracji panelu jest `status_base_url` (np. `https://status.mati-it.pl`),
`tickets_tracking_token_from_request()` przyjmuje token także w rootcie
(`/<token>`) - ale **tylko na skonfigurowanym hoście**, żeby ścieżki CMS-a nie
były brane za token.

| Żądanie | Odpowiedź |
|---|---|
| `GET /status/{token}/` | 200, HTML w stylu marki |
| `GET /status/{token}` | to samo co wyżej (bez końcowego ukośnika) |
| `HEAD /status/{token}/` | 200, te same nagłówki bez treści (podgląd nagłówków, `curl -I`) |
| `GET /status/{token}/?format=json` | 200, `application/json` |
| `GET /status/{token}/` z `Accept: application/json` | 200, JSON (to samo) |
| `HEAD /status/{token}/?format=json` | 200, nagłówki JSON bez treści |
| nieprawidłowy, unieważniony lub zarchiwizowany token | 404, strona z komunikatem „Ten link jest nieaktywny lub wygasł” (także dla HEAD) |
| metoda inna niż GET/HEAD (np. POST, PUT) | 403 z nginx (`limit_except GET`), a gdy żądanie dojdzie do PHP - 405 z `Allow: GET, HEAD` |
| przekroczony limit tempa | 429 (także dla HEAD); HTML dostaje `Retry-After: 60`, JSON tylko kod i treść |

HEAD jest obsługiwany: PHP wysyła nagłówki i kod (200/404/429), ale nie treść,
więc `curl -I` jest poprawnym testem wdrożenia (patrz sekcja 8). 405 dostają
wyłącznie metody inne niż GET i HEAD, z nagłówkiem `Allow: GET, HEAD`.

Odpowiedź 404 jest identyczna dla tokenu nieistniejącego, unieważnionego
i zgłoszenia z `archived = 1` - nie ujawniamy, czy zgłoszenie istnieje. 404 dla
złego tokenu renderuje widok statusu z komunikatem, a nie stronę `/404.html`
(nginx nie podmienia odpowiedzi PHP, bo `fastcgi_intercept_errors` jest domyślnie
wyłączony).

Przykład `?format=json` (odpowiedź zweryfikowana lokalnie, bez danych osobowych):

```json
{
  "ok": true,
  "status": {
    "number": "SK-2026-000123",
    "device": "Lenovo ThinkPad T480",
    "received_date": "20.11.2026",
    "received_time": "11:20",
    "stage": "in_progress",
    "stage_label": "W trakcie naprawy",
    "stage_index": 2,
    "stage_total": 3,
    "steps": [
      {"key": "accepted", "label": "Przyjęty", "state": "done", "at": "20.11.2026, 11:20", "caption": "Sprzęt przyjęty w serwisie."},
      {"key": "in_progress", "label": "W trakcie naprawy", "state": "current", "at": "22.11.2026, 09:05", "caption": "Diagnoza i naprawa w toku."},
      {"key": "ready", "label": "Gotowy do wydania", "state": "todo", "at": "", "caption": "Sprzęt gotowy - zapraszamy do odbioru."}
    ],
    "public_note": "Czekamy na części - poinformujemy SMS-em.",
    "updated_at": "22.11.2026, 09:05",
    "service": {
      "name": "Studio-Komp",
      "phone": "500 613 500",
      "phone_href": "tel:+48500613500",
      "address": "Kotlarska 14, 50-150 Wrocław",
      "hours": "Poniedziałek - piątek: 7:00 - 15:00",
      "url": "https://sk.mati-it.pl"
    }
  }
}
```

```json
{"ok":false,"error":"not_found"}
{"ok":false,"error":"rate_limited"}
{"ok":false,"error":"method_not_allowed"}
```

Pola: `number`, `device`, `received_date`, `received_time`, `stage`,
`stage_label`, `stage_index`, `stage_total`, `steps[]`, `public_note`,
`updated_at`, `service{}`. Nigdy: `name`, `phone`, `problem`, `cena`, `notes`,
`ip_hash`, `user_agent`.

Dane `service` pochodzą z `src/data/site.json` (nazwa, telefon, adres, godziny),
więc zmiana danych firmy w panelu zmienia też stopkę strony statusu.

---

## 5. Bezpieczeństwo i prywatność

| Obszar | Rozwiązanie |
|---|---|
| Token | `<id>.<wersja>.<podpis>` (id i wersja w base36, podpis 22 znaki); podpis to pierwsze 16 B HMAC-SHA256 (base64url) z `ticket\|<id>\|<wersja>`, liczony kluczem serwera - wyciek samej bazy nie daje linków. Stary format (liczby dziesiętne, podpis 43 znaki) jest nadal akceptowany |
| Klucz | `tracking_secret` w konfiguracji panelu albo plik `data/tracking.key` (64 znaki hex, uprawnienia 0600, poza repozytorium i poza webrootem) |
| Weryfikacja | wyrażenie regularne na format tokenu (oba warianty), zgodność `wersji` z `tickets.status_token_version`, `hash_equals()` na podpisie |
| Wyszukiwarki | `X-Robots-Tag: noindex, nofollow`; strona nie jest generowana przez Astro, więc nie trafia do `sitemap-0.xml` |
| Cache | `Cache-Control: no-store`; Cloudflare nie cache'uje tej ścieżki (HTML z `no-store`), a `location` w nginx nie ma cache |
| Referrer | `Referrer-Policy: no-referrer` - token z adresu nie wycieka do innych stron |
| CSP | `Content-Security-Policy` blokuje wszystko poza własnym CSS, fontami i obrazami - brak JS, brak inline, brak CDN (szczegóły niżej) |
| Metody | nginx dopuszcza GET i HEAD; PHP zwraca 405 tylko dla innych metod (`Allow: GET, HEAD`) |
| Limit tempa | w PHP: tabela `status_rate`, 30 żądań/min na hash IP (`security_ip_hash()`, sól w `data/ip_salt.key`), przekroczenie = 429 |
| Dane osobowe | brak imienia, telefonu, adresu klienta, ceny, notatek wewnętrznych |
| Unieważnianie | nowy token w panelu (`tickets_tracking_token($id, true)`) zmienia wersję; stary link zwraca 404 |
| Logi | `access_log off` dla `/status/` w nginx - token jako sekret nie trafia do logów dostępu |
| Indeksowanie | brak linków do `/status/` ze strony publicznej, brak wpisu w `robots.txt`, brak w sitemapie |

### Token - jak to działa i dlaczego tak

Token nie jest losowym ciągiem zapisanym w bazie, a **podpisem** danych
zgłoszenia:

```
token = base36(<id zgłoszenia>) . "." . base36(<wersja>) . "." . base64url(HMAC-SHA256("ticket|<id>|<wersja>")[0..16])
```

Przykład nowego, krótkiego tokenu: `5.tp47iu.uLI6Iou23tiCz5uGYRpMUA` (32 znaki
zamiast 53). Stary format - `<id dziesiętnie>.<wersja dziesiętnie>.<podpis 43
znaki>` - jest nadal akceptowany w `tickets_find_by_tracking_token()`, bo linki
wysłane wcześniej SMS-em muszą działać. Nowe linki powstają wyłącznie w krótkim
formacie.

- w bazie leży tylko `status_token_version` (liczba), nie token i nie jego hash,
- podpis da się policzyć wyłącznie znając klucz serwera (`data/tracking.key`),
  więc kopia `panel.sqlite` nie pozwala otworzyć żadnego linku,
- link jest odtwarzalny w panelu, bo nie trzeba go przechowywać,
- unieważnienie = nowa wersja (nowy podpis), stara wersja przestaje pasować,
- 16 B (128 bitów) podpisu wystarcza na link jednorazowy dla klienta, a skrócenie
  adresu o 21 znaków pozwala zmieścić cały SMS w jednej części GSM-7.

Alternatywa (losowy token i jego SHA-256 w kolumnie bazy) wymaga dodatkowej
kolumny z hashem i nie pozwala odtworzyć linku w panelu bez jego zapisania;
wybrano podpis, bo link jest odtwarzalny w każdej chwili, a sama baza nie
wystarcza do jego policzenia.

Uwaga dla wdrożenia: **klucz `data/tracking.key` jest częścią sekretów** - jego
utrata unieważnia wszystkie wysłane linki (klient dostaje wtedy nowy link z
panelu), a jego wyciek pozwala policzyć tokeny dla znanych numerów zgłoszeń.
Klucz trzeba kopiować razem z bazą i nie wrzucać do repozytorium.

### Rate limiting a nginx

nginx nie ma `limit_req` dla `/status/`. Limit jest w PHP (`status_rate`), bo
tylko PHP zna hash IP i potrafi zwrócić spójny komunikat 429 (HTML lub JSON).
Ewentualne `limit_req` w nginx byłoby warstwą dodatkową - wtedy nie duplikować
komunikatów (nginx zwracałby własną stronę 429). Stare wpisy `status_rate`
(t > 1 dzień) są czyszczone okazjonalnie przy żądaniach.

### Nagłówki i CSP

`add_header` w `location` **kasuje** dziedziczenie nagłówków z poziomu `server`,
dlatego blok `/status/` wypisuje wszystkie krytyczne nagłówki sam:
`Cache-Control`, `X-Robots-Tag`, `Referrer-Policy`, `X-Content-Type-Options`,
`X-Frame-Options`, `Strict-Transport-Security`. PHP wysyła te same wartości dla
`Cache-Control`, `X-Robots-Tag`, `Referrer-Policy`, `X-Frame-Options`
(`SAMEORIGIN`) i CSP - wartości muszą być zgodne, bo przeglądarka widzi oba
nagłówki.

`panel/api/status.php` ustawia politykę bezpieczeństwa treści (CSP):

```
Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self';
  img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'
```

Co z tego wynika dla strony statusu:

- **brak JavaScriptu** - `default-src 'none'` blokuje skrypty, `connect-src`,
  `frame-src` i `media-src`; strona nie ma prawa załadować żadnego `.js`,
- **styl wyłącznie z `/tracking/status.css`** (`style-src 'self'`) - inline
  `<style>` i atrybuty `style="..."` są blokowane, podobnie style z CDN,
- **fonty wyłącznie z `/tracking/fonts/`** (`font-src 'self'`), obrazy z tej
  samej domeny albo `data:` (`img-src 'self' data:`),
- `base-uri 'none'` i `form-action 'none'` - brak formularzy i brak możliwości
  podmiany bazy adresów; `frame-ancestors 'self'` zgadza się z
  `X-Frame-Options: SAMEORIGIN`,
- **konsekwencja dla strony awaryjnej**: `status_fail_page()` (429, brak widoku)
  rysuje własne inline `<style>`, więc CSP je zablokuje i komunikat pokaże się
  bez stylów - treść i telefon zostają czytelne, ale wygląd nie jest firmowy.

Wszystkie assety są lokalne (bez CDN), więc CSP nie wymaga żadnych wyjątków.
Dodanie skryptu, inline'owego stylu, iframe, mapy albo zewnętrznego fontu do
strony statusu wymaga zmiany polityki w `panel/api/status.php` - świadomie tego
nie robimy.

### RODO

- Administrator: Studio-Komp, Kotlarska 14, 50-150 Wrocław.
- Cel: informowanie klienta o postępie naprawy jego sprzętu.
- Podstawa: art. 6 ust. 1 lit. b i f RODO (realizacja umowy, uzasadniony
  interes informacyjny).
- Zakres na stronie: numer zgłoszenia, sprzęt, daty, etap, publiczna notatka,
  dane kontaktowe firmy.
- Link jest przekazywany wyłącznie klientowi (SMS na numer podany w formularzu).
- Retencja: jak dla zgłoszeń (12 miesięcy od zamknięcia); zgłoszenie z
  `archived = 1` nie jest dostępne publicznie nawet z poprawnym linkiem.

---

## 6. SMS do klienta

Dwa szablony, oba wysyłane przez istniejącą kolejkę `notifications` i worker
cron `panel/bin/tickets-worker.php` (bez nowej infrastruktury):

| Szablon | Kiedy | Domyślna treść |
|---|---|---|
| `client_accepted` | przy przyjęciu sprzętu (`tickets_create`) | `Studio-Komp: przyjelismy sprzet ({sprzet}). Status naprawy: {link}` |
| `client_ready` | przy przejściu statusu na `done` | `Studio-Komp: {sprzet} jest gotowy do odbioru. Kotlarska 14, pon.-pt. 7:00-15:00. Status: {link}` |

`client_ready` jest kolejkowany tylko przy **zmianie** statusu na `done`
(ponowne zapisanie `done` nie tworzy drugiego SMS-a). `client_accepted` powstaje
razem ze zgłoszeniem, w tej samej transakcji. Te same dwa szablony obsługują
zlecenia z aplikacji serwisowej (sekcja 11): `client_accepted` przy utworzeniu
zgłoszenia w CMS, `client_ready` przy pierwszym przejściu na `done`.

Placeholdery (obsługiwane przez `tickets_client_sms_message()`):

| Placeholder | Wartość |
|---|---|
| `{numer}` / `{number}` | numer zgłoszenia `SK-ROK-000123` albo `SRV-ROK-000000` |
| `{imie}` | imię klienta z formularza (puste dla zleceń z aplikacji) |
| `{sprzet}` | sprzęt z formularza (`sprzet` zamiast pustej wartości) |
| `{link}` | `https://<site_url>/status/<token>/`, a przy ustawionym `status_base_url` - `<status_base_url>/<token>` |
| `{serwis}` | nazwa serwisu z `src/data/site.json` |
| `{telefon}` | telefon serwisu (500 613 500) |

Uwaga: w wewnętrznym szablonie `new_ticket` (SMS do serwisu) `{telefon}` oznacza
numer **klienta**. W szablonach klienta `{telefon}` to numer **serwisu** - to
jedyna różnica w znaczeniu placeholderów (w kodzie: `tickets_sms_message()` vs
`tickets_client_sms_message()`).

Zasady wspólne z `docs/formularz-zgloszen.md`:

- polskie znaki są zamieniane na ASCII (GSM-7 - krótszy i tańszy SMS),
- treść ograniczona do 459 znaków (jeden SMS GSM-7 to 160 znaków, dłuższe idą
  jako wieloczęściowe),
- `UNIQUE(ticket_id, channel, template)` gwarantuje najwyżej jedną wiadomość
  danego szablonu na zgłoszenie - powtórzenie żądania nie tworzy duplikatów,
- odbiorcą jest numer klienta z formularza; brak numeru = brak SMS-a,
- link nie zawiera danych osobowych, tylko token.

Przełącznik wysyłki: `sms_client_enabled` (`1` domyślnie). Wyłączenie zostawia
zgłoszenie i stronę statusu bez zmian - SMS-y klienta po prostu nie powstają.

### Tryb testowy

Domyślnie `sms_test_mode = 1` (wspólny z formularzem):

- żadne połączenie z dostawcą nie jest wykonywane (brak kosztów),
- powiadomienie dostaje status `test`, a w `payload` zostaje zamaskowany podgląd,
- panel pokazuje „tryb testowy (bez wysyłki)”.

### Włączenie realnej wysyłki (decyzja właściciela)

1. Konto u SMSAPI albo SerwerSMS, wygenerowany token API.
2. Zatwierdzona nazwa nadawcy (jeśli własna).
3. Panel: Ustawienia -> Formularz - dostawca, token, nadawca, **odznaczone**
   „Tryb testowy”.
4. Test: zgłoszenie z formularza -> w panelu „Zgłoszenia” powiadomienia
   `client_accepted` ze statusem `sent`, na telefonie SMS z linkiem.
5. Zmiana statusu na „Zrealizowane” -> drugi SMS (`client_ready`).
6. Sprawdzić, że link otwiera stronę statusu, a `?format=json` zwraca dane.

Przed wysłaniem zgłoszenia można sprawdzić sam dostawcę jednym poleceniem
(bezpieczne: bez `--send` tylko pokazuje konfigurację i treść, nic nie wysyła):

```bash
runuser -u skcms -- php /srv/studio-komp/panel/bin/sms-test.php --to=600100200
runuser -u skcms -- php /srv/studio-komp/panel/bin/sms-test.php --to=600100200 --send
```

### Koszt

`docs/formularz-zgloszen.md` świadomie nie podaje cen - trzeba potwierdzić
cennik u dostawcy. Skala: **2 wiadomości na naprawę** (przyjęcie i gotowe do
wydania), np. 100 napraw miesięcznie = ok. 200 SMS-ów; koszt policzyć z cennika
dostawcy. Szablony warto trzymać w jednej części SMS-a (do 160 znaków GSM-7),
żeby nie płacić za wiadomości wieloczęściowe - domyślne treści mieszczą się
w jednej części dopiero po podstawieniu krótkiej nazwy sprzętu, więc przed
włączeniem realnej wysyłki sprawdzić długość na prawdziwych danych.

### Błędy dostawcy

- `failed` + `last_error` w panelu, backoff 1, 5, 15, 60 minut, maks. 5 prób.
- Brak realnej wysyłki nie blokuje zgłoszenia ani strony statusu - link jest
  widoczny także w panelu (można go wysłać ręcznie).
- Błędny numer telefonu: zgłoszenie zostaje, SMS wypada - pracownik widzi to
  w szczegółach zgłoszenia.

---

## 7. Panel

| Ekran | Ścieżka | Co tam jest |
|---|---|---|
| Zgłoszenia | `/panel/zgloszenia` | lista, szczegóły, zmiana statusu, historia, kolejka powiadomień |
| Ustawienia -> Formularz | `/panel/settings?tab=form` | dostawca SMS, token, nadawca, tryb testowy, treści szablonów |

W szczegółach zgłoszenia (`/panel/zgloszenia?id=...`) jest karta
**„Śledzenie naprawy (dla klienta)”** z etapem klienta w nagłówku:

- pole **„Link do strony statusu”** (tylko do odczytu) z przyciskiem
  **„Kopiuj”** i linkiem **„Otwórz stronę statusu”** - to ten sam adres, który
  idzie w SMS-ach (`tickets_tracking_link()`),
- **„Nowy link”**: checkbox **„Rozumiem, że stary link przestanie działać”**
  i przycisk **„Wygeneruj nowy link”** (`tickets_tracking_token($id, true)`);
  używane przy pomylonym numerze telefonu albo wycieku adresu,
- **„Notatka publiczna (widoczna dla klienta)”**: pole do 500 znaków i przycisk
  **„Zapisz notatkę publiczną”** (`ticket_set_public_note()`); puste pole usuwa
  notatkę, a notatki wewnętrzne zostają w historii statusów (pole „Notatka” przy
  zmianie statusu),
- w tabeli powiadomień kolumna **„Szablon”** pokazuje m.in.
  „Klient: przyjęcie sprzętu” (`client_accepted`) i „Klient: gotowy do odbioru”
  (`client_ready`), ze statusem i przyciskiem „Ponów”.

Zgłoszenia zsynchronizowane z aplikacji serwisowej mają badge **„Z serwisu”**
(na liście obok numeru i w nagłówku karty), wiersz **„Źródło”** z numerem zlecenia
`SRV-ROK-000000` i puste imię (aplikacja go nie przesyła - nie zmyślamy danych).
Adres karty zlecenia w aplikacji nie jest tu linkowany, bo CMS nie zna jej
identyfikatorów; numer zlecenia wystarcza do odnalezienia go w `serwis.mati-it.pl`.

W Ustawieniach -> Formularz (`/panel/settings?tab=form`) jest sekcja
**„SMS do klienta (śledzenie naprawy)”**:

- przełącznik **„Wysyłaj SMS do klienta”** (`sms_client_enabled`),
- dwa pola treści: **„SMS po przyjęciu sprzętu”** (`client_accepted`) i
  **„SMS po zmianie statusu na „Zrealizowane””** (`client_ready`); puste pole
  oznacza treść domyślną pokazaną jako podpowiedź,
- lista placeholderów z zaznaczeniem, że `{telefon}` to numer serwisu (nie
  klienta), a `{link}` jest wymagany,
- podgląd **„Przykład”** liczony na ostatnim zgłoszeniu z bazy (gdy zgłoszeń nie
  ma, podgląd nie zawiera linku),
- walidacja: maksymalnie 459 znaków i wymagany placeholder `{link}` - bez niego
  zapis jest odrzucany, bo klient nie dostałby adresu strony.

Uprawnienia: link, publiczna notatka i generowanie nowego linku - role `admin`
i `editor` (jak przy zmianie statusu). Każda z tych akcji trafia do dziennika
zdarzeń (`ticket_public_note`, `ticket_tracking_refresh`).

---

### Sprawdzone w panelu (2026-11-25)

Przejście przez interfejs (nie tylko warstwę danych), na lokalnej kopii panelu
z tymczasową bazą:

1. Zapis nowych treści w Ustawienia -> Formularz powoduje, że
   `tickets_client_sms_message()` zwraca **nowy tekst** z podstawionymi
   placeholderami, np. `(tel. 500 613 500)` i linkiem do strony statusu;
   polskie znaki są zamieniane na ASCII, długość mieści się w limicie
   (przykład: 206 znaków).
2. Odznaczenie **„Wysyłaj SMS do klienta”** i zapis: nowe zgłoszenie ma w
   kolejce tylko powiadomienia serwisu (`new_ticket`), **bez** `client_accepted`.
   Po ponownym zaznaczeniu `client_accepted` wraca.
3. Wcześniej sprawdzone na tym samym środowisku: „Nowy link” unieważnia stary
   (stary adres 404, nowy 200), notatka publiczna pojawia się na stronie statusu,
   a zmiana statusu na „Zrealizowane” kolejkuje `client_ready`.

## 8. Wdrożenie na VPS

Kolejność (VPS: `/srv/studio-komp`, dane: `/srv/studio-komp-cms/data`):

1. **Kopia bazy i klucza przed wdrożeniem** (schemat zmienia się przez
   `db_ensure_column`, a klucz tokenów jest sekretem):

   ```bash
   sqlite3 /srv/studio-komp-cms/data/panel.sqlite \
     ".backup /srv/studio-komp-cms/data/panel-backup-$(date +%Y%m%d-%H%M).sqlite"
   cp -a /srv/studio-komp-cms/data/tracking.key \
     /srv/studio-komp-cms/data/tracking.key.bak 2>/dev/null || true
   ```

2. **Wgranie plików**: `panel/lib/*`, `panel/api/status.php`,
   `panel/api/service-sync.php`, `panel/views/status.php`,
   `panel/pages/zgloszenia.php`, `panel/pages/settings.php`,
   `panel/bin/tickets-worker.php`, `panel/bin/self-check-service-sync.php`,
   `deploy/nginx/sk.mati-it.pl.conf`.

3. **Konfiguracja** (`/srv/studio-komp-cms/config.php`) - dwa nowe klucze
   (wzór: `panel/config.example.php`):

   ```php
   'status_base_url'     => '',   // puste = https://<site_url>/status/<token>/
   'service_sync_secret' => '',   // puste = integracja z serwisem wyłączona (503)
   ```

   Sekret musi być **ten sam** po stronie aplikacji (`/srv/sk-serwis/.env`);
   generowanie: `php -r "echo bin2hex(random_bytes(32));"`. Bez sekretu
   `POST /api/service-sync.php` odpowiada 503 `integration_disabled`, a panel
   i strona statusu działają normalnie.

4. **Assety strony statusu** (`public/tracking/*`) trafiają na VPS **przez
   publikację strony** (`pnpm publish:now`), bo leżą w `public/` i są częścią
   builda Astro - samo wgranie panelu ich nie doda.

5. **Baza migruje się sama** - `db_init()` w `panel/lib/db.php` tworzy tabele
   `status_rate` i `sync_rate` oraz dokłada kolumny `public_note`,
   `status_token_version`, `external_ref` (plus unikalny indeks) przy pierwszym
   żądaniu panelu albo API. Klucz `data/tracking.key` powstaje automatycznie
   przy pierwszym liczeniu tokenu (0600).

6. **nginx**:

   ```bash
   cp /srv/studio-komp/deploy/nginx/sk.mati-it.pl.conf /etc/nginx/nginx-sk.conf
   nginx -t -c /etc/nginx/nginx-sk.conf
   systemctl reload nginx-sk
   ```

   Test składni wykonuje się **na VPS** - w repozytorium nie ma nginxa.
   Konfiguracja zawiera już `location = /api/service-sync.php` (tylko POST,
   `client_max_body_size 16k`, `access_log off`) - bez tego bloku żądania
   aplikacji dostawałyby 404.

7. **Test po wdrożeniu** (token z panelu, nie z logów - logi nie zawierają
   tokenu, bo `access_log off`):

   ```bash
   # nagłówki - HEAD wystarcza, PHP odpowiada nagłówkami bez treści
   curl -I https://sk.mati-it.pl/status/<token>/
   # to samo żądaniem GET (gdy proxy nie przepuszcza HEAD)
   curl -sS -D - -o /dev/null https://sk.mati-it.pl/status/<token>/
   # dane dla ewentualnych integracji
   curl -s https://sk.mati-it.pl/status/<token>/?format=json
   # prefiks bez tokenu i token nieprawidłowy
   curl -I https://sk.mati-it.pl/status/
   curl -I https://sk.mati-it.pl/status/zly-token/
   ```

   Oczekiwane nagłówki pierwszego żądania:

   ```
   HTTP/2 200
   cache-control: no-store
   x-robots-tag: noindex, nofollow
   referrer-policy: no-referrer
   x-content-type-options: nosniff
   x-frame-options: SAMEORIGIN
   content-security-policy: default-src 'none'; style-src 'self'; font-src 'self';
     img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'
   ```

   Oczekiwane kody: `/status/<token>/` -> 200 (także dla HEAD),
   `?format=json` -> 200 z `{"ok":true,...}`, `/status/` -> 404,
   `/status/zly-token/` -> 404 (także dla HEAD).

   Nagłówki uwagi: CSP ustawia tylko PHP (nginx go nie dubluje), a
   `Cache-Control` może wystąpić dwa razy - PHP wysyła `no-store, max-age=0`,
   nginx dokłada `no-store`. Oba warianty są poprawne, ważne żeby wartość
   zawierała `no-store`.

8. **Test regresyjny strony statusu** (75 warunków, katalog tymczasowy, bez
   dotykania bazy produkcyjnej i bez wysyłki SMS):

   ```bash
   runuser -u skcms -- php /srv/studio-komp/panel/bin/self-check-status.php
   runuser -u skcms -- php /srv/studio-komp/panel/bin/self-check-service-sync.php
   ```

   Kod wyjścia `0` i „Sprawdzone warunki: 75” oznaczają, że mapowanie etapów,
   tokeny (oba formaty, w tym podrobiony podpis i unieważnianie), kolejka
   `client_accepted` / `client_ready`, tryb testowy, model widoku bez danych
   osobowych, anulowanie i limit tempa są spójne. Drugi skrypt („Sprawdzone
   warunki: 151”) sprawdza integrację: podpis HMAC, okno czasu, walidację
   ładunku, idempotencję, mapowanie statusów aplikacji i endpoint HTTP na
   wbudowanym serwerze PHP. Ten sam skrypt działa lokalnie na Windows:

   ```powershell
   php -d extension=pdo_sqlite -d extension=sqlite3 panel/bin/self-check-status.php
   php -d extension=pdo_sqlite -d extension=sqlite3 panel/bin/self-check-service-sync.php
   ```

9. **SMS**: bez zmiany trybu testowego powiadomienia klienta dostają status
   `test`. Realna wysyłka - patrz sekcja 6.

10. **Cron**: bez zmian (`panel/bin/tickets-worker.php` co minutę z
    `/etc/cron.d/studio-komp`).

11. **Krótkie linki** (`status_base_url`, np. `https://status.mati-it.pl/<token>`)
    wymagają osobnego hosta: vhost z `server_name status.mati-it.pl`,
    `location /` przekazującym do `panel/api/status.php` (ten sam
    `SCRIPT_FILENAME` i `fastcgi_pass` co `/status/`) oraz certyfikatu.
    Do czasu jego dodania `status_base_url` zostaje puste i linki działają pod
    `https://sk.mati-it.pl/status/<token>/`.

Rollback: kod PHP i konfiguracja nginx wracają przez git + ponowne `nginx -t`
i `systemctl reload nginx-sk`; baza z kopii z punktu 1. Uwaga: cofnięcie pliku
`panel/api/status.php` bez cofnięcia konfiguracji nginx daje 404 na całej
ścieżce `/status/`.

### Dostępność - wynik audytu axe

Strona statusu przeszła audyt axe-core (`wcag2a`, `wcag2aa`, `wcag21a`,
`wcag21aa`, `best-practice`) na żywym HTML-u z 2026-11-25:

| Widok | Naruszenia | Zaliczone reguły | Nierozstrzygnięte |
|---|---|---|---|
| `/status/<token>/` 390 px | **0** | 32 | 0 |
| `/status/<token>/` 1366 px | **0** | 32 | 0 |
| `/klient/status/<token>/` 390 px | **0** | 32 | 0 |
| strona „link nieaktywny" (404) 390 px | **0** | 30 | 0 |

Do tego sprawdzone ręcznie: kolejność Tab (telefon → adres strony, widoczny
focus `2px solid`), dokładnie jeden `<h1>`, jeden `aria-current="step"`,
brak poziomego przewijania (`scrollWidth == clientWidth == 375`), jeden plik CSS
i cztery pliki fontów, zero JavaScriptu.

Uwaga przy powtarzaniu audytu: CSP (`default-src 'none'`) celowo blokuje
wstrzyknięcie `axe.min.js` do żywej strony. Audyt robi się na zapisanej kopii
HTML (`curl -o strona.html`), serwowanej lokalnie razem z `/tracking/` - wtedy
style i fonty się ładują, a CSP nie obowiązuje.

---

## 9. Diagnostyka

| Objaw | Co sprawdzić |
|---|---|
| 404 na `/status/` (sam prefiks) | to poprawne - bez tokenu nie ma strony; `location = /status/` w nginx |
| 404 na poprawnym linku | token unieważniony (nowy link w panelu); zgłoszenie `archived = 1`; `SCRIPT_FILENAME` wskazuje `/srv/studio-komp/panel/api/status.php` |
| 404 po restarcie/odtworzeniu VPS | brak `data/tracking.key` (nowy klucz = stare linki nie pasują); odtworzyć klucz z kopii |
| 403 | żądanie nie jest GET/HEAD (`limit_except GET`); sprawdzić metodę w narzędziu testowym |
| 405 przy POST/PUT/innej metodzie | poprawne: dozwolone są tylko GET i HEAD (`Allow: GET, HEAD`); `curl -I` (HEAD) daje 200 i nie jest błędem |
| 429 | limit 30 żądań/min na hash IP (zweryfikowany lokalnie: 30 x 200, 31. żądanie 429); sprawdzić `status_rate`, odczekać minutę |
| Puste dane na stronie | zgłoszenie ma puste `device`; brak publicznej notatki (pole opcjonalne); sprawdzić `ticket_public_view()` |
| Komunikat „Strona statusu jest chwilowo niedostępna” z kodem 200 | brak pliku `panel/views/status.php` - wgrać widok (do tego czasu działa strona awaryjna) |
| Etap nie zmienia się po zmianie statusu | status spoza `CLIENT_STAGE_BY_STATUS` (nieznany status mapuje się na `accepted`); sprawdzić `tickets.status` |
| SMS `test` | tryb testowy włączony - to poprawne zachowanie, nie błąd |
| Brak SMS do klienta | `sms_client_enabled` wyłączone; pusty numer w zgłoszeniu; brak wpisu w `notifications` |
| Brak linku w SMS | pusty `{link}` przy braku `site_url` w konfiguracji panelu; `tickets_tracking_link()` zwraca `''` gdy brak `id` |
| SMS nie dochodzi, status `failed` | `last_error` w panelu; token/nadawca u dostawcy; log `data/logs/tickets.log` |
| Worker nic nie robi | wpis cron, użytkownik `skcms`, uprawnienia `data/logs`, `flock` |
| Strona w cache przeglądarki | `no-store` jest w nagłówkach; Ctrl+F5; sprawdzić `cf-cache-status` w odpowiedzi |
| Stary wygląd strony statusu | `status.css` i fonty z `/tracking/` łapie reguła `location ~* \.(css\|js\|woff2)$` z `max-age=31536000, immutable` - zmiana pliku bez zmiany adresu nie dotrze do klientów; widok linkuje `/tracking/status.css?v=1`, więc po zmianie stylów trzeba podbić numer (`?v=2`) w `panel/views/status.php` |
| Brak nagłówków bezpieczeństwa | `add_header` w `location /status/` nie dziedziczy z `server` - sprawdzić komplet nagłówków w bloku; CSP pochodzi z PHP, nie z nginx |
| Komunikat bez stylów (429, brak widoku) | to skutek CSP (`style-src 'self'` blokuje inline `<style>` w `status_fail_page()`); treść jest poprawna, styl nie - docelowo strona awaryjna też powinna korzystać z `/tracking/status.css` |
| Zablokowany skrypt/styl/obraz w konsoli | CSP: `default-src 'none'`, `style-src 'self'`, `font-src 'self'`, `img-src 'self' data:` - inline i CDN są zabronione; asset musi leżeć w `/tracking/` |
| `php -l` na VPS | `runuser -u skcms -- php -l /srv/studio-komp/panel/api/status.php` |
| Sync: 503 `integration_disabled` | brak `service_sync_secret` w `/srv/studio-komp-cms/config.php` (albo inny klucz) - integracja wyłączona, panel działa normalnie |
| Sync: 401 `invalid_signature` | inny sekret po stronie aplikacji, podpis liczony z innego ciała żądania (np. po zmianie JSON-a przez proxy) albo nagłówki `X-SK-*` nie docierają do PHP |
| Sync: 401 `stale_timestamp` | zegar aplikacji odchylony o więcej niż 300 s (sprawdzić NTP na obu hostach) |
| Sync: 400 `invalid_status` / `unknown_field` / `invalid_reference` | aplikacja wysyła status spoza mapy, pole spoza kontraktu albo numer zlecenia w innym formacie niż `SRV-ROK-000000` - szczegóły w `data/logs/tickets.log` (bez telefonu i usterki) |
| Sync: 429 `rate_limited` | przekroczone 60 żądań/min z jednego IP-hash; sprawdzić `sync_rate` i pętlę retry w aplikacji |
| Sync: dwa zgłoszenia dla jednego zlecenia | nie powinno się zdarzyć - `UNIQUE(external_ref)`; sprawdzić, czy aplikacja wysyła ten sam `reference` (a nie np. numer z sufiksem) |
| Sync: brak SMS po zmianie na „Do wydania” | `sms_client_enabled` wyłączone, pusty telefon albo wpis `client_ready` już istnieje (UNIQUE) - panel pokazuje kolejkę zgłoszenia |
| Krótki link 404 | `status_base_url` ustawione, ale brak vhosta dla tego hosta (sekcja 8, punkt 11); do tego czasu wyczyścić `status_base_url` - linki wrócą pod `/status/` |
| Krótki link działa, stary `/status/` nie | `status_base_url` ustawione, ale nginx nie ma bloku `/status/` - oba warianty muszą działać równolegle dla już wysłanych SMS-ów |

Test regresyjny bez ruszania bazy produkcyjnej (katalog tymczasowy, SMS w trybie
testowym): 75 warunków strony statusu i 151 warunków integracji z aplikacją.

```bash
runuser -u skcms -- php /srv/studio-komp/panel/bin/self-check-status.php
runuser -u skcms -- php /srv/studio-komp/panel/bin/self-check-service-sync.php
php -l /srv/studio-komp/panel/views/status.php
```

Kod wyjścia `0` i „Sprawdzone warunki: 75” / „Sprawdzone warunki: 151”
oznaczają, że mapowanie etapów, tokeny (oba formaty, także podrobiony podpis
i unieważnianie), kolejka `client_accepted` / `client_ready`, tryb testowy,
model widoku bez danych osobowych, limit tempa, podpis HMAC, idempotencja
synchronizacji i endpoint HTTP są spójne. Lokalnie na Windows:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 panel/bin/self-check-status.php
php -d extension=pdo_sqlite -d extension=sqlite3 panel/bin/self-check-service-sync.php
```

---

## 10. Ograniczenia i decyzje właściciela

Do decyzji:

1. **Realna wysyłka SMS do klienta** - konto u dostawcy, token, nadawca,
   odznaczenie trybu testowego, akceptacja kosztu (sekcja 6).
2. **Ważność linku** - obecnie link działa do usunięcia zgłoszenia (retencja
   12 miesięcy) albo do wygenerowania nowego tokenu; brak automatycznego
   wygaszania. Do decyzji: czy dodać termin ważności (np. 90 dni od wydania).
3. **Kopia klucza `data/tracking.key`** - bez niego wszystkie wysłane linki
   przestają działać; do decyzji: kopia razem z bazą, poza VPS.
4. **Retencja i usuwanie** - czy zgłoszenia (a z nimi linki) mają być usuwane
   automatycznie po 12 miesiącach; dziś schemat jest gotowy, ale czyszczenie nie
   jest włączone (`panel/bin/cleanup.php`).
5. **Domena docelowa** - linki zawierają `site_url` z konfiguracji panelu.
   Przejście na `studio-komp.pl` zmieni adres w nowych SMS-ach; stare linki na
   `sk.mati-it.pl` działają dalej, o ile domena testowa zostanie.
6. **Zakres danych na stronie** - czy pokazywać przewidywany termin i cenę
   (dziś: bez ceny, termin tylko jako publiczna notatka).
7. **Reguła „Bypass cache” w Cloudflare** dla `*/status/*` - opcjonalne
   zabezpieczenie na przyszłość; dziś niepotrzebne, bo HTML ma `no-store`.
8. **Host krótkich linków** (`status_base_url`) - decyzja o osobnym vhostcie
   `status.mati-it.pl` (certyfikat, DNS, `location /`); do tego czasu linki mają
   postać `https://sk.mati-it.pl/status/<token>/`.
9. **Termin i Express w synchronizacji** - aplikacja przysyła `planned_at`
   i `express`, ale CMS ich nie zapisuje (nie ma kolumn, a termin pokazywany
   klientowi to dziś publiczna notatka). `accepted_at` **jest** używane jako
   data przyjęcia (`created_at`). Do decyzji: czy dodać kolumny i pokazać
   planowany termin oraz tryb ekspresowy na stronie statusu.

Ograniczenia techniczne:

- Brak powiadomień push i brak pełnej historii dla klienta - widoczne są etapy
  i ich daty, nie notatki pracowników.
- Brak załączników i zdjęć na stronie statusu (świadomie - minimalizacja danych).
- Strona statusu jest poza Astro, więc nie korzysta z komponentów strony
  publicznej; style są w `public/tracking/` i wymagają wersjonowania przy
  zmianach (patrz sekcja 9).
- Link jest sekretem: każdy, kto go zna (np. po przesłaniu dalej), widzi status.
  Ograniczają to `noindex`, brak cache i limit tempa, ale nie zastępują
  logowania - i takie było założenie.
- Token da się policzyć mając `data/tracking.key` i numer `id` zgłoszenia;
  dlatego klucz jest sekretem na tym samym poziomie co hasła w konfiguracji.
- Zgłoszenie z aplikacji nie ma imienia klienta (aplikacja go nie przysyła),
  więc w panelu i w SMS-ach `{imie}` jest puste.

---

## 11. Integracja z serwis.mati-it.pl

Aplikacja warsztatowa (`serwis.mati-it.pl`, Laravel) jest **źródłem prawdy**
o zleceniu. CMS nie pyta aplikacji o nic - dostaje podpisane żądanie i utrzymuje
publiczną stronę statusu, token oraz kolejkę SMS. Plan integracji po stronie
aplikacji: `serwis/docs/aegis/work/2026-11-26-servis-ui-status/plan.md`.

### Kontrakt endpointu

```
POST https://sk.mati-it.pl/api/service-sync.php
Content-Type: application/json
X-SK-Timestamp: <unix, sekundy>
X-SK-Signature: base64url(HMAC-SHA256(sekret, timestamp . "\n" . rawBody))
```

Ciało żądania (JSON, obiekt):

| Pole | Wymagane | Zasady |
|---|---|---|
| `reference` | tak | numer zlecenia, `^SRV-\d{4}-\d{6}$` |
| `phone` | tak | 9 cyfr (spacje/myślniki dozwolone), normalizowane do `+48XXXXXXXXX` |
| `device` | tak | do 120 znaków po normalizacji |
| `issue` | tak | do 2000 znaków, HTML usuwany |
| `status` | tak | `pending`, `in_progress`, `awaiting_parts`, `ready_for_pickup`, `released`, `cancelled` |
| `note` | nie | publiczna notatka dla klienta, do 500 znaków |
| `event` | nie | nazwa zdarzenia z aplikacji, `[A-Za-z0-9_.:-]{1,64}`, trafia do historii statusów |
| `accepted_at` | nie | **data przyjęcia sprzętu z aplikacji** (format `RRRR-MM-DD[ HH:MM[:SS]][Z|±HH:MM]`) - normalizowana do `Y-m-d H:i:s` (czas `Europe/Warsaw`) i zapisywana jako `tickets.created_at` oraz data pierwszego wpisu historii, więc przerwa w dostępie do CMS nie przesuwa etapu „Przyjęty”. Data z przyszłości (poza ±300 s) jest odrzucana |
| `planned_at` | nie | data `RRRR-MM-DD[ HH:MM[:SS]][Z|±HH:MM]`, walidowana i **nie zapisywana** - termin nie jest pokazywany na stronie statusu (sekcja 10, punkt 9) |
| `express` | nie | `true`/`false`/`1`/`0`; walidowane, ale **nie zapisywane** |

Nieznane pola są **odrzucane** (400 `unknown_field`), a nie ignorowane - dzięki
temu literówka w aplikacji nie ginie po cichu. Zbyt długie teksty i złe typy też
dają 400.

Odpowiedź 200:

```json
{"ok":true,"url":"https://status.mati-it.pl/5.tp47iu.uLI6Iou23tiCz5uGYRpMUA","number":"SRV-2026-000123","status":"new","sms_queued":["client_accepted"]}
```

`status` to status zgłoszenia w CMS (`new`, `in_progress`, `done`, `closed`,
`cancelled`), a `sms_queued` wymienia **tylko** szablony faktycznie dopisane do
kolejki w tym żądaniu (pusta lista przy powtórzeniu).

Błędy (zawsze JSON `{"ok":false,"error":"..."}`):

| Kod | `error` | Kiedy |
|---|---|---|
| 400 | `invalid_content_type` | brak `Content-Type: application/json` |
| 400 | `payload_too_large` | ciało większe niż 20 kB |
| 400 | `invalid_json` | treść nie jest obiektem JSON |
| 400 | `unknown_field` / `invalid_reference` / `invalid_phone` / `invalid_device` / `invalid_issue` / `invalid_status` / `invalid_note` / `invalid_event` / `invalid_planned_at` / `invalid_accepted_at` / `invalid_express` / `invalid_field_type` | walidacja ładunku |
| 401 | `invalid_signature` | podpis nie pasuje do ciała i sekretu |
| 401 | `stale_timestamp` | znacznik czasu poza oknem ±300 s (replay) |
| 405 | `method_not_allowed` | metoda inna niż POST (z nagłówkiem `Allow: POST`); w nginx `limit_except POST` daje 403, zanim żądanie dojdzie do PHP |
| 429 | `rate_limited` | ponad 60 żądań/min z jednego IP-hash (z `Retry-After: 60`) |
| 503 | `integration_disabled` | brak `service_sync_secret` w konfiguracji (integracja wyłączona) |
| 503 | `service_unavailable` | błąd zapisu w bazie - aplikacja może ponowić |

Kolejność sprawdzeń: metoda → sekret → `Content-Type`/rozmiar → znacznik czasu
→ limit tempa → podpis → JSON → walidacja pól. Limit tempa liczy wszystkie
żądania z poprawnym znacznikiem czasu (także te ze złym podpisem), żeby
ograniczyć zgadywanie sekretu.

Odpowiedź ma `Cache-Control: no-store` i `X-Content-Type-Options: nosniff`;
endpoint nie uruchamia sesji panelu i nie wymaga CSRF (uwierzytelnia podpis).

### Sekret

- `service_sync_secret` w `/srv/studio-komp-cms/config.php` (CMS) i ten sam
  sekret w konfiguracji aplikacji (`config/client-status.php` / `.env` -
  nazwę zmiennej ustala strona Laravela, patrz plan integracji).
- Generowanie: `php -r "echo bin2hex(random_bytes(32));"`.
- Brak sekretu = integracja wyłączona (503, komunikat bez szczegółów
  konfiguracji). Panel, formularz i strona statusu działają dalej.
- Sekret nigdy nie trafia do repozytorium ani do logów.

### Idempotencja i model danych

- Klucz idempotencji: `reference` ↔ `tickets.external_ref` (unikalny indeks,
  SQLite dopuszcza wiele `NULL`, więc zgłoszenia z formularza nie kolidują).
- Pierwsze żądanie tworzy zgłoszenie w jednej transakcji: `number = reference`,
  `source_page = 'serwis'`, `client_token = 'srvc-' . substr(sha256(reference), 0, 24)`,
  `status` z mapowania, `public_note = note`, wpis historii, link
  (`status_token_version` startuje od 0 i rośnie przy pierwszym tokenie).
- `created_at` to **data przyjęcia z aplikacji** (`accepted_at`), a nie czas
  synchronizacji - jeśli CMS był chwilę niedostępny, klient i tak widzi właściwą
  datę „Przyjęty”. Bez `accepted_at` zostaje czas zapisu; `updated_at` zawsze
  oznacza ostatnią synchronizację. Korekta `accepted_at` w kolejnych żądaniach
  nie zmienia już daty przyjęcia (żeby nie rozjechała się z historią) - termin
  przyjęcia ustala pierwsze żądanie.
- Pierwszy wpis historii to zawsze `new` z datą `accepted_at`. Gdy zlecenie
  przychodzi już w dalszym statusie (np. `ready_for_pickup`), powstaje drugi
  wpis z bieżącym statusem i czasem synchronizacji - dzięki temu etap „Gotowy do
  wydania” ma datę, a „Przyjęty” datę przyjęcia.
- Kolejne żądania aktualizują telefon, sprzęt, status i publiczną notatkę, ale
  **puste/nieobecne pole nie nadpisuje** zapisanej wartości (`issue` zostaje
  z przyjęcia). Historia rośnie tylko przy zmianie statusu.
- To samo żądanie powtórzone (np. retry po timeoucie) nie tworzy drugiego
  zgłoszenia, nie dubluje SMS-a (`UNIQUE(ticket_id, channel, template)`) i nie
  dopisuje historii; odpowiedź zawiera ten sam `number` i `url`.
- SMS: `client_accepted` przy utworzeniu zgłoszenia w CMS, `client_ready` przy
  pierwszym przejściu na `done`. Powiadomienia `new_ticket` do serwisu **nie**
  powstają - o zleceniu wie aplikacja.
- Anulowanie (`cancelled`) nie kasuje zgłoszenia: klient widzi etap „Anulowane”
  bez „Gotowy do wydania” i „Odebrany”.

### Przykład `curl`

Podpis liczy się z **dokładnie tym samym** bajtem ciała, który leci w żądaniu -
dlatego ciało zapisujemy do pliku:

```bash
SECRET='<sekret-z-configu>'
BODY='{"reference":"SRV-2026-000123","phone":"600100200","device":"Lenovo ThinkPad T480","issue":"Nie laduje sie po podlaczeniu zasilacza","status":"pending","note":"Sprzet przyjety w serwisie."}'
TS=$(date +%s)
SIG=$(printf '%s' "$TS"$'\n'"$BODY" | openssl dgst -sha256 -hmac "$SECRET" -binary | openssl base64 -A | tr '+/' '-_' | tr -d '=')

curl -sS -X POST https://sk.mati-it.pl/api/service-sync.php \
  -H 'Content-Type: application/json' \
  -H "X-SK-Timestamp: $TS" \
  -H "X-SK-Signature: $SIG" \
  --data-binary "$BODY"
```

Oczekiwane: `{"ok":true,"url":"https://status.mati-it.pl/<token>","number":"SRV-2026-000123","status":"new","sms_queued":["client_accepted"]}`.
Powtórzenie tego samego polecenia (nowy `TS` i `SIG`) zwraca ten sam `number`
i pustą listę `sms_queued`.

### Ograniczenia integracji

- Realna wysyłka SMS nadal jest w trybie testowym (wspólny przełącznik
  `sms_test_mode`); kolejka rośnie, ale dostawca nie jest wołany.
- `planned_at` i `express` są walidowane i ignorowane (brak kolumn) - patrz
  sekcja 10. `accepted_at` jest używane jako data przyjęcia zgłoszenia.
- Korekta `accepted_at` po pierwszej synchronizacji nie zmienia już daty
  przyjęcia - termin ustala żądanie, które utworzyło zgłoszenie.
- CMS nie wysyła nic do aplikacji: brak webhooków zwrotnych, brak statusu
  doręczenia SMS-a. Link jest w odpowiedzi na żądanie i w panelu.
- Błąd CMS nie blokuje pracy warsztatu: zlecenie zostaje w aplikacji, a link
  można utworzyć ponownie kolejnym żądaniem (idempotencja).
- Komunikat 503 nie ujawnia, czego brakuje w konfiguracji - to celowe; diagnozę
  robi administrator na VPS (sekcja 9).
