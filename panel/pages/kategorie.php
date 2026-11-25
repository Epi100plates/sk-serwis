<?php
/**
 * Kategorie realizacji — CRUD z walidacją i ochroną przed usunięciem
 * kategorii, do której przypisane są realizacje.
 */

require_once __DIR__ . '/../views/layout.php';
require_once __DIR__ . '/../lib/realizacje.php';

realizacje_seed_if_empty();

$rpCategories = realizacje_categories();
$rpErrors = [];
$rpEditId = (int) get('edit', 0);
$rpForm = ['id' => 0, 'slug' => '', 'label' => '', 'sort' => 50];

/* ------------------------------------------------------------------- POST */

if (is_post()) {
    csrf_check();
    $rpDo = (string) post('do');

    if ($rpDo === 'delete') {
        $rpDeleteId = (int) post('id');
        try {
            realizacje_category_delete($rpDeleteId);
            realizacje_export_json();
            flash('ok', 'Kategoria usunięta. Plik src/data/realizacje.json został zaktualizowany.');
        } catch (Throwable $e) {
            flash('err', $e->getMessage());
        }
        redirect_to(panel_url('kategorie'));
    }

    if ($rpDo === 'save') {
        $rpResult = realizacje_category_save($_POST);
        if (!empty($rpResult['ok'])) {
            realizacje_export_json();
            flash('ok', 'Kategoria zapisana. Plik src/data/realizacje.json został zaktualizowany.');
            redirect_to(panel_url('kategorie'));
        }
        $rpErrors = is_array($rpResult['errors'] ?? null) ? $rpResult['errors'] : ['Nie udało się zapisać kategorii.'];
        $rpForm = [
            'id' => (int) post('id'),
            'slug' => (string) post('slug'),
            'label' => (string) post('label'),
            'sort' => (int) post('sort'),
        ];
    }
}

if (!is_post() && $rpEditId > 0) {
    $rpSt = db()->prepare('SELECT * FROM realizacje_categories WHERE id = ?');
    $rpSt->execute([$rpEditId]);
    $rpRow = $rpSt->fetch();
    if ($rpRow) {
        $rpForm = [
            'id' => (int) $rpRow['id'],
            'slug' => (string) $rpRow['slug'],
            'label' => (string) $rpRow['label'],
            'sort' => (int) $rpRow['sort'],
        ];
    } else {
        flash('err', 'Nie znaleziono kategorii do edycji.');
        redirect_to(panel_url('kategorie'));
    }
}

/* Liczba realizacji w każdej kategorii */
$rpCounts = [];
$rpCountSt = db()->query('SELECT category_slug, COUNT(*) AS c FROM realizacje GROUP BY category_slug');
foreach ($rpCountSt->fetchAll() as $rpCountRow) {
    $rpCounts[(string) $rpCountRow['category_slug']] = (int) $rpCountRow['c'];
}

$rpIsEdit = (int) $rpForm['id'] > 0;
layout_head('Kategorie realizacji', 'realizacje');
?>
<div class="breadcrumbs">
  <a href="<?= e(panel_url('realizacje')) ?>">Realizacje</a>
  <span>/</span>
  <span>Kategorie</span>
</div>

<div class="help-box">
  Kategorie sterują filtrami i etykietami na stronie <span class="code-inline">/realizacje/</span>.
  Kolejność decyduje o układzie listy kategorii (mniejsza liczba = wyżej).
  Kategorię, do której przypisane są realizacje, można usunąć dopiero po przeniesieniu ich do innej.
</div>

<?php if ($rpErrors): ?>
  <div class="alert alert--err">
    <strong>Popraw formularz:</strong>
    <ul class="list-plain" style="margin-top:8px">
      <?php foreach ($rpErrors as $rpError): ?>
        <li><?= e($rpError) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="grid grid--sidebar">
  <section class="card">
    <header class="card__head">
      <h2>Kategorie</h2>
      <div class="card__actions"><span class="muted small"><?= count($rpCategories) ?> kategorii</span></div>
    </header>
    <div class="card__body card__body--flush">
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Nazwa</th>
              <th>Slug</th>
              <th class="num">Kolejność</th>
              <th class="num">Realizacje</th>
              <th>Akcje</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rpCategories as $rpCategory): ?>
              <?php
              $rpCategoryId = (int) $rpCategory['id'];
              $rpCategorySlug = (string) $rpCategory['slug'];
              $rpCategoryCount = $rpCounts[$rpCategorySlug] ?? 0;
              ?>
              <tr>
                <td>
                  <div class="row-title">
                    <strong><?= e($rpCategory['label']) ?></strong>
                    <?php if ($rpCategorySlug === 'inne'): ?><small>kategoria zapasowa</small><?php endif; ?>
                  </div>
                </td>
                <td><span class="mono"><?= e($rpCategorySlug) ?></span></td>
                <td class="num"><?= (int) $rpCategory['sort'] ?></td>
                <td class="num">
                  <?php if ($rpCategoryCount > 0): ?>
                    <a href="<?= e(panel_url('realizacje', ['category' => $rpCategorySlug])) ?>"><?= $rpCategoryCount ?></a>
                  <?php else: ?>
                    0
                  <?php endif; ?>
                </td>
                <td class="nowrap">
                  <a class="btn btn--sm" href="<?= e(panel_url('kategorie', ['edit' => $rpCategoryId])) ?>">Edytuj</a>
                  <form method="post" action="<?= e(panel_url('kategorie')) ?>" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="delete">
                    <input type="hidden" name="id" value="<?= $rpCategoryId ?>">
                    <button class="btn btn--sm btn--danger" type="submit" data-confirm="Usunąć kategorię „<?= e($rpCategory['label']) ?>”?<?= $rpCategoryCount > 0 ? ' Kategoria ma przypisane realizacje — usuwanie zostanie odrzucone.' : '' ?>">Usuń</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rpCategories): ?>
              <tr>
                <td colspan="5">
                  <div class="empty">Brak kategorii. Dodaj pierwszą kategorię w formularzu obok.</div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <section class="card">
    <header class="card__head">
      <h2><?= $rpIsEdit ? 'Edytuj kategorię' : 'Nowa kategoria' ?></h2>
    </header>
    <div class="card__body">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="id" value="<?= (int) $rpForm['id'] ?>">

        <label class="field">
          <span>Nazwa <em>(wymagana)</em></span>
          <input type="text" name="label" value="<?= e($rpForm['label']) ?>" required maxlength="120" placeholder="np. Chłodzenie">
        </label>

        <label class="field">
          <span>Slug</span>
          <input type="text" name="slug" value="<?= e($rpForm['slug']) ?>" maxlength="80" pattern="[a-z0-9-]+" placeholder="np. chlodzenie">
          <span class="field__hint">Małe litery, cyfry, myślniki. Puste pole = wygenerowanie z nazwy.</span>
        </label>

        <label class="field">
          <span>Kolejność</span>
          <input type="number" name="sort" value="<?= (int) $rpForm['sort'] ?>" step="1">
          <span class="field__hint">Mniejsza liczba = wyżej na liście.</span>
        </label>

        <div class="form-actions">
          <button class="btn btn--primary" type="submit"><?= $rpIsEdit ? 'Zapisz zmiany' : 'Dodaj kategorię' ?></button>
          <?php if ($rpIsEdit): ?>
            <a class="btn btn--ghost" href="<?= e(panel_url('kategorie')) ?>">Anuluj edycję</a>
          <?php endif; ?>
        </div>
      </form>

      <p class="field__hint" style="margin-top:12px">
        Po zmianie sluga istniejące realizacje zostaną automatycznie przepisane na nowy slug.
      </p>
    </div>
  </section>
</div>

<div class="form-actions">
  <a class="btn btn--sm" href="<?= e(panel_url('realizacje/new')) ?>">Dodaj realizację</a>
  <a class="btn btn--sm btn--ghost" href="<?= e(panel_url('realizacje')) ?>">Wróć do realizacji</a>
</div>
<?php
layout_foot();
