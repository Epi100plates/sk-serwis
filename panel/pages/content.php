<?php
/**
 * Lista treści wybranego typu (uslugi, blog, marki, strony).
 * Adres: /panel/content/<typ> — tabs typów, liczniki, wyszukiwanie (GET q),
 * filtr statusu (GET status=draft|published|all) i usuwanie (POST + CSRF).
 */

if (!function_exists('layout_head')) {
    require_once dirname(__DIR__) . '/views/layout.php';
}

$readQuery = function (string $name, string $default = ''): string {
    $value = $_GET[$name] ?? $default;
    return is_string($value) ? $value : $default;
};
$text = function ($value): string {
    return is_scalar($value) ? (string) $value : '';
};

$type = (string) ($arg1 ?? '');
$types = content_types();

if (!isset($types[$type])) {
    http_response_code(404);
    layout_head('Nieznana sekcja treści', '');
    echo '<div class="alert alert--err">Nie znaleziono typu treści. <a href="' . e(panel_url('')) . '">Wróć do pulpitu</a>.</div>';
    layout_foot();
    exit;
}

$definition = $types[$type];
$siteUrl = rtrim((string) cfg('site_url'), '/');
$urlPrefix = (string) ($definition['url_prefix'] ?? '/');

$publicPath = function (string $slug) use ($urlPrefix): string {
    return $urlPrefix . $slug . '/';
};
$productionUrl = function (string $slug) use ($siteUrl, $publicPath): string {
    return $siteUrl . $publicPath($slug);
};

$q = trim($readQuery('q'));
$status = $readQuery('status', 'all');
if (!in_array($status, ['all', 'published', 'draft'], true)) {
    $status = 'all';
}

$backQuery = [];
if ($q !== '') {
    $backQuery['q'] = $q;
}
if ($status !== 'all') {
    $backQuery['status'] = $status;
}
$listUrl = panel_url('content/' . $type, $backQuery);

/* --------------------------------------------------------------- POST */

if (is_post()) {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $action = is_string($action) ? $action : '';

    if ($action === 'delete') {
        $deleteId = $_POST['id'] ?? '';
        $deleteId = is_string($deleteId) ? trim($deleteId) : '';
        if (!valid_slug($deleteId) || read_content($type, $deleteId) === null) {
            flash('err', 'Nie znaleziono treści do usunięcia.');
        } else {
            delete_content($type, $deleteId, 'Panel: usunięcie ' . $type . '/' . $deleteId);
            flash('ok', 'Usunięto treść: ' . $deleteId . '.');
        }
        redirect_to($listUrl);
    }

    flash('err', 'Nieznana operacja.');
    redirect_to($listUrl);
}

/* --------------------------------------------------------------- dane */

$items = list_content($type);

$typeStats = [];
foreach (array_keys($types) as $tabType) {
    $tabItems = $tabType === $type ? $items : list_content($tabType);
    $drafts = 0;
    foreach ($tabItems as $tabItem) {
        if (!empty($tabItem['data']['draft'])) {
            $drafts++;
        }
    }
    $typeStats[$tabType] = [
        'all' => count($tabItems),
        'draft' => $drafts,
        'published' => count($tabItems) - $drafts,
    ];
}
$stats = $typeStats[$type] ?? ['all' => count($items), 'draft' => 0, 'published' => count($items)];

$qLower = mb_strtolower($q);
$qSlug = $q !== '' ? slugify($q) : '';
$filtered = [];
foreach ($items as $item) {
    if ($status === 'draft' && empty($item['data']['draft'])) {
        continue;
    }
    if ($status === 'published' && !empty($item['data']['draft'])) {
        continue;
    }
    if ($qLower !== '') {
        $haystack = implode(' ', array_filter([
            $text($item['data']['title'] ?? ''),
            $text($item['data']['h1'] ?? ''),
            $text($item['data']['brand'] ?? ''),
            $text($item['data']['navLabel'] ?? ''),
            $text($item['data']['description'] ?? ''),
            (string) $item['id'],
        ], static fn(string $value): bool => $value !== ''));
        $hit = mb_strpos(mb_strtolower($haystack), $qLower) !== false;
        if (!$hit && $qSlug !== '') {
            $hit = str_contains(slugify($haystack), $qSlug);
        }
        if (!$hit) {
            continue;
        }
    }
    $filtered[] = $item;
}

$dateLabel = function (array $data, int $mtime, string $path): string {
    $raw = $data['updated'] ?? $data['pubDate'] ?? null;
    // Defensywnie: yaml_parse_simple() w lib gubi wiersze zaraz po listach,
    // więc datę odczytujemy wprost z frontmattera pliku.
    if (is_file($path)) {
        $rawFile = (string) file_get_contents($path);
        if (preg_match('/^---\s*\n(.*?)\n---/s', $rawFile, $frontmatter)) {
            if (preg_match('/^updated:\s*(.+)$/m', $frontmatter[1], $dateMatch)) {
                $raw = trim($dateMatch[1], " \t\"'");
            } elseif (preg_match('/^pubDate:\s*(.+)$/m', $frontmatter[1], $dateMatch)) {
                $raw = trim($dateMatch[1], " \t\"'");
            }
        }
    }
    if (is_scalar($raw) && trim((string) $raw) !== '') {
        return format_date(trim((string) $raw), false);
    }
    return format_date(date('Y-m-d H:i:s', $mtime));
};

layout_head($definition['label'], $type);
?>

<div class="tabs" aria-label="Typy treści">
  <?php foreach ($types as $tabType => $tabDefinition): ?>
    <a class="<?= $tabType === $type ? 'is-active' : '' ?>" href="<?= e(panel_url('content/' . $tabType)) ?>">
      <?= e($tabDefinition['label']) ?>
      <span class="badge badge--muted"><?= (int) ($typeStats[$tabType]['all'] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<section class="card">
  <header class="card__head">
    <h2><?= e($definition['label']) ?></h2>
    <div class="card__actions">
      <span class="small muted">
        Wszystkie: <strong><?= (int) $stats['all'] ?></strong> ·
        Opublikowane: <strong><?= (int) $stats['published'] ?></strong> ·
        Szkice: <strong><?= (int) $stats['draft'] ?></strong>
      </span>
      <a class="btn btn--sm btn--primary" href="<?= e(panel_url('editor/' . $type)) ?>">+ Dodaj nową</a>
    </div>
  </header>
  <div class="card__body card__body--flush">
    <form class="toolbar" method="get" action="<?= e(panel_url('content/' . $type)) ?>" style="padding:14px 18px 0">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Szukaj: tytuł, H1, marka, adres…" aria-label="Szukaj treści">
      <select name="status" aria-label="Filtr statusu">
        <option value="all"<?= $status === 'all' ? ' selected' : '' ?>>Wszystkie (<?= (int) $stats['all'] ?>)</option>
        <option value="published"<?= $status === 'published' ? ' selected' : '' ?>>Opublikowane (<?= (int) $stats['published'] ?>)</option>
        <option value="draft"<?= $status === 'draft' ? ' selected' : '' ?>>Szkice (<?= (int) $stats['draft'] ?>)</option>
      </select>
      <button class="btn btn--sm" type="submit">Filtruj</button>
      <?php if ($q !== '' || $status !== 'all'): ?>
        <a class="btn btn--sm btn--ghost" href="<?= e(panel_url('content/' . $type)) ?>">Wyczyść filtry</a>
      <?php endif; ?>
      <span class="spacer"></span>
      <span class="small muted"><?= count($filtered) ?> z <?= count($items) ?></span>
    </form>

    <?php if (!$items): ?>
      <div class="empty" style="margin:16px 18px 18px">
        Brak treści tego typu. <a href="<?= e(panel_url('editor/' . $type)) ?>">Dodaj pierwszą</a>.
      </div>
    <?php elseif (!$filtered): ?>
      <div class="empty" style="margin:16px 18px 18px">
        Brak wyników dla wybranego filtra. <a href="<?= e(panel_url('content/' . $type)) ?>">Wyczyść filtry</a>.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Tytuł</th>
              <th>Adres URL</th>
              <th>Status</th>
              <th>Data</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($filtered as $item): ?>
            <?php
            $data = $item['data'];
            $itemId = (string) $item['id'];
            if ($type === 'marki') {
                $mainTitle = $text($data['brand'] ?? '');
                if ($mainTitle === '') {
                    $mainTitle = $text($data['title'] ?? '');
                }
                if ($mainTitle === '') {
                    $mainTitle = $itemId;
                }
                $subTitle = $text($data['h1'] ?? '');
            } else {
                $mainTitle = $text($data['title'] ?? '');
                if ($mainTitle === '') {
                    $mainTitle = $text($data['h1'] ?? '');
                }
                if ($mainTitle === '') {
                    $mainTitle = $itemId;
                }
                $subTitle = '';
                if ($type === 'uslugi') {
                    $subTitle = $text($data['group'] ?? '');
                } elseif ($type === 'blog') {
                    $subTitle = $text($data['category'] ?? '');
                } elseif ($type === 'strony') {
                    $subTitle = $text($data['navLabel'] ?? '');
                    if ($subTitle === '') {
                        $subTitle = $text($data['h1'] ?? '');
                    }
                }
            }
            ?>
            <tr>
              <td>
                <div class="row-title">
                  <strong><?= e($mainTitle) ?></strong>
                  <?php if ($subTitle !== ''): ?><small><?= e($subTitle) ?></small><?php endif; ?>
                </div>
              </td>
              <td class="mono nowrap">
                <a href="<?= e($productionUrl($itemId)) ?>" target="_blank" rel="noopener"><?= e($publicPath($itemId)) ?></a>
              </td>
              <td><?= !empty($data['draft']) ? status_badge('draft') : status_badge('published') ?></td>
              <td class="nowrap muted small"><?= e($dateLabel($data, (int) $item['mtime'], (string) $item['path'])) ?></td>
              <td class="nowrap" style="text-align:right">
                <a class="btn btn--sm" href="<?= e(panel_url('editor/' . $type . '/' . $itemId)) ?>">Edytuj</a>
                <a class="btn btn--sm btn--ghost" href="<?= e($productionUrl($itemId)) ?>" target="_blank" rel="noopener">Na stronie ↗</a>
                <form method="post" action="<?= e($listUrl) ?>" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= e($itemId) ?>">
                  <button class="btn btn--sm btn--danger" type="submit" data-confirm="<?= e('Usunąć treść „' . $mainTitle . '”? Obecna wersja zostanie zapisana w historii, plik zostanie usunięty.') ?>">Usuń</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php layout_foot(); ?>
