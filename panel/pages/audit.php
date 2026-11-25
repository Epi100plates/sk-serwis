<?php
/**
 * Dziennik zdarzeń panelu (audyt).
 */

$pageNo = max(1, (int) get('page', 1));
$perPage = 50;
$offset = ($pageNo - 1) * $perPage;
$action = trim((string) get('action', ''));
$username = trim((string) get('user', ''));

$where = [];
$params = [];
if ($action !== '') {
    $where[] = 'action LIKE ?';
    $params[] = '%' . $action . '%';
}
if ($username !== '') {
    $where[] = 'username LIKE ?';
    $params[] = '%' . $username . '%';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countSt = db()->prepare('SELECT COUNT(*) FROM audit_log' . $whereSql);
$countSt->execute($params);
$total = (int) $countSt->fetchColumn();

$st = db()->prepare('SELECT * FROM audit_log' . $whereSql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$st->execute($params);
$rows = $st->fetchAll();
$pages = max(1, (int) ceil($total / $perPage));

layout_head('Dziennik zdarzeń', '');
?>

<section class="card">
  <header class="card__head">
    <h2>Historia działań</h2>
    <div class="card__actions"><span class="small muted"><?= $total ?> wpisów</span></div>
  </header>
  <div class="card__body">
    <form method="get" class="toolbar">
      <input type="hidden" name="p" value="">
      <input type="search" name="action" value="<?= e($action) ?>" placeholder="Akcja, np. login">
      <input type="search" name="user" value="<?= e($username) ?>" placeholder="Użytkownik">
      <button class="btn btn--sm" type="submit">Filtruj</button>
      <?php if ($action !== '' || $username !== ''): ?><a class="btn btn--sm btn--ghost" href="<?= e(panel_url('audit')) ?>">Wyczyść</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
      <div class="empty">Brak wpisów.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Data</th><th>Użytkownik</th><th>Akcja</th><th>Obiekt</th><th>Szczegóły</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td class="nowrap small"><?= e(format_date($row['created_at'])) ?></td>
              <td><?= e($row['username'] ?: '-') ?></td>
              <td><strong><?= e($row['action']) ?></strong></td>
              <td class="mono small"><?= e($row['target']) ?></td>
              <td class="small muted truncate"><?= e($row['details']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($pages > 1): ?>
        <div class="pagination">
          <?php for ($p = max(1, $pageNo - 2); $p <= min($pages, $pageNo + 2); $p++): ?>
            <a class="btn btn--sm <?= $p === $pageNo ? 'btn--primary' : '' ?>" href="<?= e(panel_url('audit', ['page' => $p, 'action' => $action, 'user' => $username])) ?>"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<?php layout_foot(); ?>
