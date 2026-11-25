<?php
/**
 * Podgląd wersji roboczych: build lokalny do /var/www/sk-preview, serwowany
 * pod /podglad/ z ochroną basic auth.
 */

$previewDir = (string) cfg('preview_dir', '/var/www/sk-preview');
$exists = is_dir($previewDir) && is_file($previewDir . '/index.html');
$mtime = $exists ? filemtime($previewDir . '/index.html') : null;
$state = publish_state();
$lastPreview = null;
foreach (($state['requests'] ?? []) as $request) {
    if (($request['kind'] ?? '') === 'preview' && $request['status'] !== 'pending') {
        $lastPreview = $request;
        break;
    }
}

if (is_post()) {
    csrf_check();
    if ((string) post('action') === 'request_preview') {
        request_publish('preview');
        flash('ok', 'Dodano żądanie zbudowania podglądu. Zostanie wykonane przy najbliższym uruchomieniu builda lokalnego.');
        redirect_to(panel_url('preview'));
    }
}

layout_head('Podgląd wersji roboczych', 'preview');
?>

<div class="grid grid--sidebar">
  <section class="card">
    <header class="card__head"><h2>Jak działa podgląd</h2></header>
    <div class="card__body">
      <div class="help-box">
        <strong>Podgląd buduje się lokalnie, tak samo jak produkcja.</strong>
        <ol>
          <li>Zapisz zmiany (mogą być jako wersje robocze — <span class="code-inline">draft: true</span>).</li>
          <li>Kliknij „Zbuduj podgląd" — żądanie trafi do komputera publikującego.</li>
          <li>Po zakończeniu podgląd jest dostępny pod adresem z ochroną hasłem.</li>
        </ol>
      </div>
      <p class="small muted" style="margin-top:12px">
        Podgląd zawiera także wersje robocze (szkice), które nie są widoczne publicznie.
        Ma <span class="code-inline">noindex</span> i nie wpływa na wyszukiwarkę.
      </p>
      <div class="form-actions" style="margin-top:16px">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="request_preview">
          <button class="btn btn--yellow" type="submit">Zbuduj podgląd</button>
        </form>
        <?php if ($exists): ?>
          <a class="btn" href="<?= e(cfg('site_url')) ?>/podglad/" target="_blank" rel="noopener">Otwórz podgląd ↗</a>
        <?php endif; ?>
      </div>
      <p class="small muted" style="margin-top:10px">
        Logowanie do podglądu: użytkownik <span class="code-inline">studio-komp</span> (hasło ustawiane na serwerze w
        <span class="code-inline">/etc/nginx/sk-preview.htpasswd</span>).
      </p>
    </div>
  </section>

  <section class="card">
    <header class="card__head"><h2>Status</h2></header>
    <div class="card__body">
      <ul class="list-plain small">
        <li><strong>Ostatni podgląd:</strong> <?= $mtime ? e(format_date(date('Y-m-d H:i:s', $mtime))) : 'jeszcze nie zbudowany' ?></li>
        <li><strong>Katalog:</strong> <span class="mono"><?= e($previewDir) ?></span></li>
        <li><strong>Żądanie w kolejce:</strong> <?= !empty($state['pending']) ? status_badge('warn') : status_badge('ok') ?></li>
      </ul>
      <p class="small muted" style="margin-top:10px">
        Ręcznie na komputerze: <span class="code-inline">pnpm publish:preview</span>.
      </p>
    </div>
  </section>
</div>

<?php layout_foot(); ?>
