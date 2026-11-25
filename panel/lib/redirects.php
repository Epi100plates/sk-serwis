<?php
/**
 * Przekierowania: walidacja, wykrywanie pętli, generowanie plików
 * (nginx snippet, _redirects, .htaccess) i synchronizacja z repozytorium.
 */

function redirects_list(): array
{
    return db()->query('SELECT * FROM redirects ORDER BY old_path ASC')->fetchAll();
}

function redirect_validate(array $data): array
{
    $errors = [];
    $old = trim((string) ($data['old_path'] ?? ''));
    $target = trim((string) ($data['target_url'] ?? ''));
    $code = (int) ($data['status_code'] ?? 301);

    if ($old === '' || $old[0] !== '/') {
        $errors[] = 'Stary adres musi zaczynać się od „/”.';
    } elseif (preg_match('/[\s<>"\']/', $old)) {
        $errors[] = 'Stary adres zawiera niedozwolone znaki.';
    } elseif ($old === '/') {
        $errors[] = 'Nie można przekierowywać strony głównej.';
    }

    if ($target === '') {
        $errors[] = 'Podaj docelowy adres.';
    } elseif (str_starts_with($target, '//')) {
        $errors[] = 'Adres nie może zaczynać się od „//”.';
    } elseif (preg_match('/^\s*(javascript|data|vbscript|file):/i', $target)) {
        $errors[] = 'Niedozwolony protokół w adresie docelowym.';
    } elseif (!str_starts_with($target, '/') && !preg_match('#^https?://#i', $target)) {
        $errors[] = 'Adres docelowy musi być ścieżką (/...) lub pełnym adresem http(s).';
    }

    if (!in_array($code, [301, 302], true)) {
        $errors[] = 'Dozwolone typy przekierowania: 301 i 302.';
    }

    if ($old === $target) {
        $errors[] = 'Adres docelowy jest taki sam jak stary — to pętla.';
    }

    // Wykrywanie pętli w łańcuchu przekierowań
    if (!str_starts_with($target, 'http')) {
        $map = [];
        foreach (redirects_list() as $row) {
            if ((int) $row['enabled'] === 1) {
                $map[$row['old_path']] = $row['target_url'];
            }
        }
        $map[$old] = $target;
        $seen = [];
        $cursor = $old;
        $hops = 0;
        while (isset($map[$cursor]) && $hops < 12) {
            if (isset($seen[$cursor])) {
                $errors[] = 'Wykryto pętlę przekierowań.';
                break;
            }
            $seen[$cursor] = true;
            $cursor = $map[$cursor];
            $hops++;
        }
        if ($hops >= 12) {
            $errors[] = 'Zbyt długi łańcuch przekierowań.';
        }
    }

    return $errors;
}

function redirect_save(array $data): array
{
    $errors = redirect_validate($data);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    $old = trim((string) $data['old_path']);
    $target = trim((string) $data['target_url']);
    $code = (int) $data['status_code'];
    $enabled = !empty($data['enabled']) ? 1 : 0;
    $note = str_limit((string) ($data['note'] ?? ''), 255);
    $id = (int) ($data['id'] ?? 0);

    if ($id > 0) {
        $st = db()->prepare('UPDATE redirects SET old_path=?, target_url=?, status_code=?, enabled=?, note=?, updated_at=? WHERE id=?');
        $st->execute([$old, $target, $code, $enabled, $note, now(), $id]);
    } else {
        $exists = db()->prepare('SELECT id FROM redirects WHERE old_path = ?');
        $exists->execute([$old]);
        if ($exists->fetch()) {
            return ['ok' => false, 'errors' => ['Przekierowanie dla tego adresu już istnieje.']];
        }
        $st = db()->prepare('INSERT INTO redirects (old_path, target_url, status_code, enabled, note, hits, created_at, updated_at) VALUES (?,?,?,?,?,0,?,?)');
        $st->execute([$old, $target, $code, $enabled, $note, now(), now()]);
    }

    redirects_sync_files();
    audit('redirect_save', $old, ['target' => $target]);
    return ['ok' => true];
}

function redirect_delete(int $id): void
{
    $st = db()->prepare('SELECT old_path FROM redirects WHERE id = ?');
    $st->execute([$id]);
    $old = $st->fetchColumn();
    db()->prepare('DELETE FROM redirects WHERE id = ?')->execute([$id]);
    redirects_sync_files();
    audit('redirect_delete', (string) $old);
}

function redirects_sync_files(): void
{
    $rows = db()->query('SELECT old_path, target_url, status_code FROM redirects WHERE enabled = 1 ORDER BY old_path ASC')->fetchAll();

    // 1) nginx snippet
    $nginx = "# Wygenerowane z panelu Studio-Komp — nie edytuj ręcznie.\n# Przekierowania 301/302 obsługiwane przez nginx-sk.\n";
    foreach ($rows as $row) {
        $nginx .= 'location = ' . $row['old_path'] . ' { return ' . (int) $row['status_code'] . ' ' . $row['target_url'] . "; }\n";
    }
    $nginxPath = repo_path('deploy/nginx/sk-redirects.conf');
    @mkdir(dirname($nginxPath), 0775, true);
    file_put_contents($nginxPath, $nginx);

    // 2) _redirects (Netlify / Cloudflare Pages)
    $netlify = "# Wygenerowane z panelu Studio-Komp\n";
    foreach ($rows as $row) {
        $netlify .= $row['old_path'] . ' ' . $row['target_url'] . ' ' . (int) $row['status_code'] . "\n";
    }
    file_put_contents(repo_path('public/_redirects'), $netlify);

    // 3) .htaccess (tylko hosting klasyczny, katalog główny)
    $basePath = repo_path('deploy/htaccess.base');
    $base = is_file($basePath) ? (string) file_get_contents($basePath) : "Options -Indexes\n{{REDIRECTS}}\n";
    $rules = '';
    foreach ($rows as $row) {
        $target = str_starts_with($row['target_url'], 'http')
            ? $row['target_url']
            : rtrim((string) cfg('site_url'), '/') . $row['target_url'];
        $rules .= '  RewriteRule ^' . ltrim(str_replace('/', '', $row['old_path']), '^') . '$ ' . $target . ' [R=' . (int) $row['status_code'] . ',L]' . "\n";
    }
    file_put_contents(repo_path('public/.htaccess'), str_replace('{{REDIRECTS}}', $rules, $base));

    git_commit('Panel: aktualizacja przekierowań', ['deploy/nginx/sk-redirects.conf', 'public/_redirects', 'public/.htaccess']);
}

/**
 * Wczytuje startowy zestaw przekierowań ze starej strony, jeśli tabela jest pusta.
 * Dzięki temu wdrożenie panelu nie gubi istniejących 301.
 */
function redirects_seed_if_empty(): int
{
    $count = (int) db()->query('SELECT COUNT(*) FROM redirects')->fetchColumn();
    if ($count > 0) {
        return 0;
    }
    $seed = [
        ['/o-firmie.html', '/o-serwisie/'],
        ['/oferta/', '/uslugi/'],
        ['/oferta', '/uslugi/'],
        ['/oferta/czyszczenie-i-konserwacja-ukladu-chlodzenia.html', '/czyszczenie-laptopa-wroclaw/'],
        ['/cennik.html', '/cennik/'],
        ['/galeria.html', '/realizacje/'],
        ['/kontakt.html', '/kontakt/'],
        ['/aktualnosci/', '/poradnik/'],
        ['/aktualnosci', '/poradnik/'],
        ['/aktualnosci/index.html', '/poradnik/'],
        ['/aktualnosci/dlaczego-warto-nas-wybrac.html', '/o-serwisie/'],
        ['/aktualnosci/strona-w-przebudowie.html', '/poradnik/'],
        ['/googleads/', '/lp/naprawa-laptopow/'],
        ['/googleads', '/lp/naprawa-laptopow/'],
        ['/mapa/', '/kontakt/'],
        ['/mapa', '/kontakt/'],
    ];
    $st = db()->prepare('INSERT INTO redirects (old_path, target_url, status_code, enabled, note, hits, created_at, updated_at) VALUES (?,?,301,1,?,0,?,?)');
    foreach ($seed as $row) {
        $st->execute([$row[0], $row[1], 'Startowe przekierowanie ze starej strony', now(), now()]);
    }
    redirects_sync_files();
    return count($seed);
}

function redirect_hit(string $path): void
{
    db()->prepare('UPDATE redirects SET hits = hits + 1 WHERE old_path = ?')->execute([$path]);
}
