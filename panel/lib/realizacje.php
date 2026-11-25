<?php
/**
 * Realizacje: kategorie, wpisy, eksport do src/data/realizacje.json
 * oraz jednorazowy seed z pliku w repozytorium.
 *
 * Plik JSON jest źródłem danych dla strony statycznej (src/pages/realizacje),
 * dlatego każdy zapis w panelu od razu odświeża plik i robi commit w Git.
 */

/**
 * Uzupełnia schemat o kolumnę `art` (starsze instalacje).
 * Wywoływane na początku funkcji korzystających z tabeli realizacje.
 */
function realizacje_schema_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db_ensure_column(db(), 'realizacje', 'art', "TEXT NOT NULL DEFAULT ''");
    $done = true;
}

function realizacje_note(): string
{
    return 'Realizacje zarządzane z panelu /panel (kategorie, galerie, before/after). Ten plik jest generowany automatycznie przy publikacji — nie edytuj ręcznie na produkcji.';
}

/* -------------------------------------------------------------- kategorie */

function realizacje_categories(): array
{
    return db()->query('SELECT * FROM realizacje_categories ORDER BY sort ASC, id ASC')->fetchAll();
}

/**
 * @return array{ok: bool, errors: string[], id?: int}
 */
function realizacje_category_save(array $d): array
{
    $errors = [];
    $id = (int) ($d['id'] ?? 0);
    $slug = strtolower(trim((string) ($d['slug'] ?? '')));
    $label = trim((string) ($d['label'] ?? ''));
    $sort = (int) ($d['sort'] ?? 50);

    if ($slug === '') {
        $slug = slugify($label);
    }
    $slug = str_limit($slug, 80);

    if ($slug === '') {
        $errors[] = 'Slug jest wymagany (małe litery, cyfry, myślniki).';
    } elseif (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        $errors[] = 'Slug może zawierać tylko małe litery bez polskich znaków, cyfry i myślniki.';
    }
    if ($label === '') {
        $errors[] = 'Nazwa kategorii jest wymagana.';
    }

    if ($slug !== '') {
        $st = db()->prepare('SELECT id FROM realizacje_categories WHERE slug = ? AND id <> ?');
        $st->execute([$slug, $id]);
        if ($st->fetchColumn() !== false) {
            $errors[] = 'Kategoria o slug „' . $slug . '” już istnieje.';
        }
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    if ($id > 0) {
        $st = db()->prepare('SELECT slug FROM realizacje_categories WHERE id = ?');
        $st->execute([$id]);
        $oldSlug = (string) ($st->fetchColumn() ?: '');
        if ($oldSlug === '') {
            return ['ok' => false, 'errors' => ['Nie znaleziono kategorii do edycji.']];
        }

        db()->prepare('UPDATE realizacje_categories SET slug = ?, label = ?, sort = ? WHERE id = ?')
            ->execute([$slug, $label, $sort, $id]);

        // Zmiana sluga musi przenieść przypisania istniejących realizacji.
        if ($oldSlug !== $slug) {
            db()->prepare('UPDATE realizacje SET category_slug = ?, updated_at = ? WHERE category_slug = ?')
                ->execute([$slug, now(), $oldSlug]);
        }

        audit('realizacje_category_update', $slug, ['id' => $id, 'old_slug' => $oldSlug]);
    } else {
        db()->prepare('INSERT INTO realizacje_categories (slug, label, sort, created_at) VALUES (?,?,?,?)')
            ->execute([$slug, $label, $sort, now()]);
        $id = (int) db()->lastInsertId();
        audit('realizacje_category_create', $slug, ['id' => $id]);
    }

    return ['ok' => true, 'errors' => [], 'id' => $id];
}

/**
 * Usuwa kategorię. Odmawia, jeśli są do niej przypisane realizacje.
 *
 * @throws RuntimeException
 */
function realizacje_category_delete(int $id): void
{
    $st = db()->prepare('SELECT * FROM realizacje_categories WHERE id = ?');
    $st->execute([$id]);
    $category = $st->fetch();
    if (!$category) {
        return;
    }

    $st = db()->prepare('SELECT COUNT(*) FROM realizacje WHERE category_slug = ?');
    $st->execute([$category['slug']]);
    $count = (int) $st->fetchColumn();
    if ($count > 0) {
        throw new RuntimeException(
            'Nie można usunąć kategorii „' . $category['label'] . '” — przypisano do niej '
            . $count . ' realizacji. Najpierw zmień ich kategorię.'
        );
    }

    db()->prepare('DELETE FROM realizacje_categories WHERE id = ?')->execute([$id]);
    audit('realizacje_category_delete', (string) $category['slug'], ['id' => $id]);
}

/* ------------------------------------------------------------ realizacje */

/**
 * @param array{q?: string, category?: string, status?: string} $filters
 */
function realizacje_list(array $filters = []): array
{
    realizacje_seed_if_empty();
    realizacje_schema_ensure();

    $sql = 'SELECT * FROM realizacje WHERE 1=1';
    $params = [];

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql .= ' AND (title LIKE ? OR slug LIKE ? OR brand LIKE ? OR model LIKE ? OR summary LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }

    $category = trim((string) ($filters['category'] ?? ''));
    if ($category !== '') {
        $sql .= ' AND category_slug = ?';
        $params[] = $category;
    }

    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '') {
        $sql .= ' AND status = ?';
        $params[] = $status;
    }

    $sql .= ' ORDER BY sort ASC, id ASC';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function realizacje_get(int $id): ?array
{
    realizacje_schema_ensure();
    $st = db()->prepare('SELECT * FROM realizacje WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Zapisuje realizację (insert lub update).
 *
 * Obsługuje pola formularza panelu: poza kolumnami tabeli także
 * `before_after_enabled`, `before_after_before`, `before_after_after`
 * oraz galerię jako `gallery[src][]` / `gallery[alt][]`.
 *
 * @return array{ok: bool, errors: string[], id: int}
 */
function realizacje_save(array $d): array
{
    realizacje_schema_ensure();

    $errors = [];
    $id = (int) ($d['id'] ?? 0);
    $title = trim((string) ($d['title'] ?? ''));
    $slug = strtolower(trim((string) ($d['slug'] ?? '')));

    if ($slug === '') {
        $slug = slugify($title);
    }

    $categorySlug = trim((string) ($d['category_slug'] ?? ''));
    $status = trim((string) ($d['status'] ?? ''));
    $sort = (int) ($d['sort'] ?? 50);
    $realizationDate = trim((string) ($d['realization_date'] ?? ''));

    $simpleFields = [
        'brand' => 80,
        'model' => 80,
        'summary' => 400,
        'problem' => 20000,
        'diagnosis' => 20000,
        'repair' => 20000,
        'result' => 20000,
        'time_label' => 40,
        'image' => 500,
        'art' => 80,
        'seo_title' => 200,
        'seo_description' => 320,
    ];
    $values = [];
    foreach ($simpleFields as $key => $limit) {
        $values[$key] = str_limit(trim((string) ($d[$key] ?? '')), $limit);
    }

    /* --- walidacja --- */
    if ($title === '') {
        $errors[] = 'Tytuł jest wymagany.';
    }
    $slug = str_limit($slug, 120);
    if ($slug === '') {
        $errors[] = 'Slug jest wymagany.';
    } elseif (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        $errors[] = 'Slug może zawierać tylko małe litery bez polskich znaków, cyfry i myślniki.';
    } else {
        $st = db()->prepare('SELECT id FROM realizacje WHERE slug = ? AND id <> ?');
        $st->execute([$slug, $id]);
        if ($st->fetchColumn() !== false) {
            $errors[] = 'Realizacja o tym slug już istnieje.';
        }
    }

    if ($categorySlug === '') {
        $errors[] = 'Wybierz kategorię realizacji.';
    } else {
        $st = db()->prepare('SELECT id FROM realizacje_categories WHERE slug = ?');
        $st->execute([$categorySlug]);
        if ($st->fetchColumn() === false) {
            $errors[] = 'Wybrana kategoria nie istnieje.';
        }
    }

    if ($status === '') {
        $status = 'published';
    } elseif (!in_array($status, ['draft', 'published'], true)) {
        $errors[] = 'Nieprawidłowy status (dozwolone: draft, published).';
    }

    if ($realizationDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $realizationDate)) {
        $errors[] = 'Data realizacji musi być w formacie RRRR-MM-DD.';
    }

    /* --- galeria: {src, alt} --- */
    $galleryRaw = $d['gallery'] ?? [];
    if (is_string($galleryRaw)) {
        $decoded = json_decode($galleryRaw, true);
        $galleryRaw = is_array($decoded) ? $decoded : [];
    }
    $gallery = [];
    if (is_array($galleryRaw)) {
        $sources = $galleryRaw['src'] ?? null;
        if (is_array($sources)) {
            $alts = is_array($galleryRaw['alt'] ?? null) ? array_values($galleryRaw['alt']) : [];
            foreach (array_values($sources) as $i => $src) {
                $src = trim((string) $src);
                if ($src === '') {
                    continue;
                }
                $gallery[] = [
                    'src' => str_limit($src, 500),
                    'alt' => str_limit(trim((string) ($alts[$i] ?? '')), 300),
                ];
            }
        } else {
            foreach ($galleryRaw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $src = trim((string) ($row['src'] ?? ''));
                if ($src === '') {
                    continue;
                }
                $gallery[] = [
                    'src' => str_limit($src, 500),
                    'alt' => str_limit(trim((string) ($row['alt'] ?? '')), 300),
                ];
            }
        }
    }
    $galleryJson = json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($galleryJson === false) {
        $galleryJson = '[]';
    }

    /* --- before/after --- */
    $beforeEnabled = !empty($d['before_after_enabled']);
    $before = trim((string) ($d['before_after_before'] ?? ''));
    $after = trim((string) ($d['before_after_after'] ?? ''));
    $rawBeforeAfter = $d['before_after'] ?? null;
    if (is_array($rawBeforeAfter)) {
        $before = trim((string) ($rawBeforeAfter['before'] ?? $before));
        $after = trim((string) ($rawBeforeAfter['after'] ?? $after));
        $beforeEnabled = true;
    } elseif (is_string($rawBeforeAfter) && trim($rawBeforeAfter) !== '') {
        $decoded = json_decode($rawBeforeAfter, true);
        if (is_array($decoded)) {
            $before = trim((string) ($decoded['before'] ?? $before));
            $after = trim((string) ($decoded['after'] ?? $after));
            $beforeEnabled = true;
        }
    }

    $beforeAfterJson = '';
    if ($beforeEnabled) {
        if ($before === '' || $after === '') {
            $errors[] = 'Slider „przed / po” wymaga obu adresów: przed i po.';
        } else {
            $beforeAfterJson = (string) json_encode(
                ['before' => str_limit($before, 500), 'after' => str_limit($after, 500)],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'id' => $id];
    }

    $now = now();
    if ($id > 0) {
        $st = db()->prepare('SELECT id FROM realizacje WHERE id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn() === false) {
            return ['ok' => false, 'errors' => ['Nie znaleziono realizacji do edycji.'], 'id' => 0];
        }

        $sql = 'UPDATE realizacje SET slug = ?, title = ?, category_slug = ?, brand = ?, model = ?,'
            . ' summary = ?, problem = ?, diagnosis = ?, repair = ?, result = ?, time_label = ?,'
            . ' image = ?, gallery = ?, before_after = ?, realization_date = ?, seo_title = ?,'
            . ' seo_description = ?, status = ?, sort = ?, art = ?, updated_at = ? WHERE id = ?';
        db()->prepare($sql)->execute([
            $slug, $title, $categorySlug, $values['brand'], $values['model'],
            $values['summary'], $values['problem'], $values['diagnosis'], $values['repair'], $values['result'],
            $values['time_label'], $values['image'], $galleryJson, $beforeAfterJson, $realizationDate,
            $values['seo_title'], $values['seo_description'], $status, $sort, $values['art'], $now, $id,
        ]);
        audit('realizacje_update', $slug, ['id' => $id]);
    } else {
        $sql = 'INSERT INTO realizacje (slug, title, category_slug, brand, model, summary, problem,'
            . ' diagnosis, repair, result, time_label, image, gallery, before_after, realization_date,'
            . ' seo_title, seo_description, status, sort, art, created_at, updated_at)'
            . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        db()->prepare($sql)->execute([
            $slug, $title, $categorySlug, $values['brand'], $values['model'],
            $values['summary'], $values['problem'], $values['diagnosis'], $values['repair'], $values['result'],
            $values['time_label'], $values['image'], $galleryJson, $beforeAfterJson, $realizationDate,
            $values['seo_title'], $values['seo_description'], $status, $sort, $values['art'], $now, $now,
        ]);
        $id = (int) db()->lastInsertId();
        audit('realizacje_create', $slug, ['id' => $id]);
    }

    realizacje_export_json();

    return ['ok' => true, 'errors' => [], 'id' => $id];
}

function realizacje_delete(int $id): void
{
    realizacje_schema_ensure();

    $st = db()->prepare('SELECT slug FROM realizacje WHERE id = ?');
    $st->execute([$id]);
    $slug = $st->fetchColumn();
    if ($slug === false) {
        return;
    }

    db()->prepare('DELETE FROM realizacje WHERE id = ?')->execute([$id]);
    audit('realizacje_delete', (string) $slug, ['id' => $id]);

    realizacje_export_json();
}

/* ---------------------------------------------------------------- eksport */

/**
 * Zapisuje src/data/realizacje.json w formacie używanym przez stronę.
 */
function realizacje_export_json(): void
{
    realizacje_schema_ensure();

    $categories = db()->query('SELECT * FROM realizacje_categories ORDER BY sort ASC, id ASC')->fetchAll();
    $rows = db()->query('SELECT * FROM realizacje ORDER BY sort ASC, id ASC')->fetchAll();

    $payload = [
        '_note' => realizacje_note(),
        'categories' => [],
        'items' => [],
    ];

    foreach ($categories as $category) {
        $payload['categories'][] = [
            'slug' => (string) $category['slug'],
            'label' => (string) $category['label'],
            'order' => (int) $category['sort'],
        ];
    }

    foreach ($rows as $row) {
        $gallery = json_decode((string) $row['gallery'], true);
        if (!is_array($gallery)) {
            $gallery = [];
        }
        $galleryOut = [];
        foreach ($gallery as $image) {
            if (!is_array($image)) {
                continue;
            }
            $src = trim((string) ($image['src'] ?? ''));
            if ($src === '') {
                continue;
            }
            $galleryOut[] = [
                'src' => $src,
                'alt' => (string) ($image['alt'] ?? ''),
            ];
        }

        $date = trim((string) $row['realization_date']);
        if ($date === '') {
            $date = substr((string) $row['created_at'], 0, 10);
        }

        $payload['items'][] = [
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'category' => (string) $row['category_slug'],
            'brand' => (string) $row['brand'],
            'model' => (string) $row['model'],
            'summary' => (string) $row['summary'],
            'problem' => (string) $row['problem'],
            'diagnosis' => (string) $row['diagnosis'],
            'repair' => (string) $row['repair'],
            'result' => (string) $row['result'],
            'time' => (string) $row['time_label'],
            'art' => (string) $row['art'],
            'image' => (string) $row['image'],
            'gallery' => $galleryOut,
            'beforeAfter' => realizacje_before_after($row),
            'date' => $date,
            'draft' => (string) $row['status'] === 'draft',
            'seo' => [
                'title' => (string) $row['seo_title'],
                'description' => (string) $row['seo_description'],
            ],
        ];
    }

    $path = function_exists('repo_path')
        ? repo_path('src/data/realizacje.json')
        : rtrim((string) cfg('repo_dir'), '/') . '/src/data/realizacje.json';
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        if (function_exists('log_panel')) {
            log_panel('realizacje_export_json: brak katalogu ' . $dir);
        }
        return;
    }

    if (is_file($path) && function_exists('save_version')) {
        save_version('data:realizacje', 'realizacje', (string) file_get_contents($path));
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        if (function_exists('log_panel')) {
            log_panel('realizacje_export_json: błąd json_encode — ' . json_last_error_msg());
        }
        return;
    }

    $tmp = $path . '.tmp';
    file_put_contents($tmp, $json . "\n");
    rename($tmp, $path);
    @chmod($path, 0664);

    if (function_exists('git_commit')) {
        git_commit('Panel: eksport realizacji', ['src/data/realizacje.json']);
    }
}

/* ------------------------------------------------------------------ seed */

/**
 * Jeśli tabela realizacje jest pusta, przenosi kategorie i pozycje z JSON
 * repozytorium do bazy (idempotentnie — INSERT OR IGNORE).
 */
function realizacje_seed_if_empty(): void
{
    realizacje_schema_ensure();

    $count = (int) db()->query('SELECT COUNT(*) FROM realizacje')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $path = function_exists('repo_path')
        ? repo_path('src/data/realizacje.json')
        : rtrim((string) cfg('repo_dir'), '/') . '/src/data/realizacje.json';
    if (!is_file($path)) {
        return;
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return;
    }

    $timestamp = now();

    $categorySt = db()->prepare('INSERT OR IGNORE INTO realizacje_categories (slug, label, sort, created_at) VALUES (?,?,?,?)');
    $categories = is_array($data['categories'] ?? null) ? $data['categories'] : [];
    foreach ($categories as $index => $category) {
        if (!is_array($category)) {
            continue;
        }
        $slug = trim((string) ($category['slug'] ?? ''));
        $label = trim((string) ($category['label'] ?? ''));
        if ($slug === '' || $label === '') {
            continue;
        }
        $categorySt->execute([$slug, $label, (int) ($category['order'] ?? (($index + 1) * 10)), $timestamp]);
    }

    $itemSt = db()->prepare(
        'INSERT OR IGNORE INTO realizacje (slug, title, category_slug, brand, model, summary, problem,'
        . ' diagnosis, repair, result, time_label, image, gallery, before_after, realization_date,'
        . ' seo_title, seo_description, status, sort, art, created_at, updated_at)'
        . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );

    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        $slug = trim((string) ($item['slug'] ?? ''));
        $title = trim((string) ($item['title'] ?? ''));
        if ($slug === '' || $title === '') {
            continue;
        }

        $gallery = [];
        foreach ((array) ($item['gallery'] ?? []) as $image) {
            if (!is_array($image)) {
                continue;
            }
            $src = trim((string) ($image['src'] ?? ''));
            if ($src === '') {
                continue;
            }
            $gallery[] = ['src' => $src, 'alt' => (string) ($image['alt'] ?? '')];
        }
        $galleryJson = json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';

        $beforeAfter = '';
        $rawBeforeAfter = $item['beforeAfter'] ?? null;
        if (is_array($rawBeforeAfter)) {
            $before = trim((string) ($rawBeforeAfter['before'] ?? ''));
            $after = trim((string) ($rawBeforeAfter['after'] ?? ''));
            if ($before !== '' && $after !== '') {
                $beforeAfter = (string) json_encode(
                    ['before' => $before, 'after' => $after],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
        }

        $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];

        $itemSt->execute([
            $slug,
            $title,
            trim((string) ($item['category'] ?? 'inne')),
            (string) ($item['brand'] ?? ''),
            (string) ($item['model'] ?? ''),
            (string) ($item['summary'] ?? ''),
            (string) ($item['problem'] ?? ''),
            (string) ($item['diagnosis'] ?? ''),
            (string) ($item['repair'] ?? ''),
            (string) ($item['result'] ?? ''),
            (string) ($item['time'] ?? ''),
            (string) ($item['image'] ?? ''),
            $galleryJson,
            $beforeAfter,
            (string) ($item['date'] ?? ''),
            (string) ($seo['title'] ?? ''),
            (string) ($seo['description'] ?? ''),
            !empty($item['draft']) ? 'draft' : 'published',
            (int) ($item['order'] ?? (($index + 1) * 10)),
            (string) ($item['art'] ?? ''),
            $timestamp,
            $timestamp,
        ]);
    }

    audit('realizacje_seed', 'src/data/realizacje.json', ['items' => count($items)]);
}

/* -------------------------------------------------------------- helpery */

/**
 * Dekoduje pole before_after (JSON) do postaci {before, after}.
 *
 * @return array{before: string, after: string}|null
 */
function realizacje_before_after(array $item): ?array
{
    $raw = $item['before_after'] ?? ($item['beforeAfter'] ?? null);

    if (is_array($raw)) {
        $before = trim((string) ($raw['before'] ?? ''));
        $after = trim((string) ($raw['after'] ?? ''));
        return ($before !== '' && $after !== '') ? ['before' => $before, 'after' => $after] : null;
    }

    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $before = trim((string) ($decoded['before'] ?? ''));
    $after = trim((string) ($decoded['after'] ?? ''));
    return ($before !== '' && $after !== '') ? ['before' => $before, 'after' => $after] : null;
}
