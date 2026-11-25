<?php
/**
 * Zgłoszenia serwisowe z formularza: lista z filtrami, szczegóły, historia
 * statusów, kolejka powiadomień (SMS/e-mail) z ręcznym ponowieniem,
 * eksport CSV. Dostęp: admin i editor; usuwanie tylko administrator.
 */

/**
 * Badge statusu zgłoszenia (kolory i etykiety z biblioteki zgłoszeń).
 */
function ticket_status_badge(string $status): string
{
    return '<span class="badge badge--' . e(ticket_status_tone($status)) . '">' . e(ticket_status_label($status)) . '</span>';
}

/**
 * Badge statusu powiadomienia (kolejka/wysłane/błąd/test/pominięte).
 */
function ticket_notification_badge(string $status): string
{
    return '<span class="badge badge--' . e(ticket_notification_status_tone($status)) . '">'
        . e(ticket_notification_status_label($status)) . '</span>';
}

$ticketId = (int) get('id', 0);
$user = current_user();
$role = (string) ($user['role'] ?? '');
$username = (string) ($user['username'] ?? 'system');

/* ------------------------------------------------------------------ akcje */

if (is_post()) {
    csrf_check();
    $action = (string) post('action');

    if ($action === 'save_status') {
        $id = (int) post('ticket_id', 0);
        $status = (string) post('status');
        $note = (string) post('note');
        $result = tickets_change_status($id, $status, $note, $username);
        if ($result['ok']) {
            audit('ticket_status', 'ticket:' . $id, ['status' => $status]);
            flash('ok', 'Zapisano status zgłoszenia: ' . ticket_status_label($status) . '.');
        } else {
            flash('err', (string) ($result['error'] ?? 'Nie udało się zapisać statusu.'));
        }
        redirect_to(panel_url('zgloszenia', ['id' => $id]));
    }

    if ($action === 'retry_notification') {
        $notificationId = (int) post('notification_id', 0);
        $ticketIdForRedirect = (int) post('ticket_id', 0);
        $result = tickets_retry_notification($notificationId);
        if ($result['ok']) {
            audit('ticket_notification_retry', 'notification:' . $notificationId, []);
            flash('ok', 'Powiadomienie wróciło do kolejki - worker wyśle je w ciągu minuty.');
        } else {
            flash('err', (string) ($result['error'] ?? 'Nie udało się ponowić powiadomienia.'));
        }
        redirect_to(panel_url('zgloszenia', ['id' => $ticketIdForRedirect]));
    }

    if ($action === 'process_queue') {
        $summary = tickets_process_queue(20);
        audit('ticket_queue_run', '', $summary);
        flash('ok', 'Kolejka: przetworzono ' . $summary['processed']
            . ', wysłano ' . $summary['sent']
            . ', tryb testowy ' . $summary['test']
            . ', pominięto ' . $summary['skipped']
            . ', błędy ' . $summary['failed'] . '.');
        redirect_to(panel_url('zgloszenia'));
    }

    if ($action === 'delete') {
        if ($role !== 'admin') {
            flash('err', 'Usuwanie zgłoszeń jest dostępne wyłącznie dla administratora.');
            redirect_to(panel_url('zgloszenia'));
        }
        $id = (int) post('ticket_id', 0);
        $ticket = tickets_by_id($id);
        if ($ticket && tickets_delete($id)) {
            audit('ticket_delete', 'ticket:' . $id, ['number' => $ticket['number']]);
            flash('ok', 'Zgłoszenie ' . $ticket['number'] . ' zostało usunięte.');
        } else {
            flash('err', 'Nie udało się usunąć zgłoszenia.');
        }
        redirect_to(panel_url('zgloszenia'));
    }
}

/* --------------------------------------------------------------- filtry */

$statusFilter = (string) get('status', '');
if ($statusFilter !== '' && !isset(TICKET_STATUSES[$statusFilter])) {
    $statusFilter = '';
}
$q = tickets_normalize_text((string) get('q', ''), 80);
$pageNo = max(1, (int) get('page', 1));
$perPage = 20;

$where = ['archived = 0'];
$params = [];
if ($statusFilter !== '') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $where[] = "(number LIKE ? ESCAPE '\\' OR phone LIKE ? ESCAPE '\\' OR name LIKE ? ESCAPE '\\')";
    array_push($params, $like, $like, $like);
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$counts = tickets_counts();

/* ------------------------------------------------------------ eksport CSV */

if ((string) get('export', '') === 'csv') {
    $st = db()->prepare('SELECT * FROM tickets' . $whereSql . ' ORDER BY id DESC LIMIT 5000');
    $st->execute($params);
    $rows = $st->fetchAll();
    audit('ticket_export', '', ['count' => count($rows)]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="zgloszenia-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Numer', 'Data', 'Status', 'Imię', 'Telefon', 'Sprzęt', 'Opis usterki', 'Strona źródłowa']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['number'],
            $row['created_at'],
            ticket_status_label((string) $row['status']),
            $row['name'],
            tickets_display_phone((string) $row['phone']),
            $row['device'],
            $row['problem'],
            $row['source_page'],
        ]);
    }
    fclose($out);
    exit;
}

/* ------------------------------------------------------------ szczegóły */

if ($ticketId > 0) {
    $ticket = tickets_by_id($ticketId);
    if (!$ticket) {
        layout_head('Zgłoszenia', 'zgloszenia');
        echo '<div class="alert alert--err">Nie znaleziono zgłoszenia.</div>';
        echo '<p><a class="btn btn--sm" href="' . e(panel_url('zgloszenia')) . '">Wróć do listy</a></p>';
        layout_foot();
        exit;
    }

    // Otwarcie szczegółów oznacza "odczytane" (z wpisem w historii).
    if ($ticket['status'] === 'new') {
        $mark = tickets_change_status($ticketId, 'read', 'Odczytane w panelu.', $username);
        if ($mark['ok']) {
            audit('ticket_read', 'ticket:' . $ticketId, []);
            $ticket = tickets_by_id($ticketId) ?: $ticket;
        }
    }

    $history = tickets_history($ticketId);
    $notifications = tickets_notifications($ticketId);
    $mailCfg = mailer_config();
    $smsCfg = tickets_sms_config();

    layout_head('Zgłoszenie ' . $ticket['number'], 'zgloszenia');
    ?>
    <p class="toolbar">
      <a class="btn btn--sm" href="<?= e(panel_url('zgloszenia')) ?>">← Lista zgłoszeń</a>
      <span class="spacer"></span>
      <a class="btn btn--sm btn--ghost" href="<?= e(panel_url('zgloszenia', ['id' => $ticketId, 'export' => 'csv'])) ?>">Eksport CSV</a>
    </p>

    <div class="grid grid--sidebar">
      <section class="card">
        <header class="card__head">
          <h2><?= e($ticket['number']) ?></h2>
          <div class="card__actions"><?= ticket_status_badge($ticket['status']) ?></div>
        </header>
        <div class="card__body">
          <div class="table-wrap">
            <table class="table table--compact">
              <tbody>
                <tr><th>Data zgłoszenia</th><td><?= e(format_date($ticket['created_at'])) ?></td></tr>
                <tr><th>Imię</th><td><strong><?= e($ticket['name']) ?></strong></td></tr>
                <tr><th>Telefon</th><td><a href="tel:<?= e($ticket['phone']) ?>" class="mono"><?= e(tickets_display_phone($ticket['phone'])) ?></a></td></tr>
                <tr><th>Sprzęt</th><td><?= e($ticket['device'] !== '' ? $ticket['device'] : '-') ?></td></tr>
                <tr><th>Opis usterki</th><td><div class="ticket-message"><?= nl2br(e($ticket['problem'])) ?></div></td></tr>
                <tr><th>Strona źródłowa</th><td class="small"><?= e($ticket['source_page'] !== '' ? $ticket['source_page'] : '-') ?></td></tr>
                <tr><th>Zgoda</th><td class="small"><?= e($ticket['consent_at'] !== '' ? format_date($ticket['consent_at']) : '-') ?> (wersja <?= e($ticket['consent_version'] !== '' ? $ticket['consent_version'] : '-') ?>)</td></tr>
                <tr><th>Ostatnia zmiana</th><td class="small"><?= e(format_date($ticket['updated_at'])) ?></td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section class="card">
        <header class="card__head"><h2>Status i notatka</h2></header>
        <div class="card__body">
          <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_status">
            <input type="hidden" name="ticket_id" value="<?= (int) $ticketId ?>">
            <label class="field"><span>Nowy status</span>
              <select name="status">
                <?php foreach (TICKET_STATUSES as $key => $label): ?>
                  <option value="<?= e($key) ?>" <?= $ticket['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field"><span>Notatka (opcjonalnie)</span>
              <textarea name="note" rows="3" maxlength="500" placeholder="np. Oddzwoniono, klient przywiezie sprzęt w czwartek."></textarea>
            </label>
            <div class="form-actions">
              <button class="btn btn--primary" type="submit">Zapisz status</button>
            </div>
          </form>

          <?php if ($role === 'admin'): ?>
            <form method="post" style="margin-top:18px" onsubmit="return confirm('Usunąć zgłoszenie <?= e($ticket['number']) ?> razem z historią? Tej operacji nie można cofnąć.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="ticket_id" value="<?= (int) $ticketId ?>">
              <button class="btn btn--sm btn--danger" type="submit">Usuń zgłoszenie</button>
            </form>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <section class="card" style="margin-top:16px">
      <header class="card__head">
        <h2>Historia statusów</h2>
        <div class="card__actions"><span class="small muted"><?= count($history) ?> wpisów</span></div>
      </header>
      <div class="card__body card__body--flush">
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Data</th><th>Status</th><th>Notatka</th><th>Autor</th></tr></thead>
            <tbody>
            <?php foreach ($history as $entry): ?>
              <tr>
                <td class="nowrap small"><?= e(format_date($entry['created_at'])) ?></td>
                <td><?= ticket_status_badge($entry['status']) ?></td>
                <td class="small"><?= e($entry['note'] !== '' ? $entry['note'] : '-') ?></td>
                <td class="small"><?= e($entry['username']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section class="card" style="margin-top:16px">
      <header class="card__head">
        <h2>Powiadomienia</h2>
        <div class="card__actions">
          <span class="small muted">
            SMS: <?= $smsCfg['test_mode'] ? 'tryb testowy (bez wysyłki)' : 'aktywny' ?>,
            e-mail: <?= $mailCfg['enabled'] ? 'włączony' : 'wyłączony' ?>
          </span>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="process_queue">
            <button class="btn btn--sm" type="submit">Przetwórz kolejkę</button>
          </form>
        </div>
      </header>
      <div class="card__body card__body--flush">
        <?php if (!$notifications): ?>
          <div class="card__body"><div class="empty">Brak powiadomień dla tego zgłoszenia.</div></div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead>
                <tr><th>Kanał</th><th>Odbiorca</th><th>Status</th><th>Próby</th><th>Następna próba</th><th>ID u dostawcy</th><th>Błąd</th><th>Akcje</th></tr>
              </thead>
              <tbody>
              <?php foreach ($notifications as $notification): ?>
                <tr>
                  <td class="nowrap"><strong><?= $notification['channel'] === 'sms' ? 'SMS' : 'E-mail' ?></strong><br><span class="small muted"><?= e($notification['provider']) ?></span></td>
                  <td class="small"><?= e($notification['recipient'] !== '' ? $notification['recipient'] : 'brak konfiguracji') ?></td>
                  <td><?= ticket_notification_badge($notification['status']) ?></td>
                  <td class="num"><?= (int) $notification['attempts'] ?> / <?= TICKET_NOTIFY_MAX_ATTEMPTS ?></td>
                  <td class="small nowrap"><?= e($notification['next_attempt_at'] ? format_date($notification['next_attempt_at']) : '-') ?></td>
                  <td class="small mono"><?= e($notification['provider_message_id'] !== '' ? $notification['provider_message_id'] : '-') ?><br><span class="muted"><?= e($notification['delivery_status']) ?></span></td>
                  <td class="small muted truncate" title="<?= e($notification['last_error']) ?>"><?= e($notification['last_error'] !== '' ? $notification['last_error'] : '-') ?></td>
                  <td class="nowrap">
                    <?php if ($notification['status'] !== 'sent'): ?>
                      <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="retry_notification">
                        <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                        <input type="hidden" name="ticket_id" value="<?= (int) $ticketId ?>">
                        <button class="btn btn--sm" type="submit">Ponów</button>
                      </form>
                    <?php else: ?>
                      <span class="small muted">wysłane</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <p class="small muted" style="margin-top:14px">
      Ponowienie wysyła tę samą wiadomość jeszcze raz. SMS w trybie testowym nie łączy się z dostawcą -
      pokazuje jedynie, jak wyglądałaby wysyłka. Pełna konfiguracja: Ustawienia → Formularz.
    </p>
    <?php
    layout_foot();
    exit;
}

/* ------------------------------------------------------------------ lista */

$countSt = db()->prepare('SELECT COUNT(*) FROM tickets' . $whereSql);
$countSt->execute($params);
$total = (int) $countSt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
if ($pageNo > $pages) {
    $pageNo = $pages;
}
$offset = ($pageNo - 1) * $perPage;

$st = db()->prepare(
    'SELECT t.*,
        (SELECT n.status FROM notifications n WHERE n.ticket_id = t.id AND n.channel = "sms" ORDER BY n.id ASC LIMIT 1) AS sms_status,
        (SELECT n.status FROM notifications n WHERE n.ticket_id = t.id AND n.channel = "email" ORDER BY n.id ASC LIMIT 1) AS email_status
     FROM tickets t' . $whereSql . ' ORDER BY t.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
);
$st->execute($params);
$rows = $st->fetchAll();

layout_head('Zgłoszenia', 'zgloszenia');
?>

<section class="card">
  <header class="card__head">
    <h2>Zgłoszenia serwisowe</h2>
    <div class="card__actions">
      <span class="small muted"><?= (int) $total ?> w tym widoku</span>
      <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="process_queue">
        <button class="btn btn--sm" type="submit">Przetwórz kolejkę</button>
      </form>
      <a class="btn btn--sm btn--ghost" href="<?= e(panel_url('zgloszenia', ['status' => $statusFilter, 'q' => $q, 'export' => 'csv'])) ?>">Eksport CSV</a>
    </div>
  </header>
  <div class="card__body">
    <div class="tickets-counters">
      <?php foreach (TICKET_STATUSES as $key => $label): ?>
        <a class="tickets-counter<?= $statusFilter === $key ? ' is-active' : '' ?>" href="<?= e(panel_url('zgloszenia', ['status' => $key])) ?>">
          <span class="tickets-counter__value"><?= (int) ($counts[$key] ?? 0) ?></span>
          <span class="tickets-counter__label"><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
      <a class="tickets-counter<?= $statusFilter === '' ? ' is-active' : '' ?>" href="<?= e(panel_url('zgloszenia')) ?>">
        <span class="tickets-counter__value"><?= (int) ($counts['all'] ?? 0) ?></span>
        <span class="tickets-counter__label">Wszystkie</span>
      </a>
    </div>

    <form method="get" class="toolbar">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Numer, telefon lub imię" aria-label="Szukaj zgłoszenia">
      <select name="status" aria-label="Filtr statusu">
        <option value="">Wszystkie statusy</option>
        <?php foreach (TICKET_STATUSES as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn--sm" type="submit">Filtruj</button>
      <?php if ($q !== '' || $statusFilter !== ''): ?>
        <a class="btn btn--sm btn--ghost" href="<?= e(panel_url('zgloszenia')) ?>">Wyczyść</a>
      <?php endif; ?>
    </form>

    <?php if (!$rows): ?>
      <div class="empty">Brak zgłoszeń w tym widoku.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr><th>Numer</th><th>Data</th><th>Klient</th><th>Sprzęt</th><th>Status</th><th>Powiadomienia</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td class="nowrap"><a href="<?= e(panel_url('zgloszenia', ['id' => $row['id']])) ?>"><strong><?= e($row['number']) ?></strong></a></td>
              <td class="nowrap small"><?= e(format_date($row['created_at'])) ?></td>
              <td>
                <div class="row-title">
                  <span><?= e($row['name']) ?></span>
                  <small><?= e(tickets_display_phone($row['phone'])) ?></small>
                </div>
              </td>
              <td class="small"><?= e($row['device'] !== '' ? mb_substr($row['device'], 0, 40, 'UTF-8') : '-') ?></td>
              <td><?= ticket_status_badge($row['status']) ?></td>
              <td class="small nowrap">
                <?php if ($row['sms_status']): ?><span class="muted">SMS:</span> <?= ticket_notification_badge($row['sms_status']) ?><?php endif; ?>
                <?php if ($row['email_status']): ?> <span class="muted">E-mail:</span> <?= ticket_notification_badge($row['email_status']) ?><?php endif; ?>
                <?php if (!$row['sms_status'] && !$row['email_status']): ?><span class="muted">-</span><?php endif; ?>
              </td>
              <td class="nowrap"><a class="btn btn--sm" href="<?= e(panel_url('zgloszenia', ['id' => $row['id']])) ?>">Szczegóły</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pages > 1): ?>
        <div class="pagination">
          <?php for ($p = max(1, $pageNo - 2); $p <= min($pages, $pageNo + 2); $p++): ?>
            <a class="btn btn--sm <?= $p === $pageNo ? 'btn--primary' : '' ?>" href="<?= e(panel_url('zgloszenia', ['status' => $statusFilter, 'q' => $q, 'page' => $p])) ?>"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<p class="small muted" style="margin-top:14px">
  Zgłoszenia zapisują się w bazie panelu. O statusie nowego zgłoszenia informuje SMS do serwisu
  (domyślnie w trybie testowym - bez realnej wysyłki) oraz opcjonalny e-mail.
  Usuwanie zgłoszeń jest dostępne wyłącznie dla administratora.
</p>

<?php layout_foot(); ?>
