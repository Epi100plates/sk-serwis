# Edycja strony — instrukcja dla właściciela

Strona jest statyczna (Astro), ale treść jest oddzielona od kodu, więc ceny, teksty,
opinie i zdjęcia zmieniasz **bez dotykania kodu i bez wgrywania plików przez FTP**.
Masz do wyboru panel w przeglądarce albo edycję plików z publikacją jednym poleceniem.

## Co gdzie leży

| Co chcesz zmienić | Plik |
| --- | --- |
| Telefon, adres, godziny, ocena i liczba opinii, ekspres, analityka | `src/data/site.json` |
| Ceny i pozycje cennika | `src/data/cennik.json` |
| Opinie klientów | `src/data/opinie.json` |
| Realizacje (galeria) | `src/data/realizacje.json` |
| Wideo | `src/data/wideo.json` |
| FAQ na stronie głównej | `src/data/faq.json` |
| Landing page Google Ads | `src/data/landing.json` |
| Teksty podstron usług | `src/content/uslugi/*.md` |
| Artykuły poradnika | `src/content/blog/*.md` |
| Zdjęcia wgrywane w panelu | `public/images/uploads/` |

## Wariant A — panel w przeglądarce

**Adres:** https://sk.mati-it.pl/edycja/ (stary adres `/admin/` przekierowuje tutaj).

Przeglądarka pyta o login i hasło (zwykłe okienko „Authentication required”):

- login: `studio-komp`
- hasło: wygenerowane przy instalacji, zapisane na Twoim komputerze w
  `%LOCALAPPDATA%\studio-komp-deploy\edycja-password.txt`

Panel pozwala:

- zmieniać ceny i pozycje cennika,
- dodawać i edytować opinie,
- podmieniać zdjęcia realizacji i dodawać filmy YouTube,
- edytować teksty podstron usług i artykułów poradnika,
- zmieniać dane firmy (telefon, godziny, adres, analityka),
- edytować landing page Google Ads.

### Jak działa publikacja

1. Klikasz „Publish” w panelu → zmiana zapisuje się jako commit w repozytorium
   na serwerze (`/srv/studio-komp`).
2. Usługa `studio-komp-publish` od razu buduje stronę i podmienia pliki
   w `/var/www/sk.mati-it.pl`.
3. Nowa treść jest na stronie po zakończeniu builda. **Na tym VPS build trwa
   kilka–kilkanaście minut** (1 vCPU, współdzielony serwer) — panel nie czeka na
   build, możesz zamknąć kartę. Postęp sprawdzisz w `docs/wdrozenie-vps.md`.
4. Każda zmiana jest osobnym commitem, więc można w każdej chwili wrócić do
   poprzedniej wersji (`git log` na serwerze).

### Zmiana hasła do panelu

Z katalogu projektu na swoim komputerze:

```powershell
pnpm provision -PanelPassword "nowe-haslo"
```

(albo usuń plik `%LOCALAPPDATA%\studio-komp-deploy\edycja-password.txt` i uruchom
`pnpm provision` — wygeneruje nowe hasło).

### Gdy panel nie działa

| Objaw | Co sprawdzić |
| --- | --- |
| Okno hasła wraca / 401 | hasło z pliku `edycja-password.txt`; zmiana: `pnpm provision -PanelPassword ...` |
| 502 / „Cannot connect” | `systemctl status studio-komp-cms` i `journalctl -u studio-komp-cms -n 50` na serwerze |
| Zapis się udał, strona bez zmian | `journalctl -u studio-komp-publish -n 50` — najczęściej błąd builda |
| Panel ładuje się bez listy treści | odśwież stronę; sprawdź, czy `studio-komp-cms` odpowiada na porcie 8081 |

## Wariant B — edycja plików + publikacja

Dla większych zmian (nowa usługa, nowy artykuł, porządki w treści) wygodniej
pracować na plikach:

```powershell
pnpm pull      # najpierw pobierz zmiany zrobione w panelu na serwerze
pnpm dev       # podgląd na http://127.0.0.1:4321
# ... edycja plików ...
pnpm deploy    # commit + push na serwer; serwer sam przebuduje i opublikuje
```

- `pnpm pull` jest konieczne, jeśli treść była zmieniana w panelu — inaczej push
  zostanie odrzucony (git nie pozwoli nadpisać cudzych zmian).
- `pnpm deploy` czeka na zakończenie publikacji i sprawdza, czy strona odpowiada.
- Historia zmian jest w gicie; konflikt rozwiązujesz jak w każdym repozytorium.

## Najczęstsze zmiany

### Zmiana ceny

W `src/data/cennik.json` (albo panel → Cennik) znajdź pozycję po nazwie i zmień
`price`. Cena nieznana = `"… zł"`.

### Dodanie opinii

Panel → Opinie, albo w `src/data/opinie.json` dodaj obiekt do tablicy `items`:

```json
{
  "name": "Anna K.",
  "rating": 5,
  "text": "Treść opinii z Google.",
  "highlight": "Opcjonalne wyróżnione zdanie."
}
```

Nie wpisuj opinii, które nie istnieją w Google. Sekcja na stronie linkuje do
wizytówki — klient może sprawdzić każdą opinię.

### Zmiana liczby opinii lub oceny

W `src/data/site.json` (panel → Dane firmy):

```json
"reviews": { "rating": 4.9, "count": 550, "countLabel": "550+" }
```

### Dodanie zdjęcia realizacji

1. Panel → Realizacje → wybierz realizację → „Zdjęcie” (plik trafi do
   `public/images/uploads/`).
2. Albo ręcznie: wgraj plik do `public/images/uploads/` i ustaw w
   `src/data/realizacje.json` pole `"image": "/images/uploads/nazwa.jpg"`.
3. Puste `image` = grafika techniczna.

### Dodanie filmu

W `src/data/wideo.json` (panel → Wideo) uzupełnij `youtubeId` (np. `dQw4w9WgXcQ`).
Sekcja „Wideo z warsztatu” pojawi się automatycznie na `/realizacje/`.

### Zmiana godziny pracy

W `src/data/site.json` zmień `hours` (wyświetlanie) oraz `hoursSchema`
(dane dla Google — format 24h).

### Dodanie nowej usługi

Panel → Podstrony usług → „Add Podstrona usługi”, albo skopiuj dowolny plik
z `src/content/uslugi/`, zmień nazwę pliku na nowy adres
(np. `naprawa-drukarek-wroclaw.md`) i uzupełnij frontmatter. Strona powstanie
automatycznie pod `/<nazwa-pliku>/`, linki w menu i stopce dodadzą się same.

## Zasady bezpieczeństwa treści

- Nie usuwaj pól wymaganych we frontmatterze (`title`, `description`, `h1`,
  `lead`, `teaser`, `group`).
- Daty w formacie `RRRR-MM-DD`.
- Linki wewnętrzne pisz z ukośnikiem na końcu: `/cennik/`.
- Nie dodawaj cen, których nie znasz — pozostaw `"… zł"` lub `"wycena po diagnozie"`.

## Publikacja — podsumowanie

| Sposób | Komenda / adres | Co się dzieje |
| --- | --- | --- |
| Panel | https://sk.mati-it.pl/edycja/ | commit na serwerze → build → publikacja |
| Pliki | `pnpm deploy` | commit + push na serwer → build → publikacja |
| Podgląd lokalny | `pnpm dev` | strona na `http://127.0.0.1:4321` |

Szczegóły techniczne (usługi, logi, ręczne przebudowanie): `docs/wdrozenie-vps.md`.
