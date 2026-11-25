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

- Ceny: **Ustawienia → Cennik**.
- Telefon, adres, godziny: **Ustawienia → Kontakt** (zmiana działa w całym serwisie).
- Nie wymyślaj cen — jeśli nie znasz, zostaw „… zł” albo „wycena po diagnozie”.

### Publikacja

1. Po zapisaniu zmian kliknij **Publikuj** (górny pasek) lub **Publikacja →
   Poproś o publikację**.
2. Publikację wykona komputer właściciela (zadanie „Studio-Komp Publish” co
   5 minut) albo ręcznie: `pnpm publish:remote`.
3. Status i log zobaczysz w sekcji **Publikacja**.
4. Rollback: **Publikacja → Wersje → Przywróć**.

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
