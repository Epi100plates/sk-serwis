<?php
/**
 * Edytor jednej treści (Markdown z frontmatterem) — generyczny dla typów
 * z content_types(): uslugi, blog, marki, strony.
 *
 * Adres: /panel/editor/<typ>/<id> (puste id = nowa treść).
 * Obsługa pól: text, textarea, date, number, select, bool, list, faq, media.
 * Zapis: write_content() + historia wersji, zmiana sluga przenosi plik.
 */

if (!function_exists('layout_head')) {
    require_once dirname(__DIR__) . '/views/layout.php';
}

$postString = function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
};

$type = (string) ($arg1 ?? '');
$requestedId = trim((string) ($arg2 ?? ''));
$types = content_types();

if (!isset($types[$type])) {
    http_response_code(404);
    layout_head('Nieznana sekcja treści', '');
    echo '<div class="alert alert--err">Nie znaleziono typu treści. <a href="' . e(panel_url('')) . '">Wróć do pulpitu</a>.</div>';
    layout_foot();
    exit;
}

$definition = $types[$type];
$fields = $definition['fields'];
$isNew = $requestedId === '';
$record = $isNew ? null : read_content($type, $requestedId);

if (!$isNew && $record === null) {
    http_response_code(404);
    layout_head('Nie znaleziono treści', $type);
    echo '<div class="alert alert--err">Nie znaleziono treści <span class="mono">' . e($type . '/' . $requestedId) . '</span>. <a href="' . e(panel_url('content/' . $type)) . '">Wróć do listy</a>.</div>';
    layout_foot();
    exit;
}

/* ------------------------------------------------------------- helpery */

$siteUrl = rtrim((string) cfg('site_url'), '/');
$urlPrefix = (string) ($definition['url_prefix'] ?? '/');
$previewBase = trim((string) cfg('preview_url', ''));
if ($previewBase === '') {
    $previewBase = $siteUrl . '/podglad';
}
$previewBase = rtrim($previewBase, '/');

$publicPath = function (string $slug) use ($urlPrefix): string {
    return $urlPrefix . $slug . '/';
};
$productionUrl = function (string $slug) use ($siteUrl, $publicPath): string {
    return $siteUrl . $publicPath($slug);
};
$previewUrl = function (string $slug) use ($previewBase, $publicPath): string {
    return $previewBase . $publicPath($slug);
};

$dateValue = function ($value): string {
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }
    if (is_int($value)) {
        return date('Y-m-d', $value);
    }
    $string = is_scalar($value) ? trim((string) $value) : '';
    if ($string === '') {
        return '';
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $string, $match)) {
        return $match[1];
    }
    $timestamp = strtotime($string);
    return $timestamp !== false ? date('Y-m-d', $timestamp) : '';
};

$listValues = function ($value): array {
    if (is_array($value)) {
        $items = [];
        foreach ($value as $entry) {
            if (!is_scalar($entry)) {
                continue;
            }
            $entry = trim((string) $entry);
            if ($entry !== '') {
                $items[] = $entry;
            }
        }
        return $items;
    }
    $string = is_scalar($value) ? trim((string) $value) : '';
    if ($string === '' || $string === '[]') {
        return [];
    }
    $items = [];
    foreach (preg_split('/\r?\n/', $string) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $items[] = $line;
        }
    }
    return $items;
};
$listText = function ($value) use ($listValues): string {
    return implode("\n", $listValues($value));
};

$faqValues = function ($value): array {
    $pairs = [];
    if (!is_array($value)) {
        return $pairs;
    }
    foreach ($value as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $question = isset($entry['question']) && is_scalar($entry['question']) ? trim((string) $entry['question']) : '';
        $answer = isset($entry['answer']) && is_scalar($entry['answer']) ? trim((string) $entry['answer']) : '';
        if ($question === '' && $answer === '') {
            continue;
        }
        $pairs[] = ['question' => $question, 'answer' => $answer];
    }
    return $pairs;
};

$mediaSource = function (string $value) use ($siteUrl): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^(https?:)?//#i', $value) || str_starts_with($value, 'data:')) {
        return $value;
    }
    return $siteUrl . (str_starts_with($value, '/') ? '' : '/') . $value;
};

/*
 * Defensywny odczyt frontmattera. yaml_parse_simple() z lib/content.php ma
 * znany błąd (podwójne $j++), który gubi co drugą pozycję listy i wiersze
 * zaraz po liście — bez tego edytor wczytałby niepełne dane i nadpisał plik
 * uboższą treścią. Ten parser obsługuje dokładnie format, który zapisuje
 * serialize_frontmatter(). Po naprawie lib można go usunąć.
 */
$safeFrontmatter = function (string $raw): array {
    $raw = (string) preg_replace("/^\xEF\xBB\xBF/", '', $raw);
    if (!preg_match('/^---\s*\n(.*?)\n---\s*\n?(.*)$/s', $raw, $match)) {
        return ['data' => [], 'body' => trim($raw)];
    }

    $lines = preg_split('/\r?\n/', $match[1]) ?: [];
    $data = [];
    $count = count($lines);

    for ($i = 0; $i < $count; $i++) {
        $line = $lines[$i];
        if ($line === '' || preg_match('/^\s*#/', $line)) {
            continue;
        }
        if (!preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $line, $keyMatch)) {
            continue;
        }
        $key = $keyMatch[1];
        $rest = trim($keyMatch[2]);

        if ($rest === '[]' || $rest === '{}') {
            $data[$key] = [];
            continue;
        }
        if ($rest !== '') {
            $data[$key] = yaml_scalar($rest);
            continue;
        }

        $items = [];
        $current = null;
        $j = $i + 1;
        while ($j < $count) {
            $sub = $lines[$j];
            if (preg_match('/^([A-Za-z0-9_]+):/', $sub)) {
                break;
            }
            if (trim($sub) === '' || preg_match('/^\s*#/', $sub)) {
                $j++;
                continue;
            }
            if (preg_match('/^\s+-\s+(.*)$/', $sub, $itemMatch)) {
                if ($current !== null) {
                    $items[] = $current;
                    $current = null;
                }
                $value = trim($itemMatch[1]);
                if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $value, $mapMatch)) {
                    $current = [trim($mapMatch[1]) => yaml_scalar($mapMatch[2])];
                } else {
                    $items[] = yaml_scalar($value);
                }
                $j++;
                continue;
            }
            if ($current !== null && preg_match('/^\s{4,}([A-Za-z0-9_]+):\s*(.*)$/', $sub, $mapMatch)) {
                $current[trim($mapMatch[1])] = yaml_scalar($mapMatch[2]);
                $j++;
                continue;
            }
            if (preg_match('/^\s{2,}([A-Za-z0-9_]+):\s*(.*)$/', $sub, $mapMatch)) {
                if ($current !== null) {
                    $items[] = $current;
                    $current = null;
                }
                $items[] = [trim($mapMatch[1]) => yaml_scalar($mapMatch[2])];
                $j++;
                continue;
            }
            break;
        }
        if ($current !== null) {
            $items[] = $current;
        }
        $data[$key] = $items;
        $i = $j - 1;
    }

    return ['data' => $data, 'body' => ltrim($match[2], "\r\n")];
};

/* ------------------------------------------------------ dane istniejące */

$parsedRecord = null;
if ($record !== null && is_file($record['path'])) {
    $parsedRecord = $safeFrontmatter((string) file_get_contents($record['path']));
}
$recordData = $parsedRecord['data'] ?? ($record['data'] ?? []);

/* ------------------------------------------------------------ wartości */

$values = [];
foreach ($fields as $field) {
    $key = (string) $field['key'];
    $fieldType = (string) ($field['type'] ?? 'text');
    $raw = $recordData[$key] ?? ($field['default'] ?? null);

    switch ($fieldType) {
        case 'bool':
            $values[$key] = (bool) $raw;
            break;
        case 'list':
            $values[$key] = $listText($raw);
            break;
        case 'faq':
            $values[$key] = $faqValues($raw);
            break;
        case 'date':
            $values[$key] = $dateValue($raw);
            break;
        case 'number':
            $values[$key] = ($raw === null || $raw === '') ? '' : (is_scalar($raw) ? (string) $raw : '');
            break;
        default:
            $values[$key] = is_scalar($raw) ? (string) $raw : '';
    }
}
$body = $parsedRecord !== null ? (string) $parsedRecord['body'] : (string) ($record['body'] ?? '');
$slug = $isNew ? '' : (string) $record['id'];
$draftLabel = 'Zapisz jako wersję roboczą (nie publikuj)';
$errors = [];

/* ----------------------------------------------------------- akcje POST */

if (is_post()) {
    csrf_check();
    $action = $postString('action');

    if ($action === 'delete') {
        if ($record !== null) {
            delete_content($type, $requestedId, 'Panel: usunięcie ' . $type . '/' . $requestedId);
            flash('ok', 'Usunięto treść: ' . $requestedId . '.');
        }
        redirect_to(panel_url('content/' . $type));
    }

    if ($action === 'restore') {
        $versionId = (int) $postString('version_id');
        $version = null;
        if ($versionId > 0) {
            $statement = db()->prepare('SELECT * FROM content_versions WHERE id = ?');
            $statement->execute([$versionId]);
            $version = $statement->fetch() ?: null;
        }
        if ($record === null || $version === null || $version['kind'] !== $type || $version['target'] !== $requestedId) {
            flash('err', 'Nie znaleziono wskazanej wersji treści.');
            redirect_to(panel_url('editor/' . $type . '/' . $requestedId));
        }
        restore_version($versionId);
        flash('ok', 'Przywrócono wersję z ' . format_date((string) $version['created_at']) . '.');
        redirect_to(panel_url('editor/' . $type . '/' . $requestedId));
    }

    /* Zapis formularza — zebranie wartości z POST (także przy błędach). */
    foreach ($fields as $field) {
        $key = (string) $field['key'];
        $fieldType = (string) ($field['type'] ?? 'text');

        if ($fieldType === 'bool') {
            $values[$key] = !empty($_POST[$key]);
            continue;
        }

        if ($fieldType === 'faq') {
            $posted = $_POST[$key] ?? [];
            $pairs = [];
            if (is_array($posted)) {
                foreach ($posted as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $question = isset($entry['question']) && is_scalar($entry['question']) ? trim((string) $entry['question']) : '';
                    $answer = isset($entry['answer']) && is_scalar($entry['answer']) ? trim((string) $entry['answer']) : '';
                    if ($question === '' && $answer === '') {
                        continue;
                    }
                    $pairs[] = ['question' => $question, 'answer' => $answer];
                }
            }
            $values[$key] = $pairs;
            continue;
        }

        $values[$key] = $postString($key);
    }
    $body = rtrim($postString('body'));
    $slug = trim($postString('slug'));

    /* Walidacja: pola wymagane i formaty. */
    foreach ($fields as $field) {
        $key = (string) $field['key'];
        $fieldType = (string) ($field['type'] ?? 'text');
        $label = (string) ($field['label'] ?? $key);

        if ($fieldType === 'bool' || $fieldType === 'faq') {
            continue;
        }
        $required = !empty($field['required']);
        if ($fieldType === 'list') {
            if ($required && $listValues($values[$key]) === []) {
                $errors[] = 'Pole „' . $label . '” jest wymagane.';
            }
            continue;
        }
        $stringValue = is_scalar($values[$key] ?? null) ? trim((string) $values[$key]) : '';
        if ($required && $stringValue === '') {
            $errors[] = 'Pole „' . $label . '” jest wymagane.';
            continue;
        }
        if ($fieldType === 'date' && $stringValue !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $stringValue) !== 1) {
            $errors[] = 'Pole „' . $label . '” musi być datą w formacie RRRR-MM-DD.';
        }
        if ($fieldType === 'number' && $stringValue !== '' && !is_numeric($stringValue)) {
            $errors[] = 'Pole „' . $label . '” musi być liczbą.';
        }
    }

    foreach ($fields as $field) {
        if ((string) ($field['type'] ?? 'text') !== 'faq') {
            continue;
        }
        $key = (string) $field['key'];
        $label = (string) ($field['label'] ?? $key);
        foreach (($values[$key] ?? []) as $pair) {
            if ($pair['question'] === '' || $pair['answer'] === '') {
                $errors[] = 'W sekcji „' . $label . '” uzupełnij pytanie i odpowiedź (albo usuń pusty wiersz).';
                break;
            }
        }
    }

    /* Walidacja adresu (slug). */
    if ($isNew && $slug === '') {
        $slug = trim(slugify((string) ($values['title'] ?? '')), '-');
    }
    if ($slug === '') {
        $errors[] = 'Podaj adres (slug) treści.';
    } elseif (!valid_slug($slug)) {
        $errors[] = 'Adres (slug) może zawierać wyłącznie małe litery, cyfry i myślniki (2–81 znaków).';
    } elseif ($isNew || $slug !== $requestedId) {
        if (read_content($type, $slug) !== null) {
            $errors[] = 'Treść o adresie „' . $slug . '” już istnieje — wybierz inny adres.';
        }
    }

    /* Budowa frontmattera i zapis. */
    if ($errors === []) {
        $data = is_array($recordData) ? $recordData : [];
        foreach ($fields as $field) {
            $key = (string) $field['key'];
            $fieldType = (string) ($field['type'] ?? 'text');
            $value = $values[$key] ?? '';

            switch ($fieldType) {
                case 'bool':
                    $data[$key] = (bool) $value;
                    break;
                case 'list':
                    $list = $listValues($value);
                    if ($list === []) {
                        unset($data[$key]);
                    } else {
                        $data[$key] = $list;
                    }
                    break;
                case 'faq':
                    $pairs = $faqValues($value);
                    if ($pairs === []) {
                        unset($data[$key]);
                    } else {
                        $data[$key] = $pairs;
                    }
                    break;
                case 'number':
                    $number = is_scalar($value) ? trim((string) $value) : '';
                    if ($number === '') {
                        unset($data[$key]);
                    } else {
                        $data[$key] = str_contains($number, '.') ? (float) $number : (int) $number;
                    }
                    break;
                case 'date':
                    $date = is_scalar($value) ? trim((string) $value) : '';
                    if ($date === '') {
                        unset($data[$key]);
                    } else {
                        $data[$key] = $date;
                    }
                    break;
                default:
                    $stringValue = is_scalar($value) ? trim((string) $value) : '';
                    if ($stringValue === '') {
                        unset($data[$key]);
                    } else {
                        $data[$key] = $stringValue;
                    }
            }
        }

        $savedId = $isNew ? $slug : $requestedId;
        try {
            $result = write_content(
                $type,
                $savedId,
                $data,
                $body,
                'Panel: zapis ' . $type . '/' . $savedId,
                (!$isNew && $slug !== $requestedId) ? $slug : null
            );
            $savedId = (string) ($result['id'] ?? $savedId);
            flash('ok', 'Zapisano ' . mb_strtolower((string) $definition['label_singular']) . ': ' . $savedId . '.');
            if ($postString('intent') === 'preview') {
                flash('info', 'Podgląd wymaga zbudowania wersji podglądowej — jeśli link nie działa, zleć publikację w sekcji Publikacja.');
                redirect_to($previewUrl($savedId));
            }
            redirect_to(panel_url('editor/' . $type . '/' . $savedId));
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

/* ------------------------------------------------------ dane boczne */

$versions = $isNew ? [] : content_versions($type, $requestedId);
$modifiedLabel = '—';
if ($record !== null && is_file($record['path'])) {
    $modifiedLabel = format_date(date('Y-m-d H:i:s', (int) filemtime($record['path'])));
}
$lastAuthor = $versions ? (string) ($versions[0]['username'] ?? '') : '';

$formAction = $isNew
    ? panel_url('editor/' . $type)
    : panel_url('editor/' . $type . '/' . $requestedId);
$deleteLabel = is_scalar($values['title'] ?? null) ? trim((string) $values['title']) : '';
if ($deleteLabel === '') {
    $deleteLabel = $requestedId;
}

$counterLimits = ['title' => 60, 'description' => 160];
$slugSourceKey = 'title';

layout_head($isNew ? 'Nowa treść: ' . $definition['label_singular'] : 'Edytuj: ' . $definition['label_singular'], $type);
?>

<div class="breadcrumbs">
  <a href="<?= e(panel_url('content/' . $type)) ?>"><?= e($definition['label']) ?></a>
  <span>›</span>
  <span><?= $isNew ? 'Nowa treść' : e($requestedId) ?></span>
</div>

<?php if ($errors): ?>
  <div class="alert alert--err">
    <strong>Nie zapisano zmian — popraw poniższe błędy:</strong>
    <ul style="margin:6px 0 0; padding-left:18px">
      <?php foreach ($errors as $error): ?>
        <li><?= e($error) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="grid grid--sidebar">
  <form class="grid" method="post" action="<?= e($formAction) ?>" data-editor>
    <?= csrf_field() ?>

    <section class="card">
      <header class="card__head">
        <h2>Pola treści</h2>
        <div class="card__actions"><span class="small muted"><?= e($definition['label']) ?></span></div>
      </header>
      <div class="card__body">
        <div class="form-grid">
          <label class="field">
            <span>Adres (slug) *</span>
            <input type="text" name="slug" value="<?= e($slug) ?>" required
                   pattern="[a-z0-9][a-z0-9-]{1,80}" autocomplete="off" spellcheck="false"
                   data-slug-field<?= $isNew ? ' data-autoslug="1"' : '' ?>
                   data-slug-prefix="<?= e($urlPrefix) ?>">
            <span class="field__hint">
              Adres: <code class="code-inline" data-slug-preview><?= e($publicPath($slug !== '' ? $slug : '…')) ?></code>
              <?= $isNew ? '— generowany z tytułu, możesz go zmienić.' : '— zmiana przeniesie plik i zmieni adres treści.' ?>
            </span>
          </label>

          <?php foreach ($fields as $field): ?>
            <?php
            $key = (string) $field['key'];
            $fieldType = (string) ($field['type'] ?? 'text');
            $value = $values[$key] ?? '';
            $label = (string) ($field['label'] ?? $key);
            $hint = (string) ($field['hint'] ?? '');
            $required = !empty($field['required']);
            $counterLimit = (int) ($counterLimits[$key] ?? 0);
            $counterId = $counterLimit > 0 ? $key . '-counter' : '';
            $counterAttr = $counterLimit > 0
                ? ' data-counter="#' . $counterId . '" data-max="' . $counterLimit . '"'
                : '';
            $slugSourceAttr = ($isNew && $key === $slugSourceKey) ? ' data-slug-source' : '';
            ?>
            <?php if ($fieldType === 'bool'): ?>
              <label class="field field--check">
                <input type="checkbox" name="<?= e($key) ?>" value="1"<?= !empty($value) ? ' checked' : '' ?>>
                <span><?= e($key === 'draft' ? $draftLabel : $label) ?></span>
              </label>
            <?php elseif ($fieldType === 'select'): ?>
              <?php $optionList = is_array($field['options'] ?? null) ? $field['options'] : []; ?>
              <label class="field">
                <span><?= e($label) ?><?= $required ? ' *' : '' ?></span>
                <select name="<?= e($key) ?>"<?= $required ? ' required' : '' ?>>
                  <option value="">— wybierz —</option>
                  <?php foreach ($optionList as $optionValue => $optionLabel): ?>
                    <?php
                    if (is_int($optionValue)) {
                        $optionValue = (string) $optionLabel;
                    }
                    ?>
                    <option value="<?= e((string) $optionValue) ?>"<?= (string) $value === (string) $optionValue ? ' selected' : '' ?>><?= e((string) $optionLabel) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if ($hint !== ''): ?><span class="field__hint"><?= e($hint) ?></span><?php endif; ?>
              </label>
            <?php elseif ($fieldType === 'date'): ?>
              <label class="field">
                <span><?= e($label) ?><?= $required ? ' *' : '' ?></span>
                <input type="date" name="<?= e($key) ?>" value="<?= e((string) $value) ?>"<?= $required ? ' required' : '' ?>>
                <?php if ($hint !== ''): ?><span class="field__hint"><?= e($hint) ?></span><?php endif; ?>
              </label>
            <?php elseif ($fieldType === 'number'): ?>
              <label class="field">
                <span><?= e($label) ?><?= $required ? ' *' : '' ?></span>
                <input type="number" name="<?= e($key) ?>" value="<?= e((string) $value) ?>" step="1">
                <?php if ($hint !== ''): ?><span class="field__hint"><?= e($hint) ?></span><?php endif; ?>
              </label>
            <?php elseif ($fieldType === 'list'): ?>
              <?php $listRows = max(3, min(10, substr_count((string) $value, "\n") + 2)); ?>
              <label class="field">
                <span><?= e($label) ?><?= $required ? ' *' : '' ?></span>
                <textarea name="<?= e($key) ?>" rows="<?= (int) $listRows ?>" placeholder="Jedna pozycja w linii"><?= e((string) $value) ?></textarea>
                <span class="field__hint">Jedna pozycja w linii; puste linie są pomijane.<?= $hint !== '' ? ' ' . e($hint) : '' ?></span>
              </label>
            <?php elseif ($fieldType === 'faq'): ?>
              <?php
              $faqRows = $values[$key] ?? [];
              if (!is_array($faqRows) || $faqRows === []) {
                  $faqRows = [['question' => '', 'answer' => '']];
              }
              ?>
              <div class="field" data-faq-list data-faq-key="<?= e($key) ?>">
                <span><?= e($label) ?><?= $required ? ' *' : '' ?></span>
                <div style="display:grid; gap:12px">
                  <?php foreach ($faqRows as $index => $pair): ?>
                    <div data-faq-row data-list-row style="border:1px solid var(--a-line); border-radius:2px; padding:12px">
                      <div class="form-grid" style="gap:10px">
                        <label class="field">
                          <span>Pytanie</span>
                          <input type="text" data-faq-question name="<?= e($key) ?>[<?= (int) $index ?>][question]" value="<?= e((string) ($pair['question'] ?? '')) ?>">
                        </label>
                        <label class="field">
                          <span>Odpowiedź</span>
                          <textarea data-faq-answer name="<?= e($key) ?>[<?= (int) $index ?>][answer]" rows="2"><?= e((string) ($pair['answer'] ?? '')) ?></textarea>
                        </label>
                      </div>
                      <div class="form-actions" style="margin-top:8px">
                        <button type="button" class="btn btn--sm btn--danger" data-list-remove>Usuń pytanie</button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <template data-faq-template>
                  <div data-faq-row data-list-row style="border:1px solid var(--a-line); border-radius:2px; padding:12px">
                    <div class="form-grid" style="gap:10px">
                      <label class="field">
                        <span>Pytanie</span>
                        <input type="text" data-faq-question>
                      </label>
                      <label class="field">
                        <span>Odpowiedź</span>
                        <textarea data-faq-answer rows="2"></textarea>
                      </label>
                    </div>
                    <div class="form-actions" style="margin-top:8px">
                      <button type="button" class="btn btn--sm btn--danger" data-list-remove>Usuń pytanie</button>
                    </div>
                  </div>
                </template>
                <div class="form-actions">
                  <button type="button" class="btn btn--sm" data-faq-add>+ Dodaj pytanie</button>
                </div>
                <?php if ($hint !== ''): ?><span class="field__hint"><?= e($hint) ?></span><?php endif; ?>
              </div>
            <?php elseif ($fieldType === 'media'): ?>
              <?php
              $mediaValue = trim(is_scalar($value) ? (string) $value : '');
              $mediaPreviewSrc = $mediaSource($mediaValue);
              ?>
              <div class="field" data-media-field data-site-url="<?= e($siteUrl) ?>">
                <span><?= e($label) ?><?= $required ? ' *' : '' ?></span>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap">
                  <input type="text" data-media-input name="<?= e($key) ?>" value="<?= e($mediaValue) ?>" placeholder="/images/uploads/…" style="flex:1 1 220px"<?= $required ? ' required' : '' ?>>
                  <a class="btn btn--sm" href="<?= e(panel_url('media')) ?>" target="_blank" rel="noopener">Wybierz z biblioteki mediów ↗</a>
                </div>
                <img class="thumb thumb--wide" data-media-preview<?= $mediaPreviewSrc !== '' ? ' src="' . e($mediaPreviewSrc) . '"' : ' hidden' ?> alt="Podgląd wybranego pliku">
                <span class="field__hint">Wklej ścieżkę pliku z biblioteki mediów albo wybierz go przyciskiem obok.<?= $hint !== '' ? ' ' . e($hint) : '' ?></span>
              </div>
            <?php else: ?>
              <?php $textarea = $fieldType === 'textarea'; ?>
              <label class="field">
                <span><?= e($label) ?><?= $required ? ' *' : '' ?></span>
                <?php if ($textarea): ?>
                  <textarea name="<?= e($key) ?>" rows="3"<?= $counterAttr ?>><?= e((string) $value) ?></textarea>
                <?php else: ?>
                  <input type="text" name="<?= e($key) ?>" value="<?= e((string) $value) ?>"<?= $required ? ' required' : '' ?><?= $slugSourceAttr ?><?= $counterAttr ?>>
                <?php endif; ?>
                <?php if ($hint !== ''): ?><span class="field__hint"><?= e($hint) ?></span><?php endif; ?>
                <?php if ($counterId !== ''): ?><span class="field__hint" id="<?= e($counterId) ?>"></span><?php endif; ?>
              </label>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="card">
      <header class="card__head">
        <h2>Treść (Markdown)</h2>
        <div class="card__actions"><span class="small muted mono">body</span></div>
      </header>
      <div class="card__body">
        <label class="field">
          <span>Treść podstrony</span>
          <textarea name="body" class="mono" style="min-height:420px" rows="20" spellcheck="false"><?= e($body) ?></textarea>
          <span class="field__hint">
            Markdown: <code class="code-inline">## Nagłówek</code>,
            <code class="code-inline">**pogrubienie**</code>,
            <code class="code-inline">- lista</code>,
            <code class="code-inline">[link](adres)</code>.
          </span>
        </label>
      </div>
    </section>

    <div class="form-actions">
      <button class="btn btn--primary" type="submit">Zapisz</button>
      <?php if (!$isNew): ?>
        <button class="btn" type="submit" name="intent" value="preview">Zapisz i podgląd</button>
      <?php endif; ?>
      <a class="btn btn--ghost" href="<?= e(panel_url('content/' . $type)) ?>">Anuluj</a>
      <span class="spacer"></span>
      <span class="small muted">Zapis tworzy commit w repozytorium; publikacja osobno.</span>
    </div>
  </form>

  <div class="grid">
    <section class="card">
      <header class="card__head"><h2>Informacje</h2></header>
      <div class="card__body">
        <ul class="list-plain">
          <li><strong>Adres</strong><span class="mono"><?= e($isNew ? ($slug !== '' ? $publicPath($slug) : '—') : $publicPath($requestedId)) ?></span></li>
          <li><strong>Plik</strong><span class="mono"><?= e($definition['dir'] . '/' . ($isNew ? ($slug !== '' ? $slug : 'slug') : $requestedId) . '.md') ?></span></li>
          <li><strong>Modyfikacja</strong><span><?= e($modifiedLabel) ?></span></li>
          <li><strong>Ostatni autor</strong><span><?= e($lastAuthor !== '' ? $lastAuthor : '—') ?></span></li>
          <li><strong>Status</strong><span><?= !empty($values['draft']) ? status_badge('draft') : status_badge('published') ?></span></li>
        </ul>
        <?php if ($isNew): ?>
          <p class="small muted" style="margin-top:10px">Nowa treść — pełne informacje pojawią się po pierwszym zapisie.</p>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <header class="card__head"><h2>Historia wersji</h2></header>
      <div class="card__body">
        <?php if ($isNew): ?>
          <p class="small muted">Historia wersji pojawi się po pierwszym zapisie.</p>
        <?php elseif (!$versions): ?>
          <p class="small muted">Brak wcześniejszych wersji. Panel zapisuje poprzednią treść przy każdym zapisie.</p>
        <?php else: ?>
          <ul class="list-plain">
            <?php foreach ($versions as $version): ?>
              <li>
                <div class="row-title">
                  <strong class="small"><?= e(format_date((string) $version['created_at'])) ?></strong>
                  <small class="muted"><?= e((string) ($version['username'] !== '' ? $version['username'] : '—')) ?> · <?= e(format_bytes((int) $version['bytes'])) ?></small>
                </div>
                <form method="post" action="<?= e($formAction) ?>" style="margin-left:auto">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="restore">
                  <input type="hidden" name="version_id" value="<?= (int) $version['id'] ?>">
                  <button class="btn btn--sm" type="submit" data-confirm="<?= e('Przywrócić wersję z ' . format_date((string) $version['created_at']) . '? Obecna treść zostanie zapisana w historii.') ?>">Przywróć</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <header class="card__head"><h2>Podgląd i publikacja</h2></header>
      <div class="card__body">
        <?php if ($isNew): ?>
          <p class="small muted">Adres, podgląd i link do strony będą dostępne po pierwszym zapisie.</p>
        <?php else: ?>
          <p class="small">Adres: <span class="mono"><?= e($publicPath($requestedId)) ?></span></p>
          <div class="form-actions" style="margin-top:10px">
            <a class="btn btn--sm" href="<?= e($previewUrl($requestedId)) ?>" target="_blank" rel="noopener">Podgląd</a>
            <a class="btn btn--sm btn--ghost" href="<?= e($productionUrl($requestedId)) ?>" target="_blank" rel="noopener">Zobacz na stronie ↗</a>
          </div>
          <p class="field__hint" style="margin-top:8px">
            Podgląd pod <span class="mono">/podglad<?= e($publicPath($requestedId)) ?></span> wymaga wcześniejszego zbudowania wersji podglądowej.
          </p>
          <p class="field__hint">Zmiany trafiają na stronę po publikacji (sekcja Publikacja).</p>
        <?php endif; ?>
      </div>
    </section>

    <?php if (!$isNew): ?>
      <section class="card">
        <header class="card__head"><h2>Usuń treść</h2></header>
        <div class="card__body">
          <p class="small muted">Plik zostanie usunięty z repozytorium. Obecna wersja trafi do historii wersji.</p>
          <form method="post" action="<?= e($formAction) ?>" style="margin-top:10px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <button class="btn btn--danger btn--block" type="submit" data-confirm="<?= e('Usunąć treść „' . $deleteLabel . '”? Tej operacji nie można cofnąć z poziomu listy.') ?>">Usuń treść</button>
          </form>
        </div>
      </section>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  'use strict';

  /* Adres (slug): podgląd adresu + generowanie z tytułu (nowa treść). */
  var slugInput = document.querySelector('[data-slug-field]');
  if (slugInput) {
    var preview = document.querySelector('[data-slug-preview]');
    var prefix = slugInput.getAttribute('data-slug-prefix') || '/';
    var auto = slugInput.getAttribute('data-autoslug') === '1';
    var manual = false;

    function slugify(text) {
      var map = {
        'ą': 'a', 'ć': 'c', 'ę': 'e', 'ł': 'l', 'ń': 'n', 'ó': 'o', 'ś': 's', 'ź': 'z', 'ż': 'z',
        'Ą': 'a', 'Ć': 'c', 'Ę': 'e', 'Ł': 'l', 'Ń': 'n', 'Ó': 'o', 'Ś': 's', 'Ź': 'z', 'Ż': 'z'
      };
      return String(text || '')
        .replace(/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/g, function (char) { return map[char] || char; })
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 80);
    }

    function renderPreview() {
      if (!preview) return;
      preview.textContent = prefix + (slugInput.value.trim() || '…') + '/';
    }

    slugInput.addEventListener('input', function () {
      manual = true;
      renderPreview();
    });

    if (auto) {
      var sources = document.querySelectorAll('[data-slug-source]');
      Array.prototype.forEach.call(sources, function (source) {
        source.addEventListener('input', function () {
          if (manual) return;
          slugInput.value = slugify(source.value);
          renderPreview();
        });
      });
    }

    renderPreview();
  }

  /* FAQ: dodawanie/usuwanie wierszy i numeracja faq[i][question]. */
  var faqList = document.querySelector('[data-faq-list]');
  if (faqList) {
    var faqKey = faqList.getAttribute('data-faq-key') || 'faq';
    var faqTemplate = faqList.querySelector('template[data-faq-template]');
    var faqAdd = document.querySelector('[data-faq-add]');

    function renumberFaq() {
      var rows = faqList.querySelectorAll('[data-faq-row]');
      Array.prototype.forEach.call(rows, function (row, index) {
        var question = row.querySelector('[data-faq-question]');
        var answer = row.querySelector('[data-faq-answer]');
        if (question) question.name = faqKey + '[' + index + '][question]';
        if (answer) answer.name = faqKey + '[' + index + '][answer]';
      });
    }

    if (faqAdd && faqTemplate) {
      faqAdd.addEventListener('click', function () {
        var fragment = faqTemplate.content.cloneNode(true);
        faqList.insertBefore(fragment, faqTemplate);
        renumberFaq();
        var rows = faqList.querySelectorAll('[data-faq-row]');
        var last = rows[rows.length - 1] || null;
        var input = last ? last.querySelector('[data-faq-question]') : null;
        if (input) input.focus();
      });
    }

    faqList.addEventListener('click', function (event) {
      var button = event.target.closest('[data-list-remove]');
      if (!button) return;
      var row = button.closest('[data-faq-row]');
      if (row) {
        row.remove();
        renumberFaq();
      }
    });

    renumberFaq();
  }

  /* Media: podgląd miniatury wybranego pliku. */
  Array.prototype.forEach.call(document.querySelectorAll('[data-media-field]'), function (field) {
    var input = field.querySelector('[data-media-input]');
    var image = field.querySelector('[data-media-preview]');
    if (!input || !image) return;
    var site = field.getAttribute('data-site-url') || '';

    function renderImage() {
      var value = input.value.trim();
      if (value === '') {
        image.hidden = true;
        image.removeAttribute('src');
        return;
      }
      if (!/^(https?:)?\/\//i.test(value) && value.indexOf('data:') !== 0) {
        value = site + (value.charAt(0) === '/' ? '' : '/') + value;
      }
      image.src = value;
      image.hidden = false;
    }

    input.addEventListener('input', renderImage);
    input.addEventListener('change', renderImage);
    renderImage();
  });

  /* Autozapis szkicu z admin.js przywraca stary token CSRF — podmień na świeży. */
  var csrfField = document.querySelector('[data-editor] input[name="_csrf"]');
  if (csrfField) {
    var freshToken = csrfField.value;
    document.addEventListener('click', function (event) {
      if (!event.target.closest('[data-restore-draft]')) return;
      window.setTimeout(function () { csrfField.value = freshToken; }, 0);
    });
  }
})();
</script>

<?php layout_foot(); ?>
