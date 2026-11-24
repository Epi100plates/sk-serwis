# Edycja strony — instrukcja dla właściciela

Strona jest statyczna (Astro). Treść jest oddzielona od kodu, więc ceny, teksty, opinie i zdjęcia zmieniasz w plikach danych albo w panelu CMS — bez przebudowywania strony.

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

## Wariant A — panel CMS (najprostszy)

Panel dostępny jest pod `/admin/` (na wdrożonej stronie). Pozwala:

- zmieniać ceny i pozycje cennika,
- dodawać i edytować opinie,
- podmieniać zdjęcia realizacji i dodawać filmy YouTube,
- edytować teksty podstron usług i artykułów,
- zmieniać dane firmy (telefon, godziny, adres).

**Wymagania:** hosting z automatycznym buildem (Netlify lub Cloudflare Pages) i repozytorium Git. Konfiguracja: `public/admin/config.yml`.

**Edycja lokalna (bez wdrożenia):**

```powershell
# w katalogu projektu, w dwóch terminalach:
npx decap-server
pnpm dev
# następnie otwórz http://127.0.0.1:4321/admin/
```

## Wariant B — edycja plików

1. Otwórz plik, np. `src/data/cennik.json`.
2. Zmień wartość, np. `"price": "350 zł"` → `"price": "380 zł"`.
3. Zapisz. W trybie `pnpm dev` strona odświeża się sama.
4. Po wdrożeniu zmiany są publikowane automatycznie (Netlify/Cloudflare) albo wgrywasz nowy build przez FTP.

## Najczęstsze zmiany

### Zmiana ceny

W `src/data/cennik.json` znajdź pozycję po nazwie i zmień `price`. Cena nieznana = `"… zł"`.

### Dodanie opinii

W `src/data/opinie.json` dodaj obiekt do tablicy `items`:

```json
{
  "name": "Anna K.",
  "rating": 5,
  "text": "Treść opinii z Google.",
  "highlight": "Opcjonalne wyróżnione zdanie."
}
```

Nie wpisuj opinii, które nie istnieją w Google. Sekcja na stronie linkuje do wizytówki — klient może sprawdzić każdą opinię.

### Zmiana liczby opinii lub oceny

W `src/data/site.json`:

```json
"reviews": { "rating": 4.9, "count": 550, "countLabel": "550+" }
```

### Dodanie zdjęcia realizacji

1. Wgraj plik do `public/images/uploads/` (albo przez CMS).
2. W `src/data/realizacje.json` w wybranej pozycji ustaw `"image": "/images/uploads/nazwa.jpg"`.
3. Puste `image` = grafika techniczna.

### Dodanie filmu

W `src/data/wideo.json` uzupełnij `youtubeId` (np. `dQw4w9WgXcQ`). Sekcja „Wideo z warsztatu” pojawi się automatycznie na `/realizacje/`.

### Zmiana godziny pracy

W `src/data/site.json` zmień `hours` (wyświetlanie) oraz `hoursSchema` (dane dla Google — format 24h).

### Dodanie nowej usługi

Skopiuj dowolny plik z `src/content/uslugi/`, zmień nazwę pliku na nowy adres (np. `naprawa-drukarek-wroclaw.md`) i uzupełnij frontmatter. Strona powstanie automatycznie pod `/<nazwa-pliku>/`, linki w menu i stopce dodadzą się same.

## Zasady bezpieczeństwa treści

- Nie usuwaj pól wymaganych we frontmatterze (`title`, `description`, `h1`, `lead`, `teaser`, `group`).
- Daty w formacie `RRRR-MM-DD`.
- Linki wewnętrzne pisz z ukośnikiem na końcu: `/cennik/`.
- Nie dodawaj cen, których nie znasz — pozostaw `"… zł"` lub `"wycena po diagnozie"`.

## Publikacja

Po zmianach:

```powershell
pnpm build
```

Powstaje katalog `dist/` — to gotowa strona do wgrania na hosting. Szczegóły w `docs/wdrozenie.md`.
