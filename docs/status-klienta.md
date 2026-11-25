# Status naprawy dla klienta - dokumentacja

Dokument opisuje publiczną stronę śledzenia naprawy pod adresem
`https://<site_url>/status/<token>/` (obecnie testowo
`https://sk.mati-it.pl/status/<token>/`): token, mapowanie statusów zgłoszeń na
etapy widoczne dla klienta, SMS-y z linkiem, panel, konfigurację nginx
i wdrożenie na VPS.

Ostatnia aktualizacja: 2026-11-25.

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
| Zgłoszenie | wiersz w tabeli `tickets` w bazie panelu (`panel.sqlite`), numer `SK-ROK-000123` |
| Etap klienta | jeden z etapów widocznych publicznie: Przyjęty, W trakcie naprawy, Gotowy do wydania, Odebrany |
| Token śledzenia | podpisany adres zgłoszenia w postaci `<id>.<wersja>.<podpis>`; sekret, który jest jednocześnie adresem strony |
| Publiczna notatka | krótki tekst serwisu widoczny na stronie statusu; bez danych osobowych, bez cen |
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

Pliki:

| Plik | Rola |
|---|---|
| `panel/lib/db.php` | tabele `tickets` (kolumny `public_note`, `status_token_version`), `status_rate` (idempotentne `db_init`) |
| `panel/lib/tickets.php` | `ticket_client_stage()`, token śledzenia, `ticket_public_view()`, limit tempa, kolejka SMS do klienta |
| `panel/api/status.php` | publiczny endpoint `GET /status/<token>/` (HTML, JSON, 404, 429) |
| `panel/views/status.php` | widok strony statusu w stylu marki (HTML, bez JS, jeden arkusz `/tracking/status.css?v=1`) |
| `panel/lib/sms.php` | dostawcy SMS + tryb testowy (bez zmian względem formularza) |
| `panel/bin/tickets-worker.php` | worker kolejki powiadomień (cron co minutę) |
| `panel/bin/self-check-status.php` | samokontrola: mapowanie etapów, tokeny, kolejka SMS, model widoku (na katalogu tymczasowym) |
| `panel/bin/sms-test.php` | ręczny test dostawcy SMS po wyłączeniu trybu testowego (`--to=...`, bez `--send` tylko podgląd) |
| `panel/pages/zgloszenia.php` | panel: karta „Śledzenie naprawy (dla klienta)” - link, nowy link, publiczna notatka |
| `panel/pages/settings.php` | Ustawienia -> Formularz: sekcja „SMS do klienta (śledzenie naprawy)” |
| `public/tracking/status.css` + `public/tracking/fonts/*` | statyczne assety strony statusu (serwowane z roota, nie przez PHP) |
| `deploy/nginx/sk.mati-it.pl.conf` | publiczne `location` dla `/status/` |

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

`ticket_public_view()` buduje z tego listę kroków (`steps`) dla widoku:

- do etapu `picked_up` lista ma **3 kroki** (Przyjęty, W trakcie naprawy, Gotowy
  do wydania), po zamknięciu zgłoszenia dochodzi czwarty krok „Odebrany”,
- krok ma stan `done` (miniony), `current` (bieżący) albo `todo` (przyszły),
- data kroku to pierwszy wpis w `ticket_history` o statusie mapowanym na ten
  etap, więc data „Przyjęty” pochodzi z chwili zgłoszenia, a „Gotowy do
  wydania” z chwili zmiany statusu na `done`.

Uwagi:

- Klient widzi **trzy etapy procesu** i ewentualny stan końcowy „Odebrany”.
  Wszystko pomiędzy „Przyjęty” a „Gotowy do wydania” pokazuje się jako
  „W trakcie naprawy”.
- Zmiana etapów pośrednich w bazie (np. dodanie statusu `awaiting_parts`) **nie
  wymaga zmian na stronie klienta** - wystarczy dopisać nowy status do
  `CLIENT_STAGE_BY_STATUS` z wartością `in_progress`.
- Statusy wewnętrzne panelu (`new`, `read`, `in_progress`, `done`, `closed`)
  pozostają bez zmian; panel nadal pokazuje etykiety z `TICKET_STATUSES`.
- Pełna historia statusów (`ticket_history`) jest wewnętrzna - na stronie
  statusu widać tylko etapy i ich daty, nie notatki pracowników.

---

## 4. Adresy i odpowiedzi endpointu

Token parsowany jest z `REQUEST_URI` (prefiks `/status/`, końcowy `/` opcjonalny),
dlatego nginx przekazuje żądanie do `panel/api/status.php` przez
`fastcgi_param SCRIPT_FILENAME` - bez `try_files` i bez przepisywania adresu.

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
| Token | `<id>.<wersja>.<podpis>`; podpis to HMAC-SHA256 (base64url) z `ticket\|<id>\|<wersja>`, liczony kluczem serwera - wyciek samej bazy nie daje linków |
| Klucz | `tracking_secret` w konfiguracji panelu albo plik `data/tracking.key` (64 znaki hex, uprawnienia 0600, poza repozytorium i poza webrootem) |
| Weryfikacja | wyrażenie regularne na format tokenu, zgodność `wersji` z `tickets.status_token_version`, `hash_equals()` na podpisie |
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
token = <id zgłoszenia>.<wersja>.<base64url(HMAC-SHA256("ticket|<id>|<wersja>"))>
```

- w bazie leży tylko `status_token_version` (liczba), nie token i nie jego hash,
- podpis da się policzyć wyłącznie znając klucz serwera (`data/tracking.key`),
  więc kopia `panel.sqlite` nie pozwala otworzyć żadnego linku,
- link jest odtwarzalny w panelu, bo nie trzeba go przechowywać,
- unieważnienie = nowa wersja (nowy podpis), stara wersja przestaje pasować.

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
razem ze zgłoszeniem, w tej samej transakcji.

Placeholdery (obsługiwane przez `tickets_client_sms_message()`):

| Placeholder | Wartość |
|---|---|
| `{numer}` / `{number}` | numer zgłoszenia `SK-ROK-000123` |
| `{imie}` | imię klienta z formularza |
| `{sprzet}` | sprzęt z formularza (`sprzet` zamiast pustej wartości) |
| `{link}` | `https://<site_url>/status/<token>/` |
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
   `panel/views/status.php`, `panel/pages/zgloszenia.php`,
   `panel/pages/settings.php`, `panel/bin/tickets-worker.php`,
   `deploy/nginx/sk.mati-it.pl.conf`.

3. **Assety strony statusu** (`public/tracking/*`) trafiają na VPS **przez
   publikację strony** (`pnpm publish:now`), bo leżą w `public/` i są częścią
   builda Astro - samo wgranie panelu ich nie doda.

4. **Baza migruje się sama** - `db_init()` w `panel/lib/db.php` tworzy tabele
   `status_rate` i dokłada kolumny `public_note`, `status_token_version` przy
   pierwszym żądaniu panelu albo API. Klucz `data/tracking.key` powstaje
   automatycznie przy pierwszym liczeniu tokenu (0600).

5. **nginx**:

   ```bash
   cp /srv/studio-komp/deploy/nginx/sk.mati-it.pl.conf /etc/nginx/nginx-sk.conf
   nginx -t -c /etc/nginx/nginx-sk.conf
   systemctl reload nginx-sk
   ```

   Test składni wykonuje się **na VPS** - w repozytorium nie ma nginxa.

6. **Test po wdrożeniu** (token z panelu, nie z logów - logi nie zawierają
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

7. **Test regresyjny strony statusu** (54 warunki, katalog tymczasowy, bez
   dotykania bazy produkcyjnej i bez wysyłki SMS):

   ```bash
   runuser -u skcms -- php /srv/studio-komp/panel/bin/self-check-status.php
   ```

   Kod wyjścia `0` i „Sprawdzone warunki: 54” oznaczają, że mapowanie etapów,
   tokeny (w tym podrobiony podpis i unieważnianie), kolejka `client_accepted`
   / `client_ready`, tryb testowy, model widoku bez danych osobowych i limit
   tempa są spójne. Ten sam skrypt działa lokalnie na Windows (PHP CLI z
   `pdo_sqlite`):

   ```powershell
   php -d extension=pdo_sqlite -d extension=sqlite3 panel/bin/self-check-status.php
   ```

8. **SMS**: bez zmiany trybu testowego powiadomienia klienta dostają status
   `test`. Realna wysyłka - patrz sekcja 6.

9. **Cron**: bez zmian (`panel/bin/tickets-worker.php` co minutę z
   `/etc/cron.d/studio-komp`).

Rollback: kod PHP i konfiguracja nginx wracają przez git + ponowne `nginx -t`
i `systemctl reload nginx-sk`; baza z kopii z punktu 1. Uwaga: cofnięcie pliku
`panel/api/status.php` bez cofnięcia konfiguracji nginx daje 404 na całej
ścieżce `/status/`.

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

Test regresyjny bez ruszania bazy produkcyjnej (katalog tymczasowy, SMS w trybie
testowym, 54 warunki):

```bash
runuser -u skcms -- php /srv/studio-komp/panel/bin/self-check-status.php
php -l /srv/studio-komp/panel/views/status.php
```

Kod wyjścia `0` i „Sprawdzone warunki: 54” oznaczają, że mapowanie etapów,
tokeny (także podrobiony podpis i unieważnianie), kolejka `client_accepted` /
`client_ready`, tryb testowy, model widoku bez danych osobowych i limit tempa są
spójne. Lokalnie na Windows:

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 panel/bin/self-check-status.php
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
