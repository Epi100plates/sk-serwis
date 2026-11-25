<?php
/**
 * Biblioteka mediów: upload, podgląd, metadane, usuwanie i tryb wyboru
 * pliku (?pick=… - proste wklejanie ścieżki do formularza realizacji).
 */

require_once __DIR__ . '/../views/layout.php';

$mediaPick = trim((string) get('pick', ''));
$mediaPickMode = $mediaPick !== '';
$mediaPicked = trim((string) get('picked', ''));

/**
 * Adres strony mediów z zachowaniem trybu wyboru.
 */
function media_panel_url(string $pick = '', array $query = []): string
{
    if ($pick !== '') {
        $query['pick'] = $pick;
    }
    return panel_url('media', $query);
}

/**
 * Ścieżka miniatury (WebP) albo oryginału - do podglądu na karcie.
 */
function media_panel_thumb(array $record): string
{
    $mime = (string) ($record['mime'] ?? '');
    if (!str_starts_with($mime, 'image/')) {
        return '';
    }
    $filename = (string) ($record['filename'] ?? '');
    $path = (string) ($record['path'] ?? '');
    if ($filename !== '') {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        if (is_file(media_dir() . '/thumbs/' . $base . '.webp')) {
            return '/images/uploads/thumbs/' . $base . '.webp';
        }
    }
    return $path;
}

function media_panel_type_label(string $mime): string
{
    if (str_starts_with($mime, 'image/')) {
        return 'Obraz';
    }
    if (str_starts_with($mime, 'video/')) {
        return 'Wideo';
    }
    return $mime !== '' ? $mime : 'Plik';
}

/* ------------------------------------------------------------------- POST */

if (is_post()) {
    csrf_check();
    $mediaDo = (string) post('do');

    if ($mediaDo === 'upload') {
        $mediaFile = $_FILES['file'] ?? [];
        if (!is_array($mediaFile)) {
            $mediaFile = [];
        }
        $mediaResult = media_upload($mediaFile, [
            'alt' => (string) post('alt'),
            'title' => (string) post('title'),
            'description' => (string) post('description'),
            'category' => (string) post('category'),
        ]);
        if (!empty($mediaResult['ok'])) {
            $mediaName = (string) ($mediaResult['media']['filename'] ?? 'plik');
            flash('ok', 'Plik dodany do biblioteki: ' . $mediaName);
        } else {
            flash('err', (string) ($mediaResult['error'] ?? 'Nie udało się dodać pliku.'));
        }
        redirect_to(media_panel_url($mediaPick));
    }

    if ($mediaDo === 'update') {
        $mediaId = (int) post('id');
        $mediaRecord = $mediaId > 0 ? media_get($mediaId) : null;
        if (!$mediaRecord) {
            flash('err', 'Nie znaleziono pliku do edycji.');
        } else {
            media_update($mediaId, [
                'alt' => (string) post('alt'),
                'title' => (string) post('title'),
                'description' => (string) post('description'),
                'category' => (string) post('category'),
            ]);
            flash('ok', 'Zapisano metadane pliku: ' . (string) $mediaRecord['filename']);
        }
        redirect_to(media_panel_url($mediaPick));
    }

    if ($mediaDo === 'delete') {
        $mediaId = (int) post('id');
        $mediaRecord = $mediaId > 0 ? media_get($mediaId) : null;
        if (!$mediaRecord) {
            flash('err', 'Nie znaleziono pliku do usunięcia.');
        } else {
            media_delete($mediaId);
            flash('ok', 'Plik usunięty: ' . (string) $mediaRecord['filename']);
        }
        redirect_to(media_panel_url($mediaPick));
    }

    flash('err', 'Nieznana operacja.');
    redirect_to(media_panel_url($mediaPick));
}

/* ------------------------------------------------------------------- GET */

$mediaFilters = [
    'q' => trim((string) get('q', '')),
    'type' => (string) get('type', ''),
    'category' => (string) get('category', ''),
];
if (!in_array($mediaFilters['type'], ['', 'image', 'video'], true)) {
    $mediaFilters['type'] = '';
}

$mediaItems = media_list($mediaFilters);
$mediaCategories = media_categories();

layout_head('Media', 'media');
?>
<?php if ($mediaPickMode): ?>
  <div class="alert alert--info">
    Tryb wyboru pliku<?= $mediaPick !== '1' ? ' dla pola „' . e($mediaPick) . '”' : '' ?>:
    kliknij miniaturę (albo przycisk „Wybierz ten plik”), a następnie skopiuj ścieżkę i wklej ją
    w formularzu realizacji.
  </div>
<?php endif; ?>

<?php if ($mediaPicked !== ''): ?>
  <div class="alert alert--ok">
    Wybrano: <span class="code-inline"><?= e($mediaPicked) ?></span>
    <button type="button" class="btn btn--sm" data-copy="<?= e($mediaPicked) ?>">Kopiuj ścieżkę</button>
    <span class="small muted">Skopiuj ścieżkę i wklej ją w polu wybranej realizacji (zdjęcie / galeria).</span>
  </div>
<?php endif; ?>

<datalist id="media-cats">
  <?php foreach ($mediaCategories as $mediaCategory): ?>
    <option value="<?= e($mediaCategory) ?>"></option>
  <?php endforeach; ?>
</datalist>

<div class="grid grid--sidebar">
  <section class="card">
    <header class="card__head">
      <h2>Biblioteka</h2>
      <div class="card__actions"><span class="muted small"><?= count($mediaItems) ?> plików</span></div>
    </header>
    <div class="card__body">
      <form class="toolbar" method="get" action="<?= e(panel_url('media')) ?>">
        <?php if ($mediaPickMode): ?>
          <input type="hidden" name="pick" value="<?= e($mediaPick) ?>">
        <?php endif; ?>
        <input type="search" name="q" value="<?= e($mediaFilters['q']) ?>" placeholder="Szukaj: nazwa, ALT, opis…" aria-label="Szukaj w mediach">
        <select name="type" aria-label="Typ pliku">
          <option value="">Wszystkie typy</option>
          <option value="image"<?= $mediaFilters['type'] === 'image' ? ' selected' : '' ?>>Obrazy</option>
          <option value="video"<?= $mediaFilters['type'] === 'video' ? ' selected' : '' ?>>Wideo</option>
        </select>
        <select name="category" aria-label="Kategoria">
          <option value="">Wszystkie kategorie</option>
          <?php foreach ($mediaCategories as $mediaCategory): ?>
            <option value="<?= e($mediaCategory) ?>"<?= $mediaFilters['category'] === $mediaCategory ? ' selected' : '' ?>><?= e($mediaCategory) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn--sm" type="submit">Filtruj</button>
        <?php if ($mediaFilters['q'] !== '' || $mediaFilters['type'] !== '' || $mediaFilters['category'] !== ''): ?>
          <a class="btn btn--sm btn--ghost" href="<?= e(media_panel_url($mediaPick)) ?>">Wyczyść</a>
        <?php endif; ?>
      </form>

      <?php if (!$mediaItems): ?>
        <div class="empty">
          Biblioteka jest pusta albo żaden plik nie pasuje do filtrów.
          Wgraj pierwszy plik w formularzu obok.
        </div>
      <?php else: ?>
        <div class="media-grid">
          <?php foreach ($mediaItems as $mediaItem): ?>
            <?php
            $mediaItemId = (int) $mediaItem['id'];
            $mediaPath = (string) $mediaItem['path'];
            $mediaThumb = media_panel_thumb($mediaItem);
            $mediaPickHref = media_panel_url($mediaPick, ['picked' => $mediaPath]);
            $mediaMeta = [];
            if ((int) $mediaItem['width'] > 0 && (int) $mediaItem['height'] > 0) {
                $mediaMeta[] = (int) $mediaItem['width'] . ' × ' . (int) $mediaItem['height'] . ' px';
            }
            $mediaMeta[] = format_bytes((int) $mediaItem['bytes']);
            $mediaMeta[] = (string) $mediaItem['mime'];
            $mediaAlt = trim((string) $mediaItem['alt']);
            ?>
            <article class="media-card">
              <div class="media-card__img">
                <?php if ($mediaThumb !== ''): ?>
                  <?php if ($mediaPickMode): ?>
                    <a href="<?= e($mediaPickHref) ?>" style="display:block;width:100%;height:100%" title="Wybierz ten plik">
                      <img src="<?= e($mediaThumb) ?>" alt="<?= e($mediaAlt !== '' ? $mediaAlt : (string) $mediaItem['title']) ?>" loading="lazy">
                    </a>
                  <?php else: ?>
                    <img src="<?= e($mediaThumb) ?>" alt="<?= e($mediaAlt !== '' ? $mediaAlt : (string) $mediaItem['title']) ?>" loading="lazy">
                  <?php endif; ?>
                <?php else: ?>
                  <span class="badge badge--muted"><?= e(media_panel_type_label((string) $mediaItem['mime'])) ?></span>
                <?php endif; ?>
              </div>

              <div class="media-card__body">
                <strong title="<?= e((string) $mediaItem['filename']) ?>"><?= e((string) $mediaItem['filename']) ?></strong>
                <span class="muted"><?= e(implode(' · ', $mediaMeta)) ?></span>
                <span>
                  <span class="badge badge--muted"><?= e($mediaItem['category'] !== '' ? (string) $mediaItem['category'] : 'bez kategorii') ?></span>
                </span>
                <?php if ($mediaAlt !== ''): ?>
                  <span class="truncate" title="<?= e($mediaAlt) ?>">ALT: <?= e($mediaAlt) ?></span>
                <?php endif; ?>
                <span class="mono truncate" title="<?= e($mediaPath) ?>"><?= e($mediaPath) ?></span>
              </div>

              <div class="media-card__actions">
                <button type="button" class="btn btn--sm" data-copy="<?= e($mediaPath) ?>">Kopiuj</button>
                <form method="post" style="display:flex;flex:1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="delete">
                  <input type="hidden" name="id" value="<?= $mediaItemId ?>">
                  <button class="btn btn--sm btn--danger" type="submit" style="width:100%" data-confirm="Usunąć plik <?= e((string) $mediaItem['filename']) ?>? Plik zostanie usunięty z dysku.">Usuń</button>
                </form>
              </div>

              <?php if ($mediaPickMode): ?>
                <div class="media-card__actions">
                  <a class="btn btn--sm btn--primary" href="<?= e($mediaPickHref) ?>">Wybierz ten plik</a>
                </div>
              <?php endif; ?>

              <details style="padding:0 10px 10px">
                <summary class="small" style="cursor:pointer;padding:4px 0">Metadane</summary>
                <form method="post" class="form-grid" style="gap:8px;margin-top:8px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="update">
                  <input type="hidden" name="id" value="<?= $mediaItemId ?>">
                  <label class="field">
                    <span class="small">ALT</span>
                    <input type="text" name="alt" value="<?= e($mediaAlt) ?>" maxlength="300">
                  </label>
                  <label class="field">
                    <span class="small">Tytuł</span>
                    <input type="text" name="title" value="<?= e((string) $mediaItem['title']) ?>" maxlength="200">
                  </label>
                  <label class="field">
                    <span class="small">Opis</span>
                    <textarea name="description" rows="2" maxlength="600"><?= e((string) $mediaItem['description']) ?></textarea>
                  </label>
                  <label class="field">
                    <span class="small">Kategoria</span>
                    <input type="text" name="category" value="<?= e((string) $mediaItem['category']) ?>" list="media-cats" maxlength="80">
                  </label>
                  <button class="btn btn--sm btn--primary" type="submit">Zapisz metadane</button>
                </form>
              </details>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <div class="form-grid">
    <section class="card">
      <header class="card__head"><h2>Dodaj plik</h2></header>
      <div class="card__body">
        <form method="post" enctype="multipart/form-data" class="form-grid">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="upload">

          <label class="field">
            <span>Plik <em>(maks. 12 MB)</em></span>
            <input type="file" name="file" required accept="image/jpeg,image/png,image/webp,image/avif,image/gif,image/svg+xml,video/mp4,video/webm">
          </label>
          <label class="field">
            <span>Tytuł / nazwa</span>
            <input type="text" name="title" maxlength="200" placeholder="np. Czyszczenie chłodzenia MSI">
          </label>
          <label class="field">
            <span>ALT (opis dla wyszukiwarek)</span>
            <input type="text" name="alt" maxlength="300">
          </label>
          <label class="field">
            <span>Opis</span>
            <textarea name="description" rows="3" maxlength="600"></textarea>
          </label>
          <label class="field">
            <span>Kategoria</span>
            <input type="text" name="category" list="media-cats" maxlength="80" placeholder="np. realizacje">
          </label>

          <button class="btn btn--primary btn--block" type="submit">Wgraj plik</button>
        </form>
      </div>
    </section>

    <div class="help-box">
      <strong>Formaty i limity</strong>
      <p class="small" style="margin-top:6px">
        JPG, PNG, WebP, AVIF, GIF, SVG, MP4, WebM - do 12 MB. Miniatury WebP powstają
        automatycznie po wgraniu. Pliki SVG są sprawdzane pod kątem skryptów.
      </p>
      <p class="small" style="margin-top:8px">
        Ścieżki z biblioteki wklejasz w formularzu
        <a href="<?= e(panel_url('realizacje')) ?>">realizacji</a> (pole zdjęcia, galeria,
        slider przed/po).
      </p>
    </div>
  </div>
</div>
<?php
layout_foot();
