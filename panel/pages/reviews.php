<?php
/**
 * Opinie Google: status cache, odświeżanie API, przenoszenie do site.json.
 */

$cache = reviews_cache();
$site = data_read('site');
$opinie = data_read('opinie');
$apiKey = reviews_api_key();
$placeId = reviews_place_id();

if (is_post()) {
    csrf_check();
    $action = (string) post('action');
    if ($action === 'refresh') {
        $result = reviews_refresh();
        if ($result['ok']) {
            flash('ok', 'Pobrano dane z Google: ' . number_format((float) $result['rating'], 1, ',', ' ') . ' / 5, ' . (int) $result['count'] . ' opinii.');
        } else {
            flash('err', $result['error']);
        }
        redirect_to(panel_url('reviews'));
    }
    if ($action === 'apply') {
        $result = reviews_apply_to_site();
        if ($result['ok']) {
            flash('ok', 'Zapisano nową ocenę w pliku strony. Zmiana będzie widoczna po publikacji.');
        } else {
            flash('err', $result['error']);
        }
        redirect_to(panel_url('reviews'));
    }
    if ($action === 'clear_cache') {
        db()->prepare("UPDATE reviews_cache SET error = '', fetched_at = NULL, payload = '' WHERE id = 1")->execute();
        flash('info', 'Wyczyszczono cache opinii.');
        redirect_to(panel_url('reviews'));
    }
}

layout_head('Opinie Google', 'reviews');
?>

<div class="grid grid--sidebar">
  <div class="grid">
    <section class="card">
      <header class="card__head"><h2>Dane wizytówki</h2></header>
      <div class="card__body">
        <div class="kpis" style="grid-template-columns:repeat(3,minmax(0,1fr))">
          <div class="kpi">
            <span class="kpi__label">Ocena w Google</span>
            <span class="kpi__value"><?= $cache && $cache['rating'] !== null ? number_format((float) $cache['rating'], 1, ',', ' ') : '-' ?></span>
            <span class="kpi__hint"><?= $cache && $cache['fetched_at'] ? 'pobrano ' . e(format_date($cache['fetched_at'])) : 'dane ręczne / brak cache' ?></span>
          </div>
          <div class="kpi">
            <span class="kpi__label">Liczba opinii</span>
            <span class="kpi__value"><?= $cache && $cache['review_count'] !== null ? number_format((int) $cache['review_count'], 0, ',', ' ') : '-' ?></span>
            <span class="kpi__hint"><?= e((string) ($cache['business_name'] ?? '')) ?></span>
          </div>
          <div class="kpi">
            <span class="kpi__label">W pliku strony</span>
            <span class="kpi__value"><?= number_format((float) ($site['reviews']['rating'] ?? 0), 1, ',', ' ') ?> / 5</span>
            <span class="kpi__hint"><?= (int) ($site['reviews']['count'] ?? 0) ?> opinii (<?= e((string) ($site['reviews']['countLabel'] ?? '')) ?>)</span>
          </div>
        </div>

        <?php if (!empty($cache['error'])): ?>
          <div class="alert alert--err" style="margin-top:14px">Ostatni błąd Google API: <?= e($cache['error']) ?></div>
        <?php endif; ?>

        <div class="form-actions" style="margin-top:16px">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="refresh">
            <button class="btn btn--primary" type="submit" <?= $apiKey ? '' : 'disabled' ?>>Odśwież z Google API</button>
          </form>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="apply">
            <button class="btn" type="submit" <?= ($cache && $cache['rating'] !== null) ? '' : 'disabled' ?>>Przenieś do pliku strony</button>
          </form>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear_cache">
            <button class="btn btn--ghost" type="submit">Wyczyść cache</button>
          </form>
        </div>

        <?php if (!$apiKey): ?>
          <div class="alert alert--warn" style="margin-top:16px">
            <strong>Automatyczne odświeżanie nie jest jeszcze podłączone.</strong>
            Brakuje klucza Google Places API w konfiguracji serwera
            (<span class="code-inline">/srv/studio-komp-cms/config.php</span> →
            <span class="code-inline">google_places_api_key</span>).
            Do tego czasu ocena i liczba opinii są aktualizowane ręcznie w Ustawieniach.
          </div>
        <?php endif; ?>

        <div class="help-box" style="margin-top:16px">
          <strong>Jak to działa i dlaczego tak:</strong>
          <p class="small" style="margin-top:6px">
            Dane pobiera cron (<span class="code-inline">bin/reviews-refresh.php</span>) albo przycisk powyżej,
            maksymalnie raz na kilka godzin. Wynik trafia do bazy i do pliku strony dopiero po zatwierdzeniu,
            więc strona nigdy nie czeka na Google API. Jeśli API zawiedzie, zostają ostatnie poprawne dane.
          </p>
        </div>
      </div>
    </section>

    <section class="card">
      <header class="card__head"><h2>Opinie na stronie (cytaty)</h2></header>
      <div class="card__body card__body--flush">
        <?php $items = $opinie['items'] ?? []; ?>
        <?php if (!$items): ?>
          <div class="empty">Brak opinii w pliku <span class="code-inline">src/data/opinie.json</span>.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead><tr><th>Autor</th><th>Treść</th><th class="num">Ocena</th></tr></thead>
              <tbody>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td class="nowrap"><strong><?= e($item['name'] ?? '') ?></strong></td>
                  <td><span class="truncate" style="display:block;max-width:60ch"><?= e($item['text'] ?? '') ?></span></td>
                  <td class="num"><?= (int) ($item['rating'] ?? 0) ?>/5</td>
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
    <section class="card">
      <header class="card__head"><h2>Linki do opinii</h2></header>
      <div class="card__body">
        <p class="small muted">Publiczne przyciski „Opinie Google" korzystają z linku ustawionego w pliku strony.</p>
        <ul class="list-plain small" style="margin-top:10px">
          <li><strong>Link opinii:</strong><br><a href="<?= e((string) ($site['reviews']['url'] ?? '')) ?>" target="_blank" rel="noopener" class="mono"><?= e((string) ($site['reviews']['url'] ?? '')) ?></a></li>
          <li><strong>Wizytówka:</strong><br><a href="<?= e((string) ($site['reviews']['placeUrl'] ?? '')) ?>" target="_blank" rel="noopener" class="mono"><?= e((string) ($site['reviews']['placeUrl'] ?? '')) ?></a></li>
          <li><strong>Dodawanie opinii:</strong><br><a href="<?= e((string) ($site['reviews']['writeUrl'] ?? '')) ?>" target="_blank" rel="noopener" class="mono"><?= e((string) ($site['reviews']['writeUrl'] ?? '')) ?></a></li>
        </ul>
        <p class="small muted" style="margin-top:12px">
          Pojedyncze opinie prowadzą do wizytówki (link ogólny), bo Google nie udostępnia
          stabilnych adresów pojedynczych recenzji. Nie generujemy fikcyjnych linków.
        </p>
        <p style="margin-top:12px"><a class="btn btn--sm" href="<?= e(panel_url('settings', ['tab' => 'reviews'])) ?>">Zmień linki w Ustawieniach</a></p>
      </div>
    </section>

    <section class="card">
      <header class="card__head"><h2>Konfiguracja API</h2></header>
      <div class="card__body">
        <ul class="list-plain small">
          <li><strong>Place ID:</strong> <span class="mono"><?= e($placeId ?: '-') ?></span></li>
          <li><strong>Klucz API:</strong> <?= $apiKey ? status_badge('ok') . ' ustawiony' : status_badge('warn') . ' brak' ?></li>
          <li><strong>Źródło:</strong> Google Places API (Place Details)</li>
        </ul>
        <p class="small muted" style="margin-top:10px">
          Wymagany klucz z włączonym Places API. Alternatywa (Google Business Profile API)
          daje więcej danych, ale wymaga weryfikacji firmy i OAuth - szczegóły w
          <span class="code-inline">AGENTS.md</span>.
        </p>
      </div>
    </section>
  </div>
</div>

<?php layout_foot(); ?>
