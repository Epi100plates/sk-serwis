<?php
/**
 * Przekierowania: lista, dodawanie, edycja, włączanie, usuwanie.
 * Po zapisie generowane są pliki: nginx snippet, _redirects i .htaccess.
 */

$editId = (int) get('edit', 0);
$edit = null;
if ($editId > 0) {
    $st = db()->prepare('SELECT * FROM redirects WHERE id = ?');
    $st->execute([$editId]);
    $edit = $st->fetch() ?: null;
}

$errors = [];
if (is_post()) {
    csrf_check();
    $action = (string) post('action');

    if ($action === 'save') {
        $result = redirect_save([
            'id' => (int) post('id', 0),
            'old_path' => (string) post('old_path'),
            'target_url' => (string) post('target_url'),
            'status_code' => (int) post('status_code', 301),
            'enabled' => (bool) post('enabled', false),
            'note' => (string) post('note'),
        ]);
        if ($result['ok']) {
            flash('ok', 'Zapisano przekierowanie i zaktualizowano pliki serwera.');
            redirect_to(panel_url('redirects'));
        }
        $errors = $result['errors'];
        $edit = [
            'id' => (int) post('id', 0),
            'old_path' => (string) post('old_path'),
            'target_url' => (string) post('target_url'),
            'status_code' => (int) post('status_code', 301),
            'enabled' => (int) (bool) post('enabled', false),
            'note' => (string) post('note'),
        ];
    }

    if ($action === 'delete') {
        redirect_delete((int) post('id', 0));
        flash('info', 'Usunięto przekierowanie.');
        redirect_to(panel_url('redirects'));
    }

    if ($action === 'toggle') {
        $id = (int) post('id', 0);
        db()->prepare('UPDATE redirects SET enabled = 1 - enabled, updated_at = ? WHERE id = ?')->execute([now(), $id]);
        redirects_sync_files();
        flash('info', 'Zmieniono status przekierowania.');
        redirect_to(panel_url('redirects'));
    }
}

$rows = redirects_list();
$hostingNote = 'Wersja nginx działa automatycznie po publikacji. Pliki _redirects i .htaccess są generowane dla hostingu klasycznego (Cloudflare Pages / Netlify / hosting www).';

layout_head('Przekierowania', 'redirects');
?>

<div class="grid grid--sidebar">
  <div class="grid">
    <section class="card">
      <header class="card__head">
        <h2>Aktywne przekierowania</h2>
        <div class="card__actions"><a class="btn btn--sm" href="<?= e(panel_url('redirects')) ?>#form">Dodaj przekierowanie</a></div>
      </header>
      <div class="card__body card__body--flush">
        <div class="toolbar" style="padding:12px 14px 0">
          <input type="search" placeholder="Szukaj adresu…" data-table-filter="#redirects-table" aria-label="Szukaj przekierowania">
          <span class="spacer"></span>
          <span class="small muted"><?= count($rows) ?> przekierowań</span>
        </div>
        <?php if (!$rows): ?>
          <div class="empty">Brak przekierowań. Dodaj pierwsze — np. stare adresy z poprzedniej strony.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table table--compact" id="redirects-table">
              <thead>
                <tr><th>Stary URL</th><th>Cel</th><th>Typ</th><th class="num">Kliknięcia</th><th>Status</th><th></th></tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td class="mono"><?= e($row['old_path']) ?></td>
                  <td class="mono truncate" title="<?= e($row['target_url']) ?>"><?= e($row['target_url']) ?></td>
                  <td class="nowrap"><?= (int) $row['status_code'] ?></td>
                  <td class="num"><?= (int) $row['hits'] ?></td>
                  <td><?= (int) $row['enabled'] === 1 ? status_badge('enabled') : status_badge('disabled') ?></td>
                  <td class="nowrap" style="text-align:right">
                    <a class="btn btn--sm" href="<?= e(panel_url('redirects', ['edit' => $row['id']])) ?>">Edytuj</a>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                      <button class="btn btn--sm btn--ghost" type="submit"><?= (int) $row['enabled'] === 1 ? 'Wyłącz' : 'Włącz' ?></button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Usunąć przekierowanie <?= e($row['old_path']) ?>?')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                      <button class="btn btn--sm btn--danger" type="submit">Usuń</button>
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
  </div>

  <div class="grid">
    <section class="card" id="form">
      <header class="card__head"><h2><?= $edit && !empty($edit['id']) ? 'Edytuj przekierowanie' : 'Nowe przekierowanie' ?></h2></header>
      <div class="card__body">
        <?php foreach ($errors as $error): ?>
          <div class="alert alert--err" style="margin-bottom:10px"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" class="form-grid">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

          <label class="field">
            <span>Stary adres (ścieżka)</span>
            <input type="text" name="old_path" value="<?= e($edit['old_path'] ?? '') ?>" placeholder="/stary-adres/" required>
            <span class="field__hint">Musi zaczynać się od „/”.</span>
          </label>

          <label class="field">
            <span>Docelowy adres</span>
            <input type="text" name="target_url" value="<?= e($edit['target_url'] ?? '') ?>" placeholder="/nowy-adres/ lub https://…" required>
            <span class="field__hint">Wewnętrzny (/cennik/) albo pełny https://. Protokoły javascript:/data: są blokowane.</span>
          </label>

          <div class="form-row">
            <label class="field">
              <span>Typ</span>
              <select name="status_code">
                <option value="301" <?= (int) ($edit['status_code'] ?? 301) === 301 ? 'selected' : '' ?>>301 — trwałe</option>
                <option value="302" <?= (int) ($edit['status_code'] ?? 301) === 302 ? 'selected' : '' ?>>302 — tymczasowe</option>
              </select>
            </label>
            <label class="field field--check" style="align-self:end">
              <input type="checkbox" name="enabled" value="1" <?= (int) ($edit['enabled'] ?? 1) === 1 ? 'checked' : '' ?>>
              <span>Aktywne</span>
            </label>
          </div>

          <label class="field">
            <span>Notatka wewnętrzna</span>
            <input type="text" name="note" value="<?= e($edit['note'] ?? '') ?>" placeholder="np. stary adres z 2019">
          </label>

          <div class="form-actions">
            <button class="btn btn--primary" type="submit">Zapisz przekierowanie</button>
            <?php if ($edit && !empty($edit['id'])): ?>
              <a class="btn btn--ghost" href="<?= e(panel_url('redirects')) ?>">Anuluj</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </section>

    <section class="card">
      <header class="card__head"><h2>Zasady bezpieczeństwa</h2></header>
      <div class="card__body">
        <ul class="list-plain small">
          <li>✔ Walidacja protokołów (tylko /, http, https).</li>
          <li>✔ Kontrola pętli i łańcuchów przekierowań.</li>
          <li>✔ Brak otwartego redirectu (żadnych parametrów z URL).</li>
          <li>✔ Każda zmiana trafia do repozytorium (możliwy rollback).</li>
        </ul>
        <p class="small muted" style="margin-top:10px"><?= e($hostingNote) ?></p>
        <p class="small muted" style="margin-top:8px">
          Nie przekierowuj wielu różnych stron na homepage — to obniża jakość SEO.
          Lepiej wskazać najbliższą tematycznie podstronę.
        </p>
      </div>
    </section>
  </div>
</div>

<?php layout_foot(); ?>
