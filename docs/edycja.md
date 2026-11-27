# Panel CMS — instrukcja dla właściciela

Panel: **https://sk.mati-it.pl/panel/** (docelowo `https://studio-komp.pl/panel/`).

## Pierwsze logowanie

1. Otwórz adres panelu.
2. Login i hasło znajdziesz w pliku na serwerze:
   `/srv/studio-komp-cms/data/initial-credentials.txt` (dostęp przez SSH).
3. Po zalogowaniu: **Mój profil → Zmień hasło** i włącz **2FA**.
4. Usuń plik z hasłem po zapisaniu go w menedżerze haseł.

## Co możesz zrobić w panelu

| Sekcja | Do czego służy |
|---|---|
| Pulpit | najważniejsze liczby, szybkie skróty, status publikacji |
| Analityka | odsłony, użytkownicy, kliknięcia telefonu/dojazdu, źródła, urządzenia |
| Usługi / Poradnik / Marki / Podstrony | edycja treści, SEO, wersje robocze, historia |
| Realizacje | dodawanie napraw, kategorie, galerie, slider przed/po |
| Media | zdjęcia i filmy (ALT, opis, miniatury, kasowanie) |
| Przekierowania | stare adresy → nowe (301/302) |
| Opinie Google | ocena/liczba opinii, linki, odświeżanie z API |
| Ustawienia | telefon, adres, godziny, strona główna, menu, cennik, FAQ, analityka, SEO |
| Publikacja | kolejka, log, wersje, przywracanie |
| Podgląd | build podglądu z wersjami roboczymi (`/podglad/`) |
| Użytkownicy | dodatkowe konta, role, reset hasła/2FA |
| Pomoc | skrócona instrukcja i zasady |

## Najczęstsze zadania

### Dodanie artykułu

1. Poradnik → **Dodaj nowy**.
2. Wypełnij tytuł, opis SEO, datę, kategorię, treść (Markdown).
3. Zostaw „wersja robocza”, jeśli chcesz najpierw sprawdzić w podglądzie.
4. Zapisz, sprawdź w **Podgląd**, potem odznacz wersję roboczą i zapisz.

### Dodanie realizacji

1. Realizacje → **Dodaj realizację**.
2. Uzupełnij problem, diagnozę, przebieg naprawy i wynik (bez danych klientów!).
3. Dodaj zdjęcia w Media i wklej ich ścieżki; dla pary zdjęć włącz slider
   „przed/po”.
4. Zapisz — dane automatycznie trafią do pliku strony.

### Zmiana ceny / telefonu / godzin

- Ceny: **Ustawienia → Cennik**. To jedyne miejsce, w którym zmieniasz ceny -
  ta sama wartość trafia na stronę główną, cennik, podstrony usług, marki,
  landingi, FAQ i do artykułów poradnika. Po publikacji zaktualizuje się wszędzie.
- Jeśli nie znasz ceny, zostaw w polu ceny `… zł` albo puste pole. Na stronie
  pokaże się wtedy „wycena po diagnozie” - nigdy wymyślona kwota.
- Nie zmieniaj ukrytego klucza pozycji (widocznego jako `klucz: ...` w tabeli)
  i nie usuwaj pozycji, do których odwołują się treści. Nową pozycję dodasz,
  kopiując wpis w `src/data/cennik.json` i nadając jej nowy unikalny klucz.
- Telefon, adres, godziny: **Ustawienia → Kontakt** (zmiana działa w całym serwisie).

### Publikacja
1. Po zapisaniu zmian kliknij **Publikuj** (górny pasek) lub **Publikacja →
   Poproś o publikację**.
2. Publikację wykona komputer właściciela (zadanie „Studio-Komp Publish” co
   5 minut) albo ręcznie: `pnpm publish:remote`.
3. Status i log zobaczysz w sekcji **Publikacja**.
4. Rollback: **Publikacja → Wersje → Przywróć**.

## Zgłoszenia z formularza

Zgłoszenia z formularza na /kontakt/ trafiają do panelu w sekcji **Zgłoszenia**.
Każde ma numer w formacie `SK-2026-000123`, dane klienta, historię statusów
i historię powiadomień.

- Statusy: Nowe, Odczytane, W trakcie kontaktu, Zrealizowane, Zamknięte.
  Zmiana statusu wymaga notatki i zapisuje się w historii.
- Powiadomienia: SMS do serwisu i opcjonalnie e-mail. Domyślnie działa
  **tryb testowy** - wiadomości nie są wysyłane, tylko zapisywane w kolejce.
- Aby włączyć prawdziwe SMS-y: Ustawienia → Formularz: wybierz dostawcę
  (SMSAPI lub SerwerSMS), wpisz token, nadawcę i numer odbiorcy, wyłącz tryb
  testowy. Potrzebne jest aktywne konto u dostawcy.
- Aby włączyć e-mail: Ustawienia → Formularz: dane SMTP i adres odbiorcy.
- Kolejka jest przetwarzana automatycznie co minutę (cron). Przy błędzie
  wiadomość jest ponawiana. Przycisk „Ponów” wysyła ją od razu.

## Status naprawy dla klienta

Klient dostaje SMS-em link do strony, na której widzi, na jakim etapie jest
jego komputer. Nie musisz nic wysyłać ręcznie - dzieje się to samo:

1. Klient wysyła formularz na /kontakt/ (podaje telefon).
2. Zgłoszenie pojawia się w **Zgłoszenia** i dostaje numer `SK-2026-000123`.
3. W tej samej chwili do kolejki trafia SMS do klienta z linkiem do jego sprawy.
4. Gdy zmienisz status na **Zrealizowane**, klient dostaje drugi SMS:
   że komputer jest gotowy do odbioru. Ten SMS idzie tylko raz.
5. Klient widzi na stronie trzy etapy: **Przyjęty**, **W trakcie naprawy**
   (wszystkie statusy pośrednie) i **Gotowy do wydania**, a po wydaniu sprzętu
   dodatkowo **Odebrany**. Nie widzi swojego imienia, telefonu, ceny ani
   Twoich notatek wewnętrznych.

W szczegółach zgłoszenia, w karcie **„Śledzenie naprawy (dla klienta)”**:

- **link do strony statusu** - ten sam, który poszedł w SMS-ie; możesz go
  skopiować i wysłać klientowi samodzielnie, jeśli SMS nie doszedł,
- **Nowy link** - unieważnia poprzedni (np. gdy numer był pomylony). Wymaga
  zaznaczenia potwierdzenia; stary adres przestaje działać od razu,
- **notatka publiczna** - krótkie zdanie widoczne na stronie klienta
  („Zamówiona matryca dojedzie w czwartek”). Puste pole usuwa notatkę.

Treści obu SMS-ów zmieniasz w **Ustawienia → Formularz → SMS do klienta**.
Możesz używać: `{numer}`, `{imie}`, `{sprzet}`, `{serwis}`, `{telefon}`,
`{link}` (link jest wymagany). Przełącznik **„Wysyłaj SMS do klienta”**
wyłącza oba SMS-y bez ruszania reszty powiadomień.

Domyślnie działa **tryb testowy** - wiadomości lądują w kolejce ze statusem
„Tryb testowy”, ale nikt ich nie dostaje i nic nie kosztują. Aby wysyłać
naprawdę: konto u SMSAPI albo SerwerSMS, token w Ustawieniach, zatwierdzony
nadawca i odznaczony tryb testowy. Szczegóły techniczne:
[docs/status-klienta.md](status-klienta.md).

## Kafelki usług i ceny

- Sekcja „Co najczęściej naprawiamy” na stronie głównej to kafelki. W Treści →
  Usługi zaznacz **„Kafelek w sekcji Co najczęściej naprawiamy”**, uzupełnij
  „Tytuł kafelka” i „Opis kafelka”, wybierz zdjęcie (Media) oraz ustaw
  „Kolejność”. Opis jest widoczny po najechaniu myszą albo kliknięciu kafelka.
- Ceny edytujesz w jednym miejscu: Ustawienia → Cennik. Cennik jest jedynym
  źródłem cen: ta sama wartość jest używana na stronie głównej, w cenniku, na
  podstronach usług i marek, w landingach, FAQ i artykułach poradnika. Teksty
  nie mają już wpisanych kwot na sztywno, więc nie poprawiaj ich ręcznie.
- Pozycja bez ceny (`… zł` albo puste pole) pokazuje na stronie
  „wycena po diagnozie”. Nie wpisuj przybliżonej kwoty, jeśli nie jest ustalona.
- Lista marek w stopce i na stronie głównej pochodzi z Treści → Marki.
  Marka Microsoft Surface została wycofana z oferty - nie dodawaj jej ponownie.

## Ceny - szczegóły techniczne

- `src/data/cennik.json` to jedno źródło cen. Każda pozycja ma stały `key`.
- W Markdownie, frontmatterze, FAQ i landingach używaj tokenu
  `{{cena:klucz}}` - przy buildzie zamienia się na cenę z cennika
  (`src/lib/prices.ts`, plugin `scripts/rehype-prices.mjs`).
- Kontrola spójności: `node scripts/check-prices.mjs`. Skrypt pilnuje
  unikalnych kluczy, tokenów bez pozycji, starych kwot wpisanych na sztywno
  oraz braku „… zł” i surowych tokenów w `dist/`.
- Zmiana cennika czyści cache treści Astro (`scripts/prices-cache.mjs`), żeby
  nowa kwota pojawiła się także w artykułach bez edycji plików `.md`.

## Zasady bezpieczeństwa treści

- Nie usuwaj wartościowych podstron — ustaw zamiast tego przekierowanie 301.
- Nie kopiuj tekstów z innych stron.
- Nie pisz „autoryzowany serwis” — Studio-Komp jest niezależnym serwisem.
- Zdjęcia tylko własne, z opisem ALT.
- Publikuj pojedyncze, sprawdzone zmiany.

## Gdy coś nie działa

- **Zmiany nie są widoczne?** Sprawdź status publikacji; odśwież stronę z Ctrl+F5.
- **Zapomniałeś hasła?** Administrator resetuje w Użytkownicy (albo
  `php panel/bin/create-admin.php --username=admin` na serwerze).
- **Błąd opinii Google?** Ostatnie dane zostają; sprawdź klucz API i limity.
- **Awaria strony?** Publikacja → wcześniejsza wersja → Przywróć.

## Dla technicznych

Pełna dokumentacja: [AGENTS.md](../AGENTS.md). Konfiguracja serwera:
`/srv/studio-komp-cms/config.php` (poza repozytorium). Testy treści:
`php panel/bin/self-check.php`.
