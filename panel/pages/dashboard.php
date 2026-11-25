<?php
/**
 * Pulpit: najważniejsze liczby, szybkie akcje, status publikacji i kondycja.
 */

$from = (new DateTimeImmutable('-29 days'))->format('Y-m-d');
$to = (new DateTimeImmutable('now'))->format('Y-m-d');
[$fromSql, $toSql] = analytics_bounds($from, $to);

$summary = analytics_summary($fromSql, $toSql);
$topPages = array_slice(analytics_top_pages($fromSql, $toSql, 8), 0, 8);
$devices = analytics_devices($fromSql, $toSql);
$publish = publish_state();
$state = $publish['requests'][0] ?? null;

$counts = [];
foreach (['uslugi' => 'usługi', 'blog' => 'artykuły', 'marki' => 'marki', 'strony' => 'podstrony'] as $type => $label) {
    $items = list_content($type);
    $drafts = count(array_filter($items, fn($i) => !empty($i['data']['draft'])));
    $counts[$type] = ['total' => count($items), 'drafts' => $drafts, 'label' => $label];
}

$mediaCount = (int) db()->query('SELECT COUNT(*) FROM media')->fetchColumn();
$redirectCount = (int) db()->query('SELECT COUNT(*) FROM redirects WHERE enabled = 1')->fetchColumn();
$realizacjeCount = (int) db()->query('SELECT COUNT(*) FROM realizacje')->fetchColumn();

$reviews = db()->query('SELECT * FROM reviews_cache WHERE id = 1')->fetch() ?: null;
$rating = $reviews['rating'] ?? (float) (data_read('site')['reviews']['rating'] ?? 0);
$reviewCount = $reviews['review_count'] ?? (int) (data_read('site')['reviews']['count'] ?? 0);

$recentContent = [];
foreach (['blog' => 'Poradnik', 'uslugi' => 'Usługa', 'marki' => 'Marka'] as $type => $label) {
    foreach (list_content($type) as $item) {
        $recentContent[] = [
            'type' => $type,
            'label' => $label,
            'title' => $item['data']['title'] ?? $item['id'],
            'id' => $item['id'],
            'mtime' => $item['mtime'],
            'draft' => !empty($item['data']['draft']),
        ];
    }
}
usort($recentContent, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
$recentContent = array_slice($recentContent, 0, 6);

$recentAudit = db()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 8')->fetchAll();

$fmtNum = fn($n) => number_format((int) $n, 0, ',', ' ');

layout_head('Pulpit', 'dashboard');
?>

<div class="alert alert--info">
  Statystyki pochodzą z własnego pomiaru na stronie (tylko użytkownicy, którzy wyrazili zgodę na analitykę).
  Google Analytics / Ads podłączasz w <a href="<?= e(panel_url('settings')) ?>">Ustawieniach</a>.
</div>

<div class="kpis">
  <div class="kpi">
    <span class="kpi__label">Odsłony (30 dni)</span>
    <span class="kpi__value"><?= $fmtNum($summary['pageviews']) ?></span>
    <span class="kpi__hint">wyświetlenia podstron</span>
  </div>
  <div class="kpi">
    <span class="kpi__label">Użytkownicy</span>
    <span class="kpi__value"><?= $fmtNum($summary['visitors']) ?></span>
    <span class="kpi__hint">unikalni w okresie</span>
  </div>
  <div class="kpi">
    <span class="kpi__label">Kliknięcia telefonu</span>
    <span class="kpi__value"><?= $fmtNum($summary['events']['phone_click'] ?? 0) ?></span>
    <span class="kpi__hint">kliknięcie w numer, nie rozmowa</span>
  </div>
  <div class="kpi">
    <span class="kpi__label">Kliknięcia dojazdu</span>
    <span class="kpi__value"><?= $fmtNum($summary['events']['directions_click'] ?? 0) ?></span>
    <span class="kpi__hint">otwarcie nawigacji / mapy</span>
  </div>
  <div class="kpi">
    <span class="kpi__label">Wysłane formularze</span>
    <span class="kpi__value"><?= $fmtNum($summary['events']['contact_form_submit'] ?? 0) ?></span>
    <span class="kpi__hint">potwierdzone wysłanie zgłoszenia</span>
  </div>
  <div class="kpi">
    <span class="kpi__label">Konwersje</span>
    <span class="kpi__value"><?= $fmtNum($summary['conversions']) ?></span>
    <span class="kpi__hint">formularz + potwierdzony lead</span>
  </div>
</div>

<div class="grid grid--sidebar">
  <div class="grid">
    <section class="card">
      <header class="card__head">
        <h2>Najczęściej odwiedzane podstrony</h2>
        <div class="card__actions"><a class="btn btn--sm btn--ghost" href="<?= e(panel_url('analytics')) ?>">Pełna analityka →</a></div>
      </header>
      <div class="card__body card__body--flush">
        <?php if (!$topPages): ?>
          <div class="empty">Brak danych. Odsłony pojawią się po wejściu użytkowników na stronę (z zaakceptowaną analityką).</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead><tr><th>Strona</th><th class="num">Odsłony</th><th class="num">Telefon</th><th class="num">Formularze</th></tr></thead>
              <tbody>
              <?php foreach ($topPages as $row): ?>
                <tr>
                  <td>
                    <div class="row-title"><strong><?= e($row['label']) ?></strong><small class="mono"><?= e($row['page']) ?></small></div>
                  </td>
                  <td class="num"><?= $fmtNum($row['pageviews']) ?></td>
                  <td class="num"><?= $fmtNum($row['phone']) ?></td>
                  <td class="num"><?= $fmtNum($row['forms']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <header class="card__head"><h2>Szybkie skróty</h2></header>
      <div class="card__body">
        <div class="quick-actions">
          <a href="<?= e(panel_url('editor/blog')) ?>">Dodaj artykuł<span>Poradnik dla klientów</span></a>
          <a href="<?= e(panel_url('realizacje/new')) ?>">Dodaj realizację<span>Galeria i slider przed/po</span></a>
          <a href="<?= e(panel_url('media')) ?>">Dodaj zdjęcie<span>Biblioteka mediów</span></a>
          <a href="<?= e(panel_url('editor/uslugi')) ?>">Dodaj usługę<span>Podstrona SEO</span></a>
          <a href="<?= e(panel_url('settings')) ?>">Edytuj stronę główną<span>Hero, statystyki, menu</span></a>
          <a href="<?= e(panel_url('analytics')) ?>">Otwórz analitykę<span>Ruch, urządzenia, źródła</span></a>
        </div>
      </div>
    </section>

    <section class="card">
      <header class="card__head">
        <h2>Ostatnie zmiany treści</h2>
        <div class="card__actions"><a class="btn btn--sm btn--ghost" href="<?= e(panel_url('audit')) ?>">Historia</a></div>
      </header>
      <div class="card__body card__body--flush">
        <?php if (!$recentContent): ?>
          <div class="empty">Brak treści.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead><tr><th>Tytuł</th><th>Typ</th><th>Status</th><th class="num">Zmiana</th></tr></thead>
              <tbody>
              <?php foreach ($recentContent as $item): ?>
                <tr>
                  <td><a href="<?= e(panel_url('editor/' . $item['type'] . '/' . $item['id'])) ?>"><?= e($item['title']) ?></a></td>
                  <td class="muted"><?= e($item['label']) ?></td>
                  <td><?= $item['draft'] ? status_badge('draft') : status_badge('published') ?></td>
                  <td class="num nowrap muted"><?= e(date('d.m.Y H:i', $item['mtime'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <header class="card__head"><h2>Ostatnie działania w panelu</h2></header>
      <div class="card__body">
        <?php if (!$recentAudit): ?>
          <p class="muted small">Brak zapisanych działań.</p>
        <?php else: ?>
          <ul class="list-plain">
            <?php foreach ($recentAudit as $a): ?>
              <li>
                <strong><?= e($a['action']) ?></strong>
                <span class="muted small"><?= e($a['target']) ?> - <?= e($a['username'] ?: '-') ?>, <?= e(format_date($a['created_at'])) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <div class="grid">
    <section class="card">
      <header class="card__head">
        <h2>Publikacja</h2>
        <div class="card__actions"><a class="btn btn--sm btn--ghost" href="<?= e(panel_url('publish')) ?>">Szczegóły</a></div>
      </header>
      <div class="card__body">
        <?php
        $pending = $publish['pending'] ?? [];
        $hb = $publish['heartbeat'] ?? null;
        $hbAge = $hb ? (time() - strtotime($hb['at'])) : null;
        ?>
        <p class="small">
          <?php if ($pending): ?>
            <?= status_badge('warn') ?> Oczekuje na build lokalny (<?= count($pending) ?> żądanie).
          <?php elseif ($state && $state['status'] === 'done'): ?>
            <?= status_badge('ok') ?> Ostatnia publikacja: <?= e(format_date($state['finished_at'] ?? '')) ?> (wersja <?= e($state['release'] ?? '') ?>).
          <?php else: ?>
            <?= status_badge('muted') ?> Brak żądań publikacji.
          <?php endif; ?>
        </p>
        <p class="small muted" style="margin-top:8px">
          Komputer publikujący:
          <?php if ($hbAge !== null && $hbAge < 600): ?>
            <?= status_badge('ok') ?> online (<?= (int) round($hbAge / 60) ?> min temu)
          <?php else: ?>
            <?= status_badge('muted') ?> brak sygnału - buildy wykonuje komputer z projektem
          <?php endif; ?>
        </p>
        <p class="small muted" style="margin-top:10px">
          Build zawsze wykonuje się lokalnie (na komputerze z projektem), a serwer tylko
          przełącza gotową wersję. Dzięki temu strona pozostaje szybka i stabilna.
        </p>
        <div class="form-actions" style="margin-top:12px">
          <form method="post" action="<?= e(panel_url('publish')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request">
            <button class="btn btn--yellow btn--sm" type="submit">Poproś o publikację</button>
          </form>
        </div>
      </div>
    </section>

    <section class="card">
      <header class="card__head"><h2>Opinie Google</h2></header>
      <div class="card__body">
        <p class="small">
          Ocena: <strong><?= number_format((float) $rating, 1, ',', ' ') ?> / 5</strong>
          <?php if ($reviewCount > 0): ?> · <?= $fmtNum($reviewCount) ?> opinii<?php endif; ?>
        </p>
        <?php if (!empty($reviews['fetched_at'])): ?>
          <p class="small muted">Dane pobrane: <?= e(format_date($reviews['fetched_at'])) ?><?= $reviews['error'] ? ' · ' . status_badge('error') : '' ?></p>
        <?php else: ?>
          <p class="small muted">Automatyczne odświeżanie wymaga klucza Google Places API (Ustawienia → Integracje).</p>
        <?php endif; ?>
        <p style="margin-top:10px"><a class="btn btn--sm" href="<?= e(panel_url('reviews')) ?>">Szczegóły i odświeżanie</a></p>
      </div>
    </section>

    <section class="card">
      <header class="card__head"><h2>Zawartość serwisu</h2></header>
      <div class="card__body">
        <ul class="list-plain">
          <li><strong><?= $counts['uslugi']['total'] ?></strong> <span class="muted">usług</span> <span class="muted small">(<?= $counts['uslugi']['drafts'] ?> szkiców)</span></li>
          <li><strong><?= $counts['blog']['total'] ?></strong> <span class="muted">artykułów</span> <span class="muted small">(<?= $counts['blog']['drafts'] ?> szkiców)</span></li>
          <li><strong><?= $counts['marki']['total'] ?></strong> <span class="muted">podstron marek</span></li>
          <li><strong><?= $counts['strony']['total'] ?></strong> <span class="muted">dodatkowych podstron</span></li>
          <li><strong><?= $realizacjeCount ?></strong> <span class="muted">realizacji</span></li>
          <li><strong><?= $mediaCount ?></strong> <span class="muted">plików w mediach</span></li>
          <li><strong><?= $redirectCount ?></strong> <span class="muted">aktywnych przekierowań</span></li>
        </ul>
      </div>
    </section>

    <section class="card">
      <header class="card__head"><h2>Kondycja strony</h2></header>
      <div class="card__body">
        <ul class="list-plain">
          <li><?= status_badge('ok') ?> <span>Strona statyczna (Astro), bez bazy przy żądaniu</span></li>
          <li><?= status_badge('ok') ?> <span>Sitemap i robots generowane przy buildzie</span></li>
          <li><?= status_badge('ok') ?> <span>Obrazy z konwersją WebP w panelu</span></li>
          <li><?= status_badge('ok') ?> <span>Zgody cookies i Consent Mode</span></li>
          <?php if (setting_get('last_git_commit')): ?>
            <li><?= status_badge('info') ?> <span>Ostatni zapis: <?= e(setting_get('last_git_commit')) ?></span></li>
          <?php endif; ?>
        </ul>
        <p class="small muted" style="margin-top:10px">
          Pełny audyt i lista kontrolna: <a href="<?= e(panel_url('help')) ?>">Pomoc panelu</a>.
        </p>
      </div>
    </section>
  </div>
</div>

<?php layout_foot(); ?>
