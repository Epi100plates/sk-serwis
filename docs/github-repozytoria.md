# GitHub - co wrzucamy i do którego repozytorium

Dokument dla właściciela. Opisuje, jak podzielić projekt na dwa repozytoria
GitHub i jak je wypchnąć, gdy projekty zostaną już utworzone na koncie.

Stan na 2026-11-25: cała implementacja jest w jednym repozytorium roboczym
(`Piotrekv3`, wdrożona na https://sk.mati-it.pl, release `20261125-232808`).

---

## 1. Co jest źródłem, a co artefaktem

Repozytorium zawiera **cały kod źródłowy**. Poza nim zostają pliki, które
odtwarza się jedną komendą albo które są danymi lokalnymi:

| Katalog / plik | W repo | Dlaczego |
|---|---|---|
| `src/`, `panel/`, `deploy/`, `scripts/`, `docs/`, `public/` | tak | kod, konfiguracja, dokumentacja, assety |
| `package.json`, `pnpm-lock.yaml`, `astro.config.mjs`, `tsconfig.json` | tak | odtwarzają build (`pnpm install`) |
| `AGENTS.md`, `README.md` | tak | dokumentacja dla ludzi i agentów |
| `node_modules/` | **nie** | odtwarza `pnpm install --frozen-lockfile` |
| `dist/`, `dist-preview/`, `.astro/` | **nie** | odtwarza `pnpm build` |
| `release/` (logi, paczki, backupy) | **nie** | artefakty publikacji, mogą zawierać dane |
| `shots/` (zrzuty ekranu) | **nie** | materiały robocze |
| `panel/config.php`, `data/`, `tracking.key`, `panel.sqlite` | **nie** | konfiguracja i dane poza repozytorium |

Wszystkie wpisy „nie” są już w `.gitignore` - klon repozytorium nie zawiera
`node_modules` ani buildów.

---

## 2. Podział na dwa repozytoria

### Repozytorium A - strona serwisu (Astro + panel CMS)

To cały obecny projekt: strona publiczna, panel `/panel`, formularz zgłoszeń,
analityka, publikacja i **strona statusu naprawy** (`/status/<token>/`) z jej
SMS-ami i kolejką powiadomień.

Uzasadnienie: strona statusu czyta bazę panelu (`panel.sqlite`) i korzysta
z tego samego klucza tokenów oraz tej samej kolejki SMS. Rozdzielenie tego na
dwie aplikacje PHP oznaczałoby albo dwa procesy piszące do jednej bazy, albo
kopię logiki tokenów i statusów - czyli dwa źródła prawdy i ryzyko rozjazdu.

### Repozytorium B - strona dla klienta

Rekomendacja: **samodzielny front-end strony statusu** (PHP albo statyczny
szablon + `fetch`), który pobiera dane z publicznego JSON-a repozytorium A:

```
GET https://sk.mati-it.pl/status/<token>/?format=json
-> {"ok":true,"status":{"number":"SK-2026-000001","stage":"ready",...}}
```

Zalety: zero duplikacji kryptografii i zapytań SQL, wdrożenie na dowolnym
hostingu (nawet statycznym + mały proxy), a repozytorium A pozostaje jedynym
źródłem prawdy o statusach. Wada: dodatkowe żądanie sieciowe i druga domena do
utrzymania; link w SMS-ie musi wskazywać na wybraną wersję strony (adres bierze
się z `site_url` w konfiguracji panelu).

Wariant alternatywny (jeśli właściciel chce dwa niezależne backendy): repo B
zawiera `panel/api/status.php`, `panel/views/status.php`, `public/tracking/`
oraz biblioteki `panel/lib/db.php`, `tickets.php`, `sms.php`, `mailer.php`
i własny `config.php`. Wtedy trzeba pilnować, żeby oba repozytoria miały tę samą
wersję `tickets.php` (token HMAC i mapowanie etapów), bo inaczej linki przestaną
działać po jednej ze stron.

---

## 3. Jak wypchnąć repozytorium A

Klon roboczy jest gotowy w `D:\Deepseekapp\serwis\_exports\studio-komp-www`
(pełna historia, bez `node_modules`, 6,4 MB).

```powershell
cd D:\Deepseekapp\serwis\_exports\studio-komp-www
git remote add github git@github.com:<konto>/<nazwa-repo-A>.git
git push -u github main
```

Jeśli GitHub ma być źródłem prawdy także dla codziennej pracy, można zamienić
remote `origin` (VPS) na `github` i wypychać na oba:

```powershell
git remote set-url --add --push origin git@github.com:<konto>/<nazwa-repo-A>.git
```

(Uwaga: publikacja `pnpm publish:remote` wypycha na `origin`, więc VPS musi
zostać jednym z adresów `origin`.)

Repozytorium może być prywatne - kod nie zawiera sekretów: konfiguracja panelu,
klucz tokenów śledzenia, baza SQLite i dane klientów żyją poza repozytorium.

---

## 4. Jak przygotować repozytorium B (wariant rekomendowany)

Minimalny zestaw plików nowego projektu:

```
client-status/
  README.md
  config.php            # adres API (site_url), opcjonalny cache
  index.php             # router: /<token> -> pobranie JSON -> render
  views/status.php      # ten sam układ co w repo A (bilet + timeline)
  public/tracking/      # status.css + fonts/*.woff2 (kopiowane z repo A)
```

Kopiowanie widoku i stylów z repo A (jednorazowo):

```powershell
$src = 'D:\Deepseekapp\serwis\Piotrekv3'
$dst = 'D:\Deepseekapp\serwis\_exports\client-status'
New-Item -ItemType Directory -Force "$dst\views","$dst\public" | Out-Null
Copy-Item "$src\panel\views\status.php" "$dst\views\status.php"
Copy-Item "$src\public\tracking" "$dst\public\tracking" -Recurse
```

Czego **nie** kopiować: `panel/lib/*`, `panel/api/status.php` (wariant
rekomendowany korzysta z API), bazy, konfiguracji panelu.

---

## 5. Czego jeszcze brakuje

1. Nazwy i widoczność obu repozytoriów na koncie GitHub (właściciel tworzy je
   sam) oraz decyzja, czy repo B to wariant rekomendowany (JSON API), czy
   niezależny backend.
2. Klucz SSH do GitHuba na tej maszynie (`github_serwiskit`, `github_plany2`
   należą do innych projektów) albo token dostępu.
3. Po utworzeniu repozytoriów: `git push` (sekcja 3) i ewentualne ustawienie
   GitHub Actions dla `pnpm build` (opcjonalne - build i tak wykonuje się
   lokalnie).
