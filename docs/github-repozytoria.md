# GitHub - co jest w którym repozytorium

Dokument dla właściciela. Opisuje podział projektu na trzy repozytoria GitHub,
stan wdrożenia i to, co zostaje poza repozytoriami.

Stan na 2026-11-25.

---

## 1. Trzy repozytoria (utworzone, wypchnięte)

| Repozytorium | Zawartość | Adres |
|---|---|---|
| **sk-core** | aplikacja warsztatu dla serwisantów (Laravel 12): kolejka napraw, zdjęcia, statusy, wydruki A4, PWA - katalog główny projektu `D:\Deepseekapp\serwis`, nie `Piotrekv3/` | https://github.com/Epi100plates/sk-core |
| **sk-serwis** | strona serwisu + panel CMS + strona statusu (`/status/<token>/`), czyli cały obecny projekt `Piotrekv3` | https://github.com/Epi100plates/sk-serwis |
| **sk-klient** | samodzielna strona statusu dla klienta: widok, style, klient publicznego JSON-a; bez bazy danych i bez tokenów | https://github.com/Epi100plates/sk-klient |

Wszystkie repozytoria są publiczne, gałąź domyślna `main`, bez `node_modules`
i bez buildów (`dist/`). Klon `sk-serwis` waży ok. 6,4 MB i zawiera pełną
historię pracy; `sk-core` to 295 plików i 3,5 MB źródeł (bez `vendor/`, bez
danych z `storage/app/data`).

`sk-core` ma własne CI (`.github/workflows/ci.yml`): Pint, PHPStan poziom 6
i testy Pest w dwóch magazynach danych (JSON i MySQL). Po pierwszym wypchnięciu
2026-11-25 oba zadania są zielone.

### Dlaczego tak

- **sk-core** to aplikacja, której używają serwisanci w warsztacie. Nie zna
  bazy panelu CMS i nie wysyła SMS-ów do klientów - dlatego jest osobnym
  repozytorium: inny odbiorca (pracownik), inne wdrożenie (hosting współdzielony,
  paczka ZIP) i brak wspólnego kodu z warstwą publiczną.
- **sk-serwis** jest jedynym źródłem prawdy o statusach, tokenach i kolejce SMS.
  Strona statusu jest tam częścią panelu PHP, bo czyta bazę `panel.sqlite`
  i korzysta z tego samego klucza tokenów.
- **sk-klient** to warstwa prezentacji dla klienta. Czyta publiczny endpoint
  `GET https://sk.mati-it.pl/status/<token>/?format=json` i renderuje dokładnie
  ten sam widok. Nie ma dostępu do bazy, więc wyciek tego kodu nie ujawnia
  danych klientów ani sposobu liczenia tokenów.

Dzięki temu rozdzieleniu link w SMS-ie może wskazywać albo na sk-serwis
(`https://sk.mati-it.pl/status/...` - tak działa to teraz), albo na osobną
domenę sk-klient (po wdrożeniu i zmianie `site_url` w konfiguracji panelu).
Obie wersje korzystają z jednego backendu.

---

## 2. Co jest źródłem, a co artefaktem

| Katalog / plik | W repo | Dlaczego |
|---|---|---|
| `src/`, `panel/`, `deploy/`, `scripts/`, `docs/`, `public/` | tak | kod, konfiguracja, dokumentacja, assety |
| `package.json`, `pnpm-lock.yaml`, `astro.config.mjs`, `tsconfig.json` | tak | odtwarzają build (`pnpm install --frozen-lockfile`) |
| `AGENTS.md`, `README.md` | tak | dokumentacja dla ludzi i agentów |
| `node_modules/` | **nie** | odtwarza `pnpm install` |
| `dist/`, `dist-preview/`, `.astro/` | **nie** | odtwarza `pnpm build` |
| `release/` (logi, paczki, backupy) | **nie** | artefakty publikacji |
| `shots/` (zrzuty ekranu) | **nie** | materiały robocze |
| `panel/config.php`, `data/`, `tracking.key`, `panel.sqlite` | **nie** | konfiguracja i dane poza repozytorium |

Wszystkie wpisy „nie” są w `.gitignore` - świeży klon nie zawiera ani
`node_modules`, ani buildów, ani sekretów.

Te same zasady obowiązują w `sk-core` (aplikacja dla serwisantów): w repo są
`app/`, `resources/`, `public/`, `config/`, `routes/`, `database/`, `tests/`,
`deploy/`, `docs/`, `composer.json` i `composer.lock`; poza repo zostają
`vendor/`, `.env`, `storage/app/data` i `storage/app/photos` (dane klientów),
`old-app/` (archiwum starej aplikacji PHP), `Piotrekv3/` oraz paczki
`release-*.zip`. Świeży klon odtwarza `composer install` + `php artisan
app:install`.

---

## 3. Jak wypchnąć zmiany

```powershell
cd D:\Deepseekapp\serwis\Piotrekv3
$env:GIT_SSH_COMMAND = "ssh -i `"$env:USERPROFILE\.ssh\github_serwiskit`" -o BatchMode=yes"

# repozytorium serwisu (VPS + GitHub)
git push origin main
git -C D:\Deepseekapp\serwis\_exports\studio-komp-www pull --ff-only
git -C D:\Deepseekapp\serwis\_exports\studio-komp-www push github main

# strona dla klienta (tylko GitHub)
cd D:\Deepseekapp\serwis\_exports\client-status
git push github main

# aplikacja dla serwisantów (tylko GitHub)
cd D:\Deepseekapp\serwis
git push github main
```

Klon roboczy do wypychania `sk-serwis`: `D:\Deepseekapp\serwis\_exports\studio-komp-www`
(remote `origin` = VPS, remote `github` = GitHub). Drugi klon: `_exports\client-status`
(remote `github` = `sk-klient`). `sk-core` wypycha się wprost z katalogu głównego
projektu `D:\Deepseekapp\serwis` (gałąź `main`, remote `github` = `sk-core`) -
nie ma tam drugiego remote'a ani osobnego klonu.

Repozytoria mogą być prywatne bez zmiany kodu - nie zawierają sekretów:
konfiguracja panelu, klucz tokenów, baza i dane klientów żyją wyłącznie na VPS
w `/srv/studio-komp-cms`.

---

## 4. Synchronizacja widoku między repozytoriami

`sk-klient/views/status.php` i `sk-klient/public/tracking/` są kopiami z
`sk-serwis`. Źródłem prawdy jest `sk-serwis`:

```powershell
$src = 'D:\Deepseekapp\serwis\Piotrekv3'
$dst = 'D:\Deepseekapp\serwis\_exports\client-status'
Copy-Item "$src\panel\views\status.php" "$dst\views\status.php" -Force
Copy-Item "$src\public\tracking" "$dst\public\tracking" -Recurse -Force
```

Po skopiowaniu: podbij `?v=` przy `status.css` w obu widokach `sk-klient`,
zrób commit i push. Ta sama zasada obowiązuje dla `views/unavailable.php`.

---

## 5. Wdrożenie - stan i brakujące kroki

| Element | Stan |
|---|---|
| Strona serwisu + panel + `/status/` na `sk.mati-it.pl` | **wdrożone** (release `20261125-232808`, nginx `-t` OK, live 200) |
| Repozytoria GitHub | **wypchnięte** (`sk-core`, `sk-serwis`, `sk-klient`) |
| Aplikacja dla serwisantów (`sk-core`) | **działa** - `https://epii.pl/serwis` (hosting współdzielony, paczka ZIP z `build-release.ps1`); repozytorium to samo źródło, bez wdrożenia na VPS |
| Strona `sk-klient` na VPS | **wdrożona pod `/klient/`** - adres `https://sk.mati-it.pl/klient/status/<token>/`, aplikacja w `/srv/sk-klient`, ten sam backend co `/status/` |
| Strona `sk-klient` na własnej domenie | **nie wdrożona** - brak decyzji o domenie (np. `status.studio-komp.pl`) |
| Realna wysyłka SMS | **wyłączona** - tryb testowy; potrzebny token SMSAPI/SerwerSMS i nadawca |

### Wdrożenie pod `/klient/` (zrobione)

Aplikacja `sk-klient` jest na VPS w `/srv/sk-klient` (właściciel `skcms`),
nginx-serwis przekazuje `/klient/` do jej `index.php`, a style i fonty pochodzą
ze wspólnego `/tracking/`. Wymagane dwa elementy konfiguracji:

1. blok `location ^~ /klient/` w `deploy/nginx/sk.mati-it.pl.conf`,
2. `/srv/sk-klient` w `php_admin_value[open_basedir]` poolu `studio-komp`
   (`deploy/setup-cms.sh`) - bez tego PHP-FPM zwraca
   `Unable to open primary script ... (Operation not permitted)`.

Test po wdrożeniu:

```bash
curl -I https://sk.mati-it.pl/klient/status/<token>/   # 200, Cache-Control: no-store
curl -I https://sk.mati-it.pl/klient/                   # 404
curl -X POST https://sk.mati-it.pl/klient/status/<t>/   # 403 (nginx)
```

### Przeniesienie na własną domenę (do decyzji)

1. `git clone git@github.com:Epi100plates/sk-klient.git /srv/sk-klient`
2. `cp /srv/sk-klient/config.example.php /srv/sk-klient/config.php` i ustawić
   `api_base` na `https://sk.mati-it.pl`.
3. `install -m 644 /srv/sk-klient/deploy/nginx.conf /etc/nginx/sites-available/sk-klient`
   (dostosować `server_name`, certyfikat i socket PHP-FPM), `ln -s` do
   `sites-enabled`, `nginx -t`, `systemctl reload nginx`.
4. Test: `curl -I https://<domena>/status/<token>/` (200), `.../status/zly/` (404).
5. Opcjonalnie: zmienić `site_url` w konfiguracji panelu na tę domenę, żeby SMS-y
   wysyłały link do `sk-klient` zamiast do `sk.mati-it.pl`.
