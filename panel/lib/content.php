<?php
/**
 * Treści: pliki Markdown z frontmatterem (usługi, blog, marki, strony) oraz
 * pliki JSON z danymi strony (site, cennik, opinie, faq, ...).
 *
 * Panel jest źródłem prawdy dla treści, ale publikacja odbywa się przez lokalny
 * build - dlatego każdy zapis trafia od razu do repozytorium Git na VPS.
 */

function content_types(): array
{
    $serviceSelect = [];
    foreach (list_content('uslugi') as $s) {
        $serviceSelect[$s['id']] = $s['data']['navLabel'] ?? $s['id'];
    }

    return [
        'uslugi' => [
            'label' => 'Usługi',
            'label_singular' => 'Usługa',
            'dir' => 'src/content/uslugi',
            'url_prefix' => '/',
            'icon' => 'tools',
            'fields' => [
                ['key' => 'title', 'label' => 'Tytuł SEO (meta title)', 'type' => 'text', 'required' => true, 'hint' => 'Bez nazwy firmy - dopiszemy ją w szablonie.'],
                ['key' => 'navLabel', 'label' => 'Nazwa w menu', 'type' => 'text', 'required' => true],
                ['key' => 'description', 'label' => 'Opis SEO (meta description)', 'type' => 'textarea', 'required' => true, 'hint' => '140-160 znaków.'],
                ['key' => 'h1', 'label' => 'Nagłówek H1', 'type' => 'text', 'required' => true],
                ['key' => 'lead', 'label' => 'Lead pod H1', 'type' => 'textarea', 'required' => true],
                ['key' => 'teaser', 'label' => 'Krótki opis do listy usług', 'type' => 'text', 'required' => true],
                ['key' => 'group', 'label' => 'Kategoria', 'type' => 'select', 'options' => ['Konserwacja', 'Naprawy', 'Elektronika', 'System i dane'], 'required' => true],
                ['key' => 'order', 'label' => 'Kolejność', 'type' => 'number', 'default' => 50],
                ['key' => 'featured', 'label' => 'Pokazuj na stronie głównej', 'type' => 'bool'],
                ['key' => 'tile', 'label' => 'Kafelek w sekcji "Co najczęściej naprawiamy"', 'type' => 'bool', 'hint' => 'Zaznacz, aby usługa pojawiła się jako kafelek na stronie głównej.'],
                ['key' => 'tileTitle', 'label' => 'Tytuł kafelka', 'type' => 'text', 'hint' => 'Puste = nazwa z menu (navLabel).'],
                ['key' => 'tileText', 'label' => 'Opis kafelka (po najechaniu lub kliknięciu)', 'type' => 'textarea', 'hint' => 'Krótko, co obejmuje naprawa. 20-35 słów.'],
                ['key' => 'priceFrom', 'label' => 'Cena', 'type' => 'text', 'hint' => 'np. 200-250 zł. Puste, jeśli cena zależy od modelu.'],
                ['key' => 'priceNote', 'label' => 'Uwaga o cenie', 'type' => 'text'],
                ['key' => 'duration', 'label' => 'Czas realizacji', 'type' => 'text'],
                ['key' => 'image', 'label' => 'Zdjęcie/ilustracja (kafelek i podstrona)', 'type' => 'media'],
                ['key' => 'imageAlt', 'label' => 'Opis zdjęcia (ALT)', 'type' => 'text'],
                ['key' => 'includes', 'label' => 'Co obejmuje usługa', 'type' => 'list'],
                ['key' => 'problems', 'label' => 'Typowe problemy', 'type' => 'list'],
                ['key' => 'faq', 'label' => 'FAQ', 'type' => 'faq'],
                ['key' => 'related', 'label' => 'Powiązane usługi (slugi)', 'type' => 'list'],
                ['key' => 'updated', 'label' => 'Aktualizacja', 'type' => 'date'],
                ['key' => 'draft', 'label' => 'Wersja robocza (nie publikuj)', 'type' => 'bool'],
            ],
        ],
        'blog' => [
            'label' => 'Poradnik',
            'label_singular' => 'Artykuł',
            'dir' => 'src/content/blog',
            'url_prefix' => '/poradnik/',
            'icon' => 'book',
            'fields' => [
                ['key' => 'title', 'label' => 'Tytuł', 'type' => 'text', 'required' => true],
                ['key' => 'description', 'label' => 'Opis SEO (meta description)', 'type' => 'textarea', 'required' => true],
                ['key' => 'pubDate', 'label' => 'Data publikacji', 'type' => 'date', 'required' => true],
                ['key' => 'updatedDate', 'label' => 'Data aktualizacji', 'type' => 'date'],
                ['key' => 'category', 'label' => 'Kategoria', 'type' => 'select', 'options' => ['Naprawa', 'Konserwacja', 'Wydajność', 'Dane i system', 'Zakup i modernizacja', 'Poradnik'], 'required' => true],
                ['key' => 'service', 'label' => 'Powiązana usługa', 'type' => 'select', 'options' => $serviceSelect],
                ['key' => 'readingTime', 'label' => 'Czas czytania (min)', 'type' => 'number'],
                ['key' => 'faq', 'label' => 'FAQ', 'type' => 'faq'],
                ['key' => 'draft', 'label' => 'Wersja robocza (nie publikuj)', 'type' => 'bool'],
            ],
        ],
        'marki' => [
            'label' => 'Marki',
            'label_singular' => 'Podstrona marki',
            'dir' => 'src/content/marki',
            'url_prefix' => '/',
            'icon' => 'laptop',
            'fields' => [
                ['key' => 'brand', 'label' => 'Marka', 'type' => 'text', 'required' => true],
                ['key' => 'title', 'label' => 'Tytuł SEO', 'type' => 'text', 'required' => true],
                ['key' => 'description', 'label' => 'Opis SEO', 'type' => 'textarea', 'required' => true],
                ['key' => 'h1', 'label' => 'Nagłówek H1', 'type' => 'text', 'required' => true],
                ['key' => 'lead', 'label' => 'Lead', 'type' => 'textarea', 'required' => true],
                ['key' => 'problems', 'label' => 'Typowe problemy', 'type' => 'list'],
                ['key' => 'repairs', 'label' => 'Najczęstsze naprawy', 'type' => 'list'],
                ['key' => 'models', 'label' => 'Modele', 'type' => 'list'],
                ['key' => 'services', 'label' => 'Powiązane usługi (slugi)', 'type' => 'list'],
                ['key' => 'faq', 'label' => 'FAQ', 'type' => 'faq'],
                ['key' => 'image', 'label' => 'Zdjęcie', 'type' => 'media'],
                ['key' => 'imageAlt', 'label' => 'Opis zdjęcia (ALT)', 'type' => 'text'],
                ['key' => 'order', 'label' => 'Kolejność', 'type' => 'number', 'default' => 50],
                ['key' => 'review', 'label' => 'Status treści', 'type' => 'select', 'options' => ['pending' => 'Do zatwierdzenia', 'approved' => 'Zatwierdzona'], 'default' => 'pending'],
                ['key' => 'updated', 'label' => 'Aktualizacja', 'type' => 'date'],
                ['key' => 'draft', 'label' => 'Wersja robocza (nie publikuj)', 'type' => 'bool'],
            ],
        ],
        'strony' => [
            'label' => 'Podstrony',
            'label_singular' => 'Podstrona',
            'dir' => 'src/content/strony',
            'url_prefix' => '/',
            'icon' => 'page',
            'fields' => [
                ['key' => 'title', 'label' => 'Tytuł SEO', 'type' => 'text', 'required' => true],
                ['key' => 'description', 'label' => 'Opis SEO', 'type' => 'textarea', 'required' => true],
                ['key' => 'h1', 'label' => 'Nagłówek H1', 'type' => 'text', 'required' => true],
                ['key' => 'lead', 'label' => 'Lead pod H1', 'type' => 'textarea'],
                ['key' => 'navLabel', 'label' => 'Nazwa w menu (opcjonalnie)', 'type' => 'text'],
                ['key' => 'showInNav', 'label' => 'Pokaż w menu', 'type' => 'bool'],
                ['key' => 'order', 'label' => 'Kolejność', 'type' => 'number', 'default' => 50],
                ['key' => 'noindex', 'label' => 'Ukryj w Google (noindex)', 'type' => 'bool'],
                ['key' => 'updated', 'label' => 'Aktualizacja', 'type' => 'date'],
                ['key' => 'draft', 'label' => 'Wersja robocza (nie publikuj)', 'type' => 'bool'],
            ],
        ],
    ];
}

function data_files(): array
{
    return [
        'site' => ['label' => 'Ustawienia strony', 'path' => 'src/data/site.json', 'icon' => 'settings'],
        'cennik' => ['label' => 'Cennik', 'path' => 'src/data/cennik.json', 'icon' => 'tag'],
        'opinie' => ['label' => 'Opinie', 'path' => 'src/data/opinie.json', 'icon' => 'star'],
        'faq' => ['label' => 'FAQ', 'path' => 'src/data/faq.json', 'icon' => 'help'],
        'landing' => ['label' => 'Landing Google Ads', 'path' => 'src/data/landing.json', 'icon' => 'target'],
        'wideo' => ['label' => 'Wideo', 'path' => 'src/data/wideo.json', 'icon' => 'video'],
    ];
}

function repo_path(string $relative): string
{
    return rtrim((string) cfg('repo_dir'), '/') . '/' . ltrim($relative, '/');
}

/* --------------------------------------------------------- frontmatter */

function parse_frontmatter(string $raw): array
{
    $raw = preg_replace("/^\xEF\xBB\xBF/", '', $raw);
    if (!preg_match('/^---\s*\n(.*?)\n---\s*\n?(.*)$/s', $raw, $m)) {
        return ['data' => [], 'body' => trim($raw)];
    }
    return ['data' => yaml_parse_simple($m[1]), 'body' => $m[2]];
}

function yaml_parse_simple(string $yaml): array
{
    $data = [];
    $lines = preg_split('/\r?\n/', $yaml);
    $count = count($lines);
    $i = 0;

    while ($i < $count) {
        $line = $lines[$i];
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            $i++;
            continue;
        }
        if (!preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $line, $m)) {
            $i++;
            continue;
        }
        $key = $m[1];
        $rest = trim($m[2]);

        if ($rest !== '') {
            $data[$key] = yaml_scalar($rest);
            $i++;
            continue;
        }

        // Blok listy/mapy - kazda pozycja zuzywana dokladnie raz.
        $i++;
        $items = [];
        while ($i < $count) {
            $blockLine = $lines[$i];
            if (trim($blockLine) === '') {
                $i++;
                continue;
            }
            $indent = strlen($blockLine) - strlen(ltrim($blockLine));
            if ($indent === 0) {
                break;
            }
            $body = ltrim($blockLine);

            if (preg_match('/^-\s*(.*)$/', $body, $lm)) {
                $value = trim($lm[1]);
                if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $value, $mm)) {
                    $item = [trim($mm[1]) => yaml_scalar($mm[2])];
                    $i++;
                    while ($i < $count) {
                        $cont = $lines[$i];
                        if (trim($cont) === '') {
                            $i++;
                            continue;
                        }
                        $contIndent = strlen($cont) - strlen(ltrim($cont));
                        if ($contIndent <= $indent) {
                            break;
                        }
                        if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', ltrim($cont), $cm)) {
                            $item[trim($cm[1])] = yaml_scalar($cm[2]);
                        }
                        $i++;
                    }
                    $items[] = $item;
                } else {
                    $items[] = yaml_scalar($value);
                    $i++;
                }
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $body, $mm)) {
                $item = [trim($mm[1]) => yaml_scalar($mm[2])];
                $i++;
                while ($i < $count) {
                    $cont = $lines[$i];
                    if (trim($cont) === '') {
                        $i++;
                        continue;
                    }
                    $contIndent = strlen($cont) - strlen(ltrim($cont));
                    if ($contIndent <= $indent) {
                        break;
                    }
                    if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', ltrim($cont), $cm)) {
                        $item[trim($cm[1])] = yaml_scalar($cm[2]);
                    }
                    $i++;
                }
                $items[] = $item;
                continue;
            }

            $i++;
        }

        $data[$key] = $items;
    }

    return $data;
}

function yaml_scalar(string $raw)
{
    $value = trim($raw);
    if ($value === '') {
        return '';
    }
    if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
        return str_replace(['\\"', '\\\\', '\\n'], ['"', '\\', "\n"], substr($value, 1, -1));
    }
    if (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
        return str_replace("''", "'", substr($value, 1, -1));
    }
    $lower = strtolower($value);
    if ($lower === 'true') {
        return true;
    }
    if ($lower === 'false') {
        return false;
    }
    if ($lower === 'null' || $lower === '~') {
        return null;
    }
    if (preg_match('/^-?\d+$/', $value)) {
        return (int) $value;
    }
    if (preg_match('/^-?\d+\.\d+$/', $value)) {
        return (float) $value;
    }
    return $value;
}

function yaml_quote(string $value): string
{
    if ($value === '') {
        return '""';
    }
    $needsQuotes = preg_match('/[:#\n"\\\\]|^\s|\s$|^[-?&*!|>%@`{}\[\],]|^(true|false|null|yes|no|on|off|~\d)/i', $value)
        || is_numeric($value);
    if ($needsQuotes) {
        return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value) . '"';
    }
    return $value;
}

function serialize_frontmatter(array $data, string $body): string
{
    $out = "---\n";
    foreach ($data as $key => $value) {
        if (is_bool($value)) {
            $out .= "$key: " . ($value ? 'true' : 'false') . "\n";
        } elseif (is_int($value) || is_float($value)) {
            $out .= "$key: $value\n";
        } elseif (is_array($value)) {
            if (!$value) {
                $out .= "$key: []\n";
                continue;
            }
            $isList = array_keys($value) === range(0, count($value) - 1);
            $out .= "$key:\n";
            foreach ($value as $item) {
                if (is_array($item)) {
                    $first = true;
                    foreach ($item as $ik => $iv) {
                        $prefix = $first ? '  - ' : '    ';
                        $out .= $prefix . $ik . ': ' . yaml_quote(is_bool($iv) ? ($iv ? 'true' : 'false') : (string) $iv) . "\n";
                        $first = false;
                    }
                } else {
                    $out .= '  - ' . yaml_quote((string) $item) . "\n";
                }
            }
            if (!$isList) {
                // mapa - potraktuj jak listę par (nieużywane w schematach)
            }
        } elseif ($value === null) {
            $out .= "$key: null\n";
        } else {
            $out .= "$key: " . yaml_quote((string) $value) . "\n";
        }
    }
    $out .= "---\n\n" . rtrim($body) . "\n";
    return $out;
}

/* ------------------------------------------------------------- content */

function content_dir(string $type): string
{
    // Statyczna mapa katalogów - NIE wolno tu wołać content_types(),
    // bo content_types() korzysta z list_content() i powstałaby rekurencja.
    $dirs = [
        'uslugi' => 'src/content/uslugi',
        'blog' => 'src/content/blog',
        'marki' => 'src/content/marki',
        'strony' => 'src/content/strony',
    ];
    if (!isset($dirs[$type])) {
        throw new RuntimeException('Nieznany typ treści: ' . $type);
    }
    return repo_path($dirs[$type]);
}

function list_content(string $type): array
{
    $dir = content_dir($type);
    if (!is_dir($dir)) {
        return [];
    }
    $items = [];
    foreach (new DirectoryIterator($dir) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'md') {
            continue;
        }
        $raw = (string) file_get_contents($file->getPathname());
        $parsed = parse_frontmatter($raw);
        $items[] = [
            'id' => $file->getBasename('.md'),
            'data' => $parsed['data'],
            'body' => $parsed['body'],
            'mtime' => $file->getMTime(),
            'path' => $file->getPathname(),
        ];
    }
    usort($items, function ($a, $b) {
        $ao = (int) ($a['data']['order'] ?? 50);
        $bo = (int) ($b['data']['order'] ?? 50);
        if ($ao !== $bo) {
            return $ao <=> $bo;
        }
        return strcmp($a['id'], $b['id']);
    });
    return $items;
}

function read_content(string $type, string $id): ?array
{
    if (!valid_slug($id)) {
        return null;
    }
    $path = content_dir($type) . '/' . $id . '.md';
    if (!is_file($path)) {
        return null;
    }
    $parsed = parse_frontmatter((string) file_get_contents($path));
    return ['id' => $id, 'data' => $parsed['data'], 'body' => $parsed['body'], 'path' => $path];
}

function valid_slug(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', $slug);
}

function slugify(string $text): string
{
    $map = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z',
    ];
    $text = strtr($text, $map);
    $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text ?? '', '-');
}

function write_content(string $type, string $id, array $data, string $body, string $message, ?string $newId = null): array
{
    $dir = content_dir($type);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nie można utworzyć katalogu treści.');
    }

    $targetId = $newId !== null && $newId !== '' ? $newId : $id;
    if (!valid_slug($targetId)) {
        throw new RuntimeException('Nieprawidłowy adres (slug). Dozwolone: małe litery, cyfry, myślniki.');
    }

    $oldFile = $dir . '/' . $id . '.md';
    $newFile = $dir . '/' . $targetId . '.md';
    if ($newId !== null && $newId !== '' && $newId !== $id) {
        if (is_file($newFile)) {
            throw new RuntimeException('Podstrona o tym adresie już istnieje.');
        }
    }

    // Wersja poprzednia (historia i przywracanie)
    if (is_file($oldFile)) {
        save_version($type, $id, (string) file_get_contents($oldFile));
    }

    $content = serialize_frontmatter($data, $body);
    $tmp = $newFile . '.tmp';
    file_put_contents($tmp, $content);
    rename($tmp, $newFile);
    if ($oldFile !== $newFile && is_file($oldFile)) {
        unlink($oldFile);
    }

    git_commit($message, [$dir]);

    return ['id' => $targetId];
}

function delete_content(string $type, string $id, string $message): void
{
    $path = content_dir($type) . '/' . $id . '.md';
    if (!is_file($path)) {
        return;
    }
    save_version($type, $id, (string) file_get_contents($path));
    unlink($path);
    git_commit($message, [dirname($path)]);
}

function save_version(string $kind, string $target, string $content): void
{
    $st = db()->prepare('INSERT INTO content_versions (kind, target, content, username, created_at) VALUES (?,?,?,?,?)');
    $st->execute([$kind, $target, $content, current_user()['username'] ?? 'system', now()]);

    // Retencja: maksymalnie 40 wersji na plik
    $st = db()->prepare('DELETE FROM content_versions WHERE id IN (
        SELECT id FROM content_versions WHERE kind = ? AND target = ?
        ORDER BY id DESC LIMIT -1 OFFSET 40
    )');
    $st->execute([$kind, $target]);
}

function content_versions(string $kind, string $target, int $limit = 15): array
{
    $st = db()->prepare('SELECT id, username, created_at, length(content) AS bytes FROM content_versions WHERE kind = ? AND target = ? ORDER BY id DESC LIMIT ' . (int) $limit);
    $st->execute([$kind, $target]);
    return $st->fetchAll();
}

function restore_version(int $versionId): ?array
{
    $st = db()->prepare('SELECT * FROM content_versions WHERE id = ?');
    $st->execute([$versionId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $path = content_dir($row['kind']) . '/' . $row['target'] . '.md';
    if (is_file($path)) {
        save_version($row['kind'], $row['target'], (string) file_get_contents($path));
    }
    file_put_contents($path, $row['content']);
    git_commit('Przywrócenie wersji: ' . $row['kind'] . '/' . $row['target'], [dirname($path)]);
    return $row;
}

/* ------------------------------------------------------------ data JSON */

function data_read(string $name): array
{
    $files = data_files();
    if (!isset($files[$name])) {
        throw new RuntimeException('Nieznany plik danych: ' . $name);
    }
    $path = repo_path($files[$name]['path']);
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function data_write(string $name, array $data, string $message): void
{
    $files = data_files();
    if (!isset($files[$name])) {
        throw new RuntimeException('Nieznany plik danych: ' . $name);
    }
    $path = repo_path($files[$name]['path']);
    if (is_file($path)) {
        save_version('data:' . $name, $name, (string) file_get_contents($path));
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Nie można zakodować JSON: ' . json_last_error_msg());
    }
    $tmp = $path . '.tmp';
    file_put_contents($tmp, $json . "\n");
    rename($tmp, $path);
    git_commit($message, [dirname($path)]);
}

/* ------------------------------------------------------------------ Git */

function git_commit(string $message, array $paths = []): void
{
    $repo = escapeshellarg((string) cfg('repo_dir'));
    $add = $paths ? implode(' ', array_map('escapeshellarg', $paths)) : '-A';
    $cmd = 'cd ' . $repo . ' && git add ' . $add . ' 2>&1 && git diff --cached --quiet || git -c user.name=' . escapeshellarg('Panel Studio-Komp')
        . ' -c user.email=' . escapeshellarg('panel@' . parse_url((string) cfg('site_url'), PHP_URL_HOST))
        . ' commit -m ' . escapeshellarg($message) . ' 2>&1';
    $output = [];
    exec($cmd, $output, $code);
    if ($code !== 0) {
        log_panel('git commit nie powiódł się: ' . implode(' ', $output));
    } else {
        setting_set('last_git_commit', now() . ' - ' . $message);
    }
}

function log_panel(string $message): void
{
    $dir = (string) cfg('data_dir') . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    @file_put_contents($dir . '/panel.log', '[' . now_iso() . '] ' . $message . "\n", FILE_APPEND);
}
