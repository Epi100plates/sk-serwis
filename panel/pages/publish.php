<?php
/**
 * Publikacja: kolejka, status, wersje, log. Build wykonuje komputer lokalny.
 */

$publish = publish_state();
$releases = releases();
$canSwitch = (current_user()['role'] ?? '') === 'admin';

if (is_post()) {
    csrf_check();
    $action = (string) post('action');
    if ($action === 'request') {
        request_publish('publish');
        flash('ok', 'Dodano żądanie publikacji. Zostanie wykonane przy najbliższym uruchomieniu builda lokalnego.');
        redirect_to(panel_url('publish'));
    }
    if ($action === 'switch' && $canSwitch) {
        try {
            switch_release((string) post('release'));
            flash('ok', 'Przełączono wersję strony.');
        } catch (Throwable $e) {
            flash('err', $e->getMessage());
        }
        redirect_to(panel_url('publish'));
    }
    if ($action === 'clear' && $canSwitch) {
        mark_requests_done(999999, 'cancelled', '', 'anulowane w panelu');
        @unlink(publish_request_file());
        flash('info', 'Wyczyszczono kolejkę publikacji.');
        redirect_to(panel_url('publish'));
    }
}

$pending = $publish['pending'] ?? [];
$state = $publish['requests'][0] ?? null;
$heartbeat = $publish['heartbeat'] ?? null;
$log = publish_log_tail(120);

layout_head('Publikacja', 'publish');
?>

<div class="grid grid--sidebar">
  <div class="grid">
    <section class="card">
      <header class="card__head"><h2>Jak działa publikacja</h2></header>
      <div class="card__body">
        <div class="help-box">
          <strong>Build wykonuje się na komputerze z projektem, nie na serwerze.</strong>
          <ol>
            <li>W panelu klikasz „Poproś o publikację" - żądanie trafia do kolejki.</li>
            <li>Komputer publikujący (zadanie „Studio-Komp Publish") wykrywa żądanie, pobiera najnowszą treść z repozytorium, buduje stronę i wgrywa gotowe pliki.</li>
            <li>Serwer przełącza nową wersję atomowo - użytkownicy nigdy nie widzą połowicznego wdrożenia.</li>
            <li>Panel pokazuje status, log i listę wersji do przywrócenia.</li>
          </ol>
        </div>
        <p class="small muted" style="margin-top:12px">
          Ręczne uruchomienie na komputerze: <span class="code-inline">pnpm publish:remote</span>.
          Podgląd wersji roboczych: <span class="code-inline">pnpm publish:preview</span> (adres pojawi się na stronie Podgląd).
        </p>
      </div>
    </section>

    <section class="card">
      <header class="card__head">
        <h2>Kolejka i ostatnia publikacja</h2>
        <div class="card__actions">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request">
            <button class="btn btn--yellow btn--sm" type="submit">Poproś o publikację</button>
          </form>
        </div>
      </header>
      <div class="card__body">
        <?php if ($pending): ?>
          <div class="alert alert--warn">
            <strong>Oczekuje na build:</strong> <?= count($pending) ?> żądanie.
            Ostatnie zgłoszenie: <?= e(format_date($pending[0]['requested_at'])) ?>, przez <?= e($pending[0]['requested_by']) ?>.
          </div>
        <?php else: ?>
          <div class="alert alert--info">Brak oczekujących publikacji.</div>
        <?php endif; ?>

        <?php if ($state): ?>
          <div class="table-wrap" style="margin-top:14px">
            <table class="table table--compact">
              <tbody>
                <tr><th style="width:200px">Status ostatniego żądania</th><td><?= e($state['status']) ?></td></tr>
                <tr><th>Zgłoszone</th><td><?= e(format_date($state['requested_at'])) ?> (<?= e($state['requested_by']) ?>)</td></tr>
                <tr><th>Zakończone</th><td><?= e(format_date((string) ($state['finished_at'] ?? ''))) ?></td></tr>
                <tr><th>Wersja</th><td><?= e((string) ($state['release'] ?? '-')) ?></td></tr>
                <?php if (!empty($state['error'])): ?><tr><th>Błąd</th><td><span class="badge badge--err"><?= e($state['error']) ?></span></td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <p class="small muted" style="margin-top:12px">
          Sygnał komputera publikującego:
          <?php if ($heartbeat && (time() - strtotime($heartbeat['at'])) < 600): ?>
            <?= status_badge('ok') ?> <?= e(format_date($heartbeat['at'])) ?>
          <?php else: ?>
            <?= status_badge('muted') ?> brak (komputer wyłączony lub zadanie nieuruchomione)
          <?php endif; ?>
        </p>
      </div>
    </section>

    <section class="card">
      <header class="card__head"><h2>Log ostatniego builda</h2></header>
      <div class="card__body card__body--flush">
        <?php if ($log === ''): ?>
          <div class="empty">Brak logu - publikacja nie była jeszcze uruchamiana z tego panelu.</div>
        <?php else: ?>
          <pre class="code-inline" style="display:block;white-space:pre-wrap;padding:14px;font-size:12px;line-height:1.5;max-height:420px;overflow:auto"><?= e($log) ?></pre>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <div class="grid">
    <section class="card">
      <header class="card__head"><h2>Wersje strony</h2></header>
      <div class="card__body card__body--flush">
        <?php if (!$releases): ?>
          <div class="empty">Brak wersji na serwerze. Pierwsza publikacja utworzy wersję.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead><tr><th>Wersja</th><th>Data</th><th class="num">Plików</th><th></th></tr></thead>
              <tbody>
              <?php foreach ($releases as $release): ?>
                <tr>
                  <td class="mono"><?= e($release['name']) ?><?= $release['current'] ? ' ' . status_badge('ok') : '' ?></td>
                  <td class="nowrap small"><?= e(format_date($release['created_at'])) ?></td>
                  <td class="num"><?= (int) $release['files'] ?></td>
                  <td class="num">
                    <?php if ($canSwitch && !$release['current']): ?>
                      <form method="post" onsubmit="return confirm('Przywrócić wersję <?= e($release['name']) ?>?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="switch">
                        <input type="hidden" name="release" value="<?= e($release['name']) ?>">
                        <button class="btn btn--sm" type="submit">Przywróć</button>
                      </form>
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

    <section class="card">
      <header class="card__head"><h2>Ustawienia publikacji</h2></header>
      <div class="card__body">
        <ul class="list-plain small">
          <li><strong>Tryb:</strong> lokalny (kolejka)</li>
          <li><strong>Katalog wersji:</strong> <span class="mono"><?= e((string) cfg('releases_dir')) ?></span></li>
          <li><strong>Katalog strony:</strong> <span class="mono"><?= e((string) cfg('webroot')) ?></span></li>
          <li><strong>Repozytorium:</strong> <span class="mono"><?= e((string) cfg('repo_dir')) ?></span></li>
        </ul>
        <?php if ($canSwitch && $pending): ?>
          <form method="post" style="margin-top:12px" onsubmit="return confirm('Anulować wszystkie oczekujące publikacje?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear">
            <button class="btn btn--danger btn--sm" type="submit">Anuluj kolejkę</button>
          </form>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<?php layout_foot(); ?>
