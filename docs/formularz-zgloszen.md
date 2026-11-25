# Formularz zgłoszeń serwisowych - dokumentacja

Dokument opisuje system zgłoszeń z formularza na stronie Studio-Komp:
zapis w bazie panelu, numer zgłoszenia, powiadomienie SMS do serwisu (domyślnie
tryb testowy), opcjonalny e-mail SMTP, panel „Zgłoszenia” i worker cron.

Ostatnia aktualizacja: 2026-11-25.

---

## 1. Po co to jest

Wcześniej formularz wysyłał dane do `/dziekujemy/` (Netlify/Web3Forms). Na VPS
nginx zwracał 405 Method Not Allowed, więc zgłoszenia ginęły. Teraz:

- zgłoszenie trafia **najpierw do bazy** panelu (źródło prawdy),
- dostaje numer `SK-ROK-000123`,
- jest widoczne w panelu `/panel/zgloszenia`,
- o nowym zgłoszeniu serwis dostaje **SMS** (domyślnie w trybie testowym -
  bez realnej wysyłki i bez kosztów),
- e-mail jest opcjonalną kopią, wysyłaną przez SMTP tylko po włączeniu,
- system nie opiera się wyłącznie na SMS - nawet jeśli powiadomienie zawiedzie,
  zgłoszenie i tak jest w bazie.

Zasady bezpieczeństwa: żadnych fikcyjnych integracji, brak realnej wysyłki
SMS/e-mail domyślnie, brak danych osobowych w logach i adresach URL.

---

## 2. Architektura

```
Przeglądarka (strona Astro SSG)
  |  GET /api/form-token            -> token + cookie sk_form_csrf
  |  POST /api/zgloszenie           -> JSON (JS) albo 303 /dziekujemy/ (bez JS)
  v
nginx-sk (/api/form-token, /api/zgloszenie -> PHP-FPM pool skcms)
  v
panel/api/form-token.php  +  panel/api/form.php   (lekka inicjalizacja, bez sesji panelu)
  v
panel/lib/tickets.php  ->  panel/lib/db.php (SQLite panelu)
  |      - walidacja, numer, zapis w transakcji, historia, kolejka powiadomień
  v
notifications (status pending)
  v
panel/bin/tickets-worker.php (cron co minutę, flock)
  |      - SMS: panel/lib/sms.php (SMSAPI / SerwerSMS, tryb testowy)
  |      - e-mail: panel/lib/mailer.php (SMTP przez socket, bez composera)
  v
panel/pages/zgloszenia.php  (lista, szczegóły, historia, ponawianie, CSV)
```

Pliki:

| Plik | Rola |
|---|---|
| `panel/lib/db.php` | tabele `tickets`, `ticket_history`, `notifications`, `form_rate` (idempotentne `db_init`) |
| `panel/lib/tickets.php` | walidacja, normalizacja, zapis, numer, historia, kolejka, limity |
| `panel/lib/sms.php` | dostawcy SMS + tryb testowy |
| `panel/lib/mailer.php` | klient SMTP (TLS/STARTTLS, AUTH LOGIN, UTF-8) |
| `panel/api/_init.php` | lekka inicjalizacja publicznego API (config + db + lib, bez sesji) |
| `panel/api/form-token.php` | `GET /api/form-token` |
| `panel/api/form.php` | `POST /api/zgloszenie` |
| `panel/bin/tickets-worker.php` | worker kolejki (CLI, cron) |
| `panel/pages/zgloszenia.php` | widok panelu |
| `panel/pages/settings.php` | Ustawienia -> Formularz (SMS/SMTP) |
| `src/components/RepairForm.astro` | formularz na stronie |
| `deploy/nginx/sk.mati-it.pl.conf` | publiczne location dla API |
| `deploy/setup-cms.sh` | wpis cron workera |

---

## 3. Tabele

### tickets

| Kolumna | Typ | Opis |
|---|---|---|
| `id` | INTEGER PK | |
| `number` | TEXT UNIQUE | numer `SK-YYYY-000123` (sekwencja roczna) |
| `created_at`, `updated_at` | TEXT | czas lokalny Europe/Warsaw (`Y-m-d H:i:s`) |
| `name` | TEXT | imię (2-80 znaków) |
| `phone` | TEXT | numer znormalizowany `+48XXXXXXXXX` |
| `device` | TEXT | sprzęt (do 120 znaków, opcjonalny) |
| `problem` | TEXT | opis usterki (10-2000 znaków, plain text) |
| `source_page` | TEXT | podstrona, z której wysłano formularz |
| `ip_hash` | TEXT | hash IP z solą (bez pełnego IP) |
| `user_agent` | TEXT | do 300 znaków |
| `status` | TEXT | `new`, `read`, `in_progress`, `done`, `closed` |
| `consent_at`, `consent_version` | TEXT | znacznik czasu i wersja zgody |
| `client_token` | TEXT UNIQUE | idempotencja ponowienia formularza |
| `archived` | INTEGER | rezerwa na archiwizację (lista domyślnie `archived = 0`) |
| `notes` | TEXT | rezerwa na notatki wewnętrzne |

### ticket_history

`ticket_id`, `status`, `note`, `username` (`system` dla zdarzeń automatycznych),
`created_at`. Każda zmiana statusu i przyjęcie zgłoszenia dopisują wpis.

### notifications

`id`, `ticket_id`, `channel` (`sms`/`email`), `provider`, `recipient`,
`template` (`new_ticket`), `payload` (JSON bez pełnych danych wrażliwych -
zamaskowany podgląd), `status` (`pending`/`sent`/`failed`/`skipped`/`test`),
`attempts`, `last_error`, `provider_message_id`, `delivery_status`,
`next_attempt_at`, `created_at`, `updated_at`.

`UNIQUE(ticket_id, channel, template)` gwarantuje najwyżej jedną wiadomość
danego kanału na zgłoszenie - ponowienie formularza nie tworzy duplikatów.

### form_rate

`ip_hash`, `created_at`. Limit: 3 zgłoszenia / 10 minut / IP-hash (zliczane są
wszystkie próby wysłania, także odrzucone przez walidację). Stare wpisy usuwa
worker (2 dni).

Wszystkie zapytania używają prepared statements PDO.

---

## 4. Endpointy publiczne

### GET /api/form-token

Zwraca `{"token":"<ts>.<hex>","ts":<unix>}` i ustawia cookie `sk_form_csrf`
(HttpOnly, Secure gdy HTTPS, SameSite=Lax, Path=/, ważność 2 h). Wartość cookie
to SHA-256 tokenu, a token wraca do klienta (double-submit). Token zawiera czas
wydania - serwer odrzuca formularze wysłane wcześniej niż po 3 s lub później
niż po 2 h.

### POST /api/zgloszenie

Przyjmuje `application/x-www-form-urlencoded` albo `application/json`.
Pola: `imie`/`name`, `telefon`/`phone`, `sprzet`/`device`, `usterka`/`problem`,
`zgoda`/`consent`, `token`, `ts`, `client_token`, `source_page`, honeypot
`bot-field`.

Odpowiedź dla JS (nagłówek `X-Requested-With: XMLHttpRequest` lub
`Accept: application/json`):

```json
{"ok":true,"number":"SK-2026-000123","duplicate":false,"message":"Dziękujemy..."}
{"ok":false,"errors":{"telefon":"Podaj numer telefonu w formacie 9 cyfr..."}}
```

Kody błędów: 400 (nieczytelne dane), 403 (Origin/bot), 413 (za duży),
422 (walidacja, honeypot, token), 429 (limit tempa, `Retry-After`),
500 (błąd wewnętrzny). Bez szczegółów systemowych w odpowiedzi.

Wysyłka bez JavaScriptu: formularz ma `action="/api/zgloszenie" method="post"`,
po sukcesie serwer odpowiada 303 na `/dziekujemy/` - bez danych osobowych
w adresie.

Zabezpieczenia: Origin/Referer zgodne z `site_url`, token + cookie, honeypot,
filtr botów po User-Agent, limit rozmiaru 20 KB, limit tempa, brak CORS.
Logi trafiają do `data/logs/tickets.log` - bez telefonu, imienia i treści
usterki (tylko rodzaj zdarzenia, numer zgłoszenia).

Uwaga: bez JavaScriptu klient nie pobiera tokenu, więc formularz natywny jest
chroniony Origin/Referer i limitem tempa (token jest dodatkową warstwą dla JS).

---

## 5. Konfiguracja w panelu

Panel: **Ustawienia -> Formularz**.

### SMS

| Klucz `settings` | Znaczenie |
|---|---|
| `sms_test_mode` | `1` = tryb testowy (domyślnie), `0` = realna wysyłka |
| `sms_provider` | `smsapi` lub `serwersms` |
| `sms_api_token` | token API dostawcy (sekret, nie jest pokazywany) |
| `sms_sender` | nadawca (opcjonalny, musi być zatwierdzony u dostawcy) |
| `sms_recipient` | numer odbiorcy powiadomień (może być inny niż numer firmy) |
| `sms_template` | szablon treści z placeholderami |

Domyślna treść SMS (bez opisu usterki, bez innych danych):

```
Studio-Komp: nowe zgloszenie nr {number}. {imie}, {sprzet}, tel. {telefon}. Szczegoly w panelu: {link}
```

Placeholdery: `{number}`, `{imie}`, `{sprzet}`, `{telefon}`, `{link}` (adres
panelu `/panel/zgloszenia`). Polskie znaki są zamieniane na ASCII (GSM-7 -
krótszy i tańszy SMS). Treść jest ograniczana do 459 znaków.

### E-mail (opcjonalny)

| Klucz `settings` | Znaczenie |
|---|---|
| `mail_enabled` | `1` = włączone, `0` = wyłączone (domyślnie) |
| `smtp_host`, `smtp_port` | serwer i port (typowo 587) |
| `smtp_secure` | `tls` (STARTTLS), `ssl` (465) lub `none` |
| `smtp_user`, `smtp_pass` | logowanie (hasło to sekret) |
| `smtp_from`, `smtp_from_name` | nadawca |
| `smtp_to` | odbiorca kopii |

Jeśli e-mail jest wyłączony lub brakuje konfiguracji, powiadomienie dostaje
status `skipped` (bez wysyłki i bez ponawiania).

Sekrety: pola typu password, puste pole oznacza „bez zmian”; jest osobny
checkbox do usunięcia zapisanego sekretu. Wartości nie trafiają do logów ani
dziennika zdarzeń.

---

## 6. Tryb testowy

Domyślnie `sms_test_mode = 1`. W trybie testowym:

- **żadne połączenie z dostawcą nie jest wykonywane** (brak kosztów),
- powiadomienie dostaje status `test`, `attempts` rośnie, `delivery_status = test`,
- w `payload` zostaje zamaskowany podgląd treści (np. `A***`, `***500`),
- panel pokazuje „tryb testowy (bez wysyłki)”.

Aby włączyć prawdziwe SMS-y (decyzja właściciela):

1. Założyć konto u SMSAPI albo SerwerSMS, wygenerować token API.
2. Zatwierdzić nazwę nadawcy (jeśli ma być własna).
3. W panelu: Ustawienia -> Formularz - wybrać dostawcę, wkleić token, wpisać
   numer odbiorcy, odznaczyć „Tryb testowy”.
4. Wysłać testowe zgłoszenie i sprawdzić status `sent` w panelu „Zgłoszenia”.
5. Ustalić limit kosztów i sposób reakcji na błędy dostawcy.

E-mail: wypełnić dane SMTP i zaznaczyć „Włącz powiadomienia e-mail”.

---

## 7. Worker i retry

`panel/bin/tickets-worker.php` uruchamiany z crona co minutę:

```
* * * * * skcms /usr/bin/php /srv/studio-komp/panel/bin/tickets-worker.php >> /srv/studio-komp-cms/data/logs/tickets.log 2>&1
```

- `flock` blokuje nakładanie się przebiegów,
- przetwarza do 30 powiadomień `pending`/`failed` z `next_attempt_at <= now`,
- jedna próba na wpis w jednym przebiegu,
- backoff po błędzie: 1, 5, 15, 60 minut, maksymalnie 5 prób, potem status
  `failed` (bez dalszych prób),
- sukces: status `sent` + `provider_message_id` (SMSAPI) / `delivery_status`,
- tryb testowy: status `test` bez sieci,
- ręczne „Ponów” w panelu resetuje liczniki i wraca wpis do kolejki,
- w panelu jest też przycisk „Przetwórz kolejkę” (uruchomienie synchroniczne).

Log `data/logs/tickets.log` zawiera tylko metadane (numer zgłoszenia, kanał,
status, kod błędu) - bez danych osobowych.

---

## 8. Panel Zgłoszenia

`/panel/zgloszenia`:

- liczniki wg statusów, filtr statusu, wyszukiwanie po numerze, telefonie
  i imieniu, paginacja,
- szczegóły: dane, historia statusów, powiadomienia (status, próby, następna
  próba, ID u dostawcy, błąd) z przyciskiem „Ponów”,
- zmiana statusu z notatką (wpis w historii),
- eksport CSV (zgodnie z filtrami),
- role: `admin` i `editor` mogą czytać i zmieniać status; usuwanie tylko `admin`.
- Otwarcie szczegółów zgłoszenia ze statusem `new` automatycznie ustawia
  `read` i dopisuje wpis „Odczytane w panelu.”.

---

## 9. RODO i prywatność

- Administrator: Studio-Komp, Kotlarska 14, 50-150 Wrocław.
- Cel: obsługa zgłoszenia i kontakt w sprawie naprawy.
- Podstawa: art. 6 ust. 1 lit. b i f RODO.
- Zakres: imię, telefon, sprzęt, opis usterki, strona źródłowa, data, zgoda;
  IP tylko jako hash.
- Retencja: 12 miesięcy od zamknięcia zgłoszenia, potem usunięcie.
- Odbiorcy: hosting/poczta; opcjonalnie dostawca SMS i SMTP.
- SMS/e-mail do serwisu to powiadomienie **wewnętrzne**, nie marketing.
- Treść SMS nie zawiera opisu usterki; `payload` w bazie ma dane zamaskowane.

Polityka prywatności na stronie zawiera sekcję „Zgłoszenia serwisowe
i powiadomienia” oraz informację o cookie `sk_form_csrf`.

---

## 10. Wdrożenie i migracja

1. Wgraj pliki: `panel/lib/*`, `panel/api/*`, `panel/bin/tickets-worker.php`,
   `panel/pages/zgloszenia.php`, `panel/views/layout.php`, `panel/index.php`,
   `panel/assets/admin.css`, `deploy/nginx/sk.mati-it.pl.conf`,
   `deploy/setup-cms.sh`, `src/**` (po publikacji strony).
2. **Baza migruje się sama** - `db_init()` w `panel/lib/db.php` tworzy nowe
   tabele `IF NOT EXISTS` przy pierwszym żądaniu (panel lub API). Nie trzeba
   ręcznie zmieniać schematu; kopia zapasowa `panel.sqlite` przed wdrożeniem
   jest zalecana.
3. nginx: `nginx -t -c /etc/nginx/nginx-sk.conf` i `systemctl reload nginx-sk`.
4. Cron: wpis w `/etc/cron.d/studio-komp` (dodaje `deploy/setup-cms.sh`).
5. Sprawdź: `GET https://sk.mati-it.pl/api/form-token` -> 200 JSON,
   `POST /api/zgloszenie` z przeglądarki -> zgłoszenie w panelu, a w trybie
   testowym powiadomienie ze statusem `test`.
6. Pamiętaj, że `deploy/publish.ps1` publikuje stronę statyczną (Astro) -
   frontend z nowym `RepairForm.astro` zadziała po publikacji.

---

## 11. Diagnostyka

| Objaw | Co sprawdzić |
|---|---|
| Formularz zwraca 404 | nginx: location `/api/form-token` i `/api/zgloszenie`; reload nginx-sk |
| 403 przy wysyłce | Origin/Referer vs `site_url` w config panelu; filtr botów (User-Agent) |
| 422 „Sesja formularza wygasła” | cookie `sk_form_csrf` (blokada cookies, Safari ITP), odświeżenie strony |
| 429 | limit 3 zgłoszeń / 10 min; odczekać lub sprawdzić `form_rate` |
| Brak SMS, status `test` | tryb testowy włączony - to poprawne zachowanie |
| Status `failed` | `last_error` w panelu; token/numer odbiorcy; logi `data/logs/tickets.log` |
| Brak e-maila, status `skipped` | e-mail wyłączony lub brak konfiguracji SMTP |
| Worker nic nie robi | wpis cron, użytkownik `skcms`, uprawnienia `data/logs`, `flock` |
| `php -l` na VPS | `runuser -u skcms -- php -l /srv/studio-komp/panel/lib/tickets.php` |

Test funkcji bez VPS (lokalnie) - patrz sekcja 12.

---

## 12. Testy lokalne (bez realnej wysyłki)

Na Windows z PHP CLI (rozszerzenia pdo_sqlite, sqlite3, mbstring, openssl):

```powershell
php -l panel/lib/tickets.php
php -l panel/lib/sms.php
php -l panel/lib/mailer.php
php -l panel/api/form.php
php -l panel/api/form-token.php
```

Testy funkcji uruchamiaj na **tymczasowym katalogu danych poza repozytorium**
(plik konfiguracji wskazuje `data_dir` na katalog tymczasowy), np.:

```php
require 'panel/api/_init.php';
tickets_create([...], 'token-testowy-1');
tickets_process_queue();
```

Nie testuj realnej wysyłki: pozostaw `sms_test_mode = 1`. Test retry wymusza
błąd przez wyłączenie trybu testowego i pusty token dostawcy (brak połączenia
sieciowego, status `failed` + `next_attempt_at`).

---

## 13. Czego brakuje do produkcji

- Konto u dostawcy SMS + token + zatwierdzony nadawca (decyzja właściciela).
- Konfiguracja SMTP (host, port, użytkownik, hasło, nadawca, odbiorca) -
  opcjonalna.
- Potwierdzenie kosztów SMS i zasad reakcji na błędy dostawcy.
- Kopia zapasowa `panel.sqlite` (poza VPS) - nadal zalecana.
- Ewentualna retencja: automatyczne usuwanie zgłoszeń po 12 miesiącach od
  zamknięcia (można dodać do `panel/bin/cleanup.php`; obecnie schemat jest
  gotowy, ale czyszczenie nie jest włączone automatycznie).
