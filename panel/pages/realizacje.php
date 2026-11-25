<?php
/**
 * Realizacje - lista i edytor w jednym pliku.
 *
 * Router przekazuje:
 *   /panel/realizacje           → lista
 *   /panel/realizacje/new       → nowa realizacja ($arg1 = 'new')
 *   /panel/realizacje/edit/<id> → edycja ($arg1 = 'edit', $arg2 = id)
 */

require_once __DIR__ . '/../views/layout.php';
require_once __DIR__ . '/../lib/realizacje.php';

$rpAction = isset($arg1) ? (string) $arg1 : '';
$rpRecordId = isset($arg2) ? (int) $arg2 : 0;

realizacje_seed_if_empty();

$rpCategories = realizacje_categories();
$rpErrors = [];
$rpForm = null;
$rpIsNew = true;

/* ------------------------------------------------------------------- POST */

if (is_post()) {
    csrf_check();
    $rpDo = (string) post('do');

    if ($rpDo === 'delete') {
        $rpDeleteId = (int) post('id');
        if ($rpDeleteId > 0) {
            realizacje_delete($rpDeleteId);
            flash('ok', 'Realizacja usunięta. Plik src/data/realizacje.json został zaktualizowany.');
        }
        redirect_to(panel_url('realizacje'));
    }

    if ($rpDo === 'save') {
        $rpResult = realizacje_save($_POST);
        if (!empty($rpResult['ok'])) {
            flash('ok', 'Realizacja zapisana. Wyeksportowano src/data/realizacje.json i utworzono commit w Git.');
            $rpSavedId = (int) ($rpResult['id'] ?? 0);
            redirect_to($rpSavedId > 0 ? panel_url('realizacje/edit/' . $rpSavedId) : panel_url('realizacje'));
        }
        $rpErrors = is_array($rpResult['errors'] ?? null) ? $rpResult['errors'] : ['Nie udało się zapisać realizacji.'];
        $rpForm = realizacje_panel_from_post($_POST);
        $rpIsNew = ((int) ($_POST['id'] ?? 0)) === 0;
    }
}

/* --------------------------------------------------------- tryb edycji */

if ($rpForm === null && $rpAction === 'new') {
    $rpForm = realizacje_panel_defaults($rpCategories);
    $rpIsNew = true;
} elseif ($rpForm === null && $rpAction === 'edit') {
    $rpRow = $rpRecordId > 0 ? realizacje_get($rpRecordId) : null;
    if ($rpRow) {
        $rpForm = realizacje_panel_from_row($rpRow, $rpCategories);
        $rpIsNew = false;
    } else {
        layout_head('Realizacje', 'realizacje');
        echo '<div class="alert alert--err">Nie znaleziono realizacji o podanym numerze.</div>';
        echo '<p><a class="btn btn--sm" href="' . e(panel_url('realizacje')) . '">Wróć do listy realizacji</a></p>';
        layout_foot();
        return;
    }
}

if ($rpForm !== null) {
    layout_head($rpIsNew ? 'Nowa realizacja' : 'Edycja realizacji', 'realizacje');
    realizacje_panel_render_form($rpForm, $rpErrors, $rpCategories);
    layout_foot();
    return;
}

/* ------------------------------------------------------------------ LISTA */

$rpFilters = [
    'q' => trim((string) get('q', '')),
    'category' => (string) get('category', ''),
    'status' => (string) get('status', ''),
];
if (!in_array($rpFilters['status'], ['', 'draft', 'published'], true)) {
    $rpFilters['status'] = '';
}
$rpItems = realizacje_list($rpFilters);

$rpLabels = [];
foreach ($rpCategories as $rpCategory) {
    $rpLabels[(string) $rpCategory['slug']] = (string) $rpCategory['label'];
}

layout_head('Realizacje', 'realizacje');
?>
<div class="help-box">
  Zapis realizacji automatycznie eksportuje dane do <span class="code-inline">src/data/realizacje.json</span>
  i tworzy commit w repozytorium. Zdjęcia i galerie najlepiej wskazywać ścieżkami z
  <a href="<?= e(panel_url('media')) ?>" target="_blank" rel="noopener">biblioteki mediów</a>.
</div>

<form class="toolbar" method="get" action="<?= e(panel_url('realizacje')) ?>">
  <input type="search" name="q" value="<?= e($rpFilters['q']) ?>" placeholder="Szukaj: tytuł, slug, marka…" aria-label="Szukaj realizacji">
  <select name="category" aria-label="Kategoria">
    <option value="">Wszystkie kategorie</option>
    <?php foreach ($rpCategories as $rpCategory): ?>
      <option value="<?= e($rpCategory['slug']) ?>"<?= $rpFilters['category'] === (string) $rpCategory['slug'] ? ' selected' : '' ?>><?= e($rpCategory['label']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status" aria-label="Status">
    <option value="">Wszystkie statusy</option>
    <option value="published"<?= $rpFilters['status'] === 'published' ? ' selected' : '' ?>>Opublikowana</option>
    <option value="draft"<?= $rpFilters['status'] === 'draft' ? ' selected' : '' ?>>Wersja robocza</option>
  </select>
  <button class="btn btn--sm" type="submit">Filtruj</button>
  <?php if ($rpFilters['q'] !== '' || $rpFilters['category'] !== '' || $rpFilters['status'] !== ''): ?>
    <a class="btn btn--sm btn--ghost" href="<?= e(panel_url('realizacje')) ?>">Wyczyść</a>
  <?php endif; ?>
  <span class="spacer"></span>
  <a class="btn btn--sm" href="<?= e(panel_url('kategorie')) ?>">Kategorie</a>
  <a class="btn btn--sm btn--primary" href="<?= e(panel_url('realizacje/new')) ?>">Dodaj realizację</a>
</form>

<section class="card">
  <header class="card__head">
    <h2>Lista realizacji</h2>
    <div class="card__actions"><span class="muted small"><?= count($rpItems) ?> pozycji</span></div>
  </header>
  <div class="card__body card__body--flush">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th aria-label="Miniatura"></th>
            <th>Tytuł</th>
            <th>Kategoria</th>
            <th>Marka / model</th>
            <th>Data</th>
            <th>Status</th>
            <th>Akcje</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rpItems as $rpItem): ?>
            <?php
            $rpId = (int) $rpItem['id'];
            $rpArt = trim((string) ($rpItem['art'] ?? ''));
            $rpThumb = trim((string) $rpItem['image']);
            if ($rpThumb === '') {
                $rpThumb = '/images/realizacje/' . ($rpArt !== '' ? $rpArt : 'case-board') . '.svg';
            }
            $rpCategoryLabel = $rpLabels[(string) $rpItem['category_slug']] ?? (string) $rpItem['category_slug'];
            $rpDevice = trim((string) $rpItem['brand'] . ' ' . (string) $rpItem['model']);
            ?>
            <tr>
              <td><img class="thumb" src="<?= e($rpThumb) ?>" alt="" width="56" height="42" loading="lazy"></td>
              <td>
                <div class="row-title">
                  <a href="<?= e(panel_url('realizacje/edit/' . $rpId)) ?>"><?= e($rpItem['title']) ?></a>
                  <small class="mono"><?= e($rpItem['slug']) ?></small>
                </div>
              </td>
              <td><?= e($rpCategoryLabel) ?></td>
              <td><?= $rpDevice !== '' ? e($rpDevice) : '-' ?></td>
              <td class="nowrap"><?= e(format_date((string) $rpItem['realization_date'], false)) ?></td>
              <td><?= status_badge((string) $rpItem['status']) ?></td>
              <td class="nowrap">
                <a class="btn btn--sm" href="<?= e(panel_url('realizacje/edit/' . $rpId)) ?>">Edytuj</a>
                <form method="post" action="<?= e(panel_url('realizacje')) ?>" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="delete">
                  <input type="hidden" name="id" value="<?= $rpId ?>">
                  <button class="btn btn--sm btn--danger" type="submit" data-confirm="Usunąć realizację „<?= e($rpItem['title']) ?>”? Eksport JSON również zostanie zaktualizowany.">Usuń</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rpItems): ?>
            <tr>
              <td colspan="7">
                <div class="empty">
                  Brak realizacji dla wybranych filtrów.
                  <a href="<?= e(panel_url('realizacje/new')) ?>">Dodaj pierwszą realizację</a>.
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php
layout_foot();

/* ------------------------------------------------------------- helpery UI */

/**
 * Normalizuje galerię z JSON-a lub z formularza do listy [src, alt].
 */
function realizacje_panel_gallery_rows($raw): array
{
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    $rows = [];
    if (is_array($raw)) {
        $sources = $raw['src'] ?? null;
        if (is_array($sources)) {
            $alts = is_array($raw['alt'] ?? null) ? array_values($raw['alt']) : [];
            foreach (array_values($sources) as $index => $src) {
                $rows[] = [
                    'src' => (string) $src,
                    'alt' => (string) ($alts[$index] ?? ''),
                ];
            }
        } else {
            foreach ($raw as $image) {
                if (!is_array($image)) {
                    continue;
                }
                $rows[] = [
                    'src' => (string) ($image['src'] ?? ''),
                    'alt' => (string) ($image['alt'] ?? ''),
                ];
            }
        }
    }
    return $rows !== [] ? $rows : [['src' => '', 'alt' => '']];
}

function realizacje_panel_defaults(array $categories): array
{
    $defaultCategory = '';
    foreach ($categories as $category) {
        if ((string) $category['slug'] === 'inne') {
            $defaultCategory = 'inne';
            break;
        }
    }
    if ($defaultCategory === '' && $categories) {
        $defaultCategory = (string) $categories[0]['slug'];
    }

    return [
        'id' => 0,
        'slug' => '',
        'title' => '',
        'category_slug' => $defaultCategory,
        'brand' => '',
        'model' => '',
        'summary' => '',
        'problem' => '',
        'diagnosis' => '',
        'repair' => '',
        'result' => '',
        'time_label' => '',
        'image' => '',
        'art' => '',
        'realization_date' => '',
        'seo_title' => '',
        'seo_description' => '',
        'status' => 'published',
        'sort' => 50,
        'gallery' => [['src' => '', 'alt' => '']],
        'before_after_enabled' => false,
        'before_after_before' => '',
        'before_after_after' => '',
    ];
}

function realizacje_panel_from_row(array $row, array $categories): array
{
    $form = realizacje_panel_defaults($categories);
    foreach ([
        'id', 'slug', 'title', 'category_slug', 'brand', 'model', 'summary', 'problem', 'diagnosis',
        'repair', 'result', 'time_label', 'image', 'art', 'realization_date', 'seo_title',
        'seo_description', 'status',
    ] as $key) {
        if (array_key_exists($key, $row)) {
            $form[$key] = (string) $row[$key];
        }
    }
    $form['sort'] = (int) ($row['sort'] ?? 50);
    $form['gallery'] = realizacje_panel_gallery_rows($row['gallery'] ?? []);

    $beforeAfter = realizacje_before_after($row);
    $form['before_after_enabled'] = $beforeAfter !== null;
    $form['before_after_before'] = $beforeAfter['before'] ?? '';
    $form['before_after_after'] = $beforeAfter['after'] ?? '';

    return $form;
}

function realizacje_panel_from_post(array $post): array
{
    $form = [];
    foreach ([
        'id', 'slug', 'title', 'category_slug', 'brand', 'model', 'summary', 'problem', 'diagnosis',
        'repair', 'result', 'time_label', 'image', 'art', 'realization_date', 'seo_title',
        'seo_description', 'status',
    ] as $key) {
        $form[$key] = (string) ($post[$key] ?? '');
    }
    $form['id'] = (int) ($form['id'] ?? 0);
    $form['sort'] = (int) ($post['sort'] ?? 50);
    if ($form['status'] === '') {
        $form['status'] = 'published';
    }
    $form['gallery'] = realizacje_panel_gallery_rows($post['gallery'] ?? []);
    $form['before_after_enabled'] = !empty($post['before_after_enabled']);
    $form['before_after_before'] = (string) ($post['before_after_before'] ?? '');
    $form['before_after_after'] = (string) ($post['before_after_after'] ?? '');

    return $form;
}

function realizacje_panel_render_form(array $form, array $errors, array $categories): void
{
    $isNew = ((int) ($form['id'] ?? 0)) === 0;
    $currentCategory = (string) ($form['category_slug'] ?? '');
    $galleryRows = is_array($form['gallery'] ?? null) ? $form['gallery'] : [];
    if ($galleryRows === []) {
        $galleryRows = [['src' => '', 'alt' => '']];
    }
    $categoryKnown = false;
    ?>
    <div class="breadcrumbs">
      <a href="<?= e(panel_url('realizacje')) ?>">Realizacje</a>
      <span>/</span>
      <span><?= e($isNew ? 'Nowa realizacja' : 'Edycja') ?></span>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert--err">
        <strong>Popraw formularz:</strong>
        <ul class="list-plain" style="margin-top:8px">
          <?php foreach ($errors as $error): ?>
            <li><?= e($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="id" value="<?= (int) ($form['id'] ?? 0) ?>">

      <section class="card">
        <header class="card__head"><h2>Podstawowe</h2></header>
        <div class="card__body">
          <div class="form-grid">
            <div class="form-row">
              <label class="field">
                <span>Tytuł <em>(wymagany)</em></span>
                <input id="rp-title" type="text" name="title" value="<?= e($form['title'] ?? '') ?>" required maxlength="200">
              </label>
              <label class="field">
                <span>Slug <em>(adres /realizacje/…)</em></span>
                <input id="rp-slug" type="text" name="slug" value="<?= e($form['slug'] ?? '') ?>" maxlength="120" pattern="[a-z0-9-]+">
                <span class="field__hint">Małe litery, cyfry i myślniki. Puste pole = wygenerowanie z tytułu.</span>
              </label>
            </div>
            <div class="form-row form-row--3">
              <label class="field">
                <span>Kategoria</span>
                <select name="category_slug">
                  <?php foreach ($categories as $category): ?>
                    <?php
                    $categorySlug = (string) $category['slug'];
                    if ($categorySlug === $currentCategory) {
                        $categoryKnown = true;
                    }
                    ?>
                    <option value="<?= e($categorySlug) ?>"<?= $categorySlug === $currentCategory ? ' selected' : '' ?>><?= e($category['label']) ?> (<?= e($categorySlug) ?>)</option>
                  <?php endforeach; ?>
                  <?php if (!$categoryKnown && $currentCategory !== ''): ?>
                    <option value="<?= e($currentCategory) ?>" selected><?= e($currentCategory) ?> (brak w kategoriach)</option>
                  <?php endif; ?>
                </select>
              </label>
              <label class="field">
                <span>Status</span>
                <select name="status">
                  <option value="published"<?= ($form['status'] ?? '') === 'published' ? ' selected' : '' ?>>Opublikowana</option>
                  <option value="draft"<?= ($form['status'] ?? '') === 'draft' ? ' selected' : '' ?>>Wersja robocza</option>
                </select>
              </label>
              <label class="field">
                <span>Kolejność</span>
                <input type="number" name="sort" value="<?= (int) ($form['sort'] ?? 50) ?>" step="1">
              </label>
            </div>
            <div class="form-row form-row--3">
              <label class="field">
                <span>Data realizacji</span>
                <input type="date" name="realization_date" value="<?= e($form['realization_date'] ?? '') ?>">
              </label>
              <label class="field">
                <span>Marka</span>
                <input type="text" name="brand" value="<?= e($form['brand'] ?? '') ?>" maxlength="80" placeholder="np. Lenovo">
              </label>
              <label class="field">
                <span>Model</span>
                <input type="text" name="model" value="<?= e($form['model'] ?? '') ?>" maxlength="80" placeholder="np. ThinkPad">
              </label>
            </div>
          </div>
        </div>
      </section>

      <section class="card">
        <header class="card__head"><h2>Opis realizacji</h2></header>
        <div class="card__body">
          <div class="form-grid">
            <label class="field">
              <span>Zajawka (summary)</span>
              <textarea name="summary" rows="2" maxlength="400"><?= e($form['summary'] ?? '') ?></textarea>
            </label>
            <label class="field">
              <span>Problem</span>
              <textarea name="problem" rows="4"><?= e($form['problem'] ?? '') ?></textarea>
            </label>
            <label class="field">
              <span>Diagnoza</span>
              <textarea name="diagnosis" rows="4"><?= e($form['diagnosis'] ?? '') ?></textarea>
            </label>
            <label class="field">
              <span>Przebieg naprawy</span>
              <textarea name="repair" rows="4"><?= e($form['repair'] ?? '') ?></textarea>
            </label>
            <label class="field">
              <span>Wynik</span>
              <textarea name="result" rows="3"><?= e($form['result'] ?? '') ?></textarea>
            </label>
            <div class="form-row">
              <label class="field">
                <span>Czas realizacji (etykieta)</span>
                <input type="text" name="time_label" value="<?= e($form['time_label'] ?? '') ?>" maxlength="40" placeholder="np. 48 h">
              </label>
              <label class="field">
                <span>Ilustracja zapasowa (art)</span>
                <input type="text" name="art" value="<?= e($form['art'] ?? '') ?>" maxlength="80" placeholder="np. case-board">
                <span class="field__hint">Plik <span class="mono">/images/realizacje/&lt;art&gt;.svg</span> - używany, gdy brak zdjęcia.</span>
              </label>
            </div>
          </div>
        </div>
      </section>

      <section class="card">
        <header class="card__head"><h2>Media</h2></header>
        <div class="card__body">
          <div class="form-grid">
            <div class="form-row">
              <label class="field">
                <span>Zdjęcie główne (image)</span>
                <input type="text" name="image" value="<?= e($form['image'] ?? '') ?>" maxlength="500" placeholder="/images/uploads/…jpg">
              </label>
              <div class="field">
                <span>Biblioteka mediów</span>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                  <a class="btn btn--sm" href="<?= e(panel_url('media', ['pick' => 'image'])) ?>" target="_blank" rel="noopener">Wybierz zdjęcie</a>
                  <a class="btn btn--sm" href="<?= e(panel_url('media', ['pick' => 'gallery'])) ?>" target="_blank" rel="noopener">Wybierz do galerii</a>
                </div>
                <span class="field__hint">Otwiera bibliotekę w nowej karcie - skopiuj ścieżkę i wklej ją powyżej lub w galerii.</span>
              </div>
            </div>

            <div class="section-title">Slider „przed / po”</div>
            <label class="field field--check">
              <input id="rp-ba-enabled" type="checkbox" name="before_after_enabled" value="1"<?= !empty($form['before_after_enabled']) ? ' checked' : '' ?>>
              <span>Włącz slider przed/po (wymaga obu zdjęć)</span>
            </label>
            <div id="rp-ba-fields" class="form-row"<?= empty($form['before_after_enabled']) ? ' hidden' : '' ?>>
              <label class="field">
                <span>Zdjęcie „przed” (URL)</span>
                <input type="text" name="before_after_before" value="<?= e($form['before_after_before'] ?? '') ?>" maxlength="500" placeholder="/images/uploads/przed.jpg">
              </label>
              <label class="field">
                <span>Zdjęcie „po” (URL)</span>
                <input type="text" name="before_after_after" value="<?= e($form['before_after_after'] ?? '') ?>" maxlength="500" placeholder="/images/uploads/po.jpg">
              </label>
            </div>

            <div class="section-title">Galeria</div>
            <div id="rp-gallery" class="form-grid">
              <?php foreach ($galleryRows as $image): ?>
                <div class="form-row" data-list-row>
                  <label class="field">
                    <span>Adres zdjęcia (src)</span>
                    <input type="text" name="gallery[src][]" value="<?= e($image['src'] ?? '') ?>" maxlength="500" placeholder="/images/uploads/…jpg">
                  </label>
                  <div class="field">
                    <span>Opis zdjęcia (ALT)</span>
                    <div style="display:flex;gap:8px">
                      <input type="text" name="gallery[alt][]" value="<?= e($image['alt'] ?? '') ?>" maxlength="300" style="flex:1;min-width:0">
                      <button class="btn btn--sm btn--danger" type="button" data-list-remove>Usuń</button>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
              <template>
                <div class="form-row" data-list-row>
                  <label class="field">
                    <span>Adres zdjęcia (src)</span>
                    <input type="text" name="gallery[src][]" value="" maxlength="500" placeholder="/images/uploads/…jpg">
                  </label>
                  <div class="field">
                    <span>Opis zdjęcia (ALT)</span>
                    <div style="display:flex;gap:8px">
                      <input type="text" name="gallery[alt][]" value="" maxlength="300" style="flex:1;min-width:0">
                      <button class="btn btn--sm btn--danger" type="button" data-list-remove>Usuń</button>
                    </div>
                  </div>
                </div>
              </template>
            </div>
            <div class="form-actions">
              <button class="btn btn--sm" type="button" data-list-add="#rp-gallery">Dodaj zdjęcie do galerii</button>
            </div>
          </div>
        </div>
      </section>

      <section class="card">
        <header class="card__head"><h2>SEO</h2></header>
        <div class="card__body">
          <div class="form-grid">
            <label class="field">
              <span>Tytuł SEO <em>(opcjonalny)</em></span>
              <input type="text" name="seo_title" value="<?= e($form['seo_title'] ?? '') ?>" maxlength="200" data-counter="#rp-seo-title-count" data-max="60">
              <span id="rp-seo-title-count" class="field__hint"></span>
            </label>
            <label class="field">
              <span>Opis SEO <em>(opcjonalny)</em></span>
              <textarea name="seo_description" rows="3" maxlength="320" data-counter="#rp-seo-desc-count" data-max="160"><?= e($form['seo_description'] ?? '') ?></textarea>
              <span id="rp-seo-desc-count" class="field__hint"></span>
            </label>
          </div>
        </div>
      </section>

      <div class="form-actions">
        <button class="btn btn--primary" type="submit"><?= $isNew ? 'Dodaj realizację' : 'Zapisz zmiany' ?></button>
        <a class="btn btn--ghost" href="<?= e(panel_url('realizacje')) ?>">Anuluj</a>
        <span class="spacer"></span>
        <span class="muted small">Zapis eksportuje dane do <span class="code-inline">src/data/realizacje.json</span>.</span>
      </div>
    </form>

    <?php if (!$isNew): ?>
      <form method="post" action="<?= e(panel_url('realizacje')) ?>" class="form-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="delete">
        <input type="hidden" name="id" value="<?= (int) ($form['id'] ?? 0) ?>">
        <button class="btn btn--sm btn--danger" type="submit" data-confirm="Usunąć tę realizację? Eksport JSON również zostanie zaktualizowany.">Usuń realizację</button>
        <span class="muted small">Usunięcie jest nieodwracalne (wersja pliku JSON zostaje w historii Git).</span>
      </form>
    <?php endif; ?>

    <script>
      (function () {
        var title = document.getElementById('rp-title');
        var slug = document.getElementById('rp-slug');
        if (title && slug) {
          var manual = slug.value.trim() !== '';
          slug.addEventListener('input', function () { manual = true; });
          title.addEventListener('input', function () {
            if (manual) return;
            var map = { 'ą': 'a', 'ć': 'c', 'ę': 'e', 'ł': 'l', 'ń': 'n', 'ó': 'o', 'ś': 's', 'ź': 'z', 'ż': 'z' };
            var value = title.value.toLowerCase().replace(/[ąćęłńóśźż]/g, function (ch) {
              return map[ch] || ch;
            });
            slug.value = value.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
          });
        }

        var toggle = document.getElementById('rp-ba-enabled');
        var fields = document.getElementById('rp-ba-fields');
        if (toggle && fields) {
          var sync = function () { fields.hidden = !toggle.checked; };
          toggle.addEventListener('change', sync);
          sync();
        }
      })();
    </script>
    <?php
}
