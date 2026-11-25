<?php
/**
 * Układ panelu. Funkcje pomocnicze widoków.
 */

function layout_head(string $title, string $active = ''): void
{
    $user = current_user();
    $cssVer = @filemtime(dirname(__DIR__) . '/assets/admin.css') ?: time();
    $jsVer = @filemtime(dirname(__DIR__) . '/assets/admin.js') ?: time();
    $nav = [
        'dashboard' => ['Pulpit', 'grid', panel_url('')],
        'zgloszenia' => ['Zgłoszenia', 'inbox', panel_url('zgloszenia')],
        'analytics' => ['Analityka', 'chart', panel_url('analytics')],
        'uslugi' => ['Usługi', 'tools', panel_url('content/uslugi')],
        'blog' => ['Poradnik', 'book', panel_url('content/blog')],
        'marki' => ['Marki', 'laptop', panel_url('content/marki')],
        'strony' => ['Podstrony', 'page', panel_url('content/strony')],
        'realizacje' => ['Realizacje', 'case', panel_url('realizacje')],
        'media' => ['Media', 'image', panel_url('media')],
        'redirects' => ['Przekierowania', 'link', panel_url('redirects')],
        'reviews' => ['Opinie Google', 'star', panel_url('reviews')],
        'settings' => ['Ustawienia', 'settings', panel_url('settings')],
        'publish' => ['Publikacja', 'upload', panel_url('publish')],
        'users' => ['Użytkownicy', 'user', panel_url('users')],
    ];
    ?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> - Panel Studio-Komp</title>
  <link rel="icon" href="/favicon.ico?v=2" sizes="any">
  <link rel="icon" href="/favicon.svg?v=2" type="image/svg+xml">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2" sizes="180x180">
  <link rel="stylesheet" href="<?= e(base_path()) ?>/assets/admin.css?v=<?= (int) $cssVer ?>">
</head>
<body class="admin">
<a class="admin-skip" href="#main">Przejdź do treści</a>
<div class="admin-shell">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar__brand">
      <a href="<?= e(panel_url('')) ?>">
        <span class="sidebar__mark">SK</span>
        <span class="sidebar__name">Studio-Komp<small>panel</small></span>
      </a>
    </div>
    <nav class="sidebar__nav" aria-label="Nawigacja panelu">
      <?php foreach ($nav as $key => $item): ?>
        <a class="sidebar__link<?= $active === $key ? ' is-active' : '' ?>" href="<?= e($item[2]) ?>">
          <span class="sidebar__icon"><?= icon_svg($item[1]) ?></span>
          <span><?= e($item[0]) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar__foot">
      <div class="sidebar__user">
        <strong><?= e($user['username'] ?? '') ?></strong>
        <span><?= e($user['role'] ?? '') ?></span>
      </div>
      <a class="sidebar__logout" href="<?= e(panel_url('logout')) ?>">Wyloguj</a>
      <p class="sidebar__version">v1.0 · <?= date('Y') ?></p>
    </div>
  </aside>

  <div class="admin-main">
    <header class="topbar">
      <button class="topbar__burger" type="button" data-sidebar-toggle aria-label="Otwórz menu" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
      <h1 class="topbar__title"><?= e($title) ?></h1>
      <div class="topbar__actions">
        <a class="btn btn--sm" href="<?= e(panel_url('publish')) ?>">Publikuj</a>
        <a class="btn btn--sm btn--ghost" href="<?= e(cfg('site_url')) ?>" target="_blank" rel="noopener">Zobacz stronę ↗</a>
      </div>
    </header>
    <main class="content" id="main">
      <?php foreach (flashes() as $flash): ?>
        <div class="alert alert--<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
      <?php endforeach; ?>
    <?php
}

function layout_foot(): void
{
    ?>
    </main>
    <footer class="admin-foot">
      <span>Studio-Komp - panel treści. Statystyki pochodzą z własnego pomiaru (tylko użytkownicy z zgodą analityczną).</span>
    </footer>
  </div>
</div>
<div class="sidebar-backdrop" data-sidebar-backdrop hidden></div>
<script src="<?= e(base_path()) ?>/assets/admin.js?v=<?= (int) $jsVer ?>"></script>
</body>
</html>
    <?php
}

function icon_svg(string $name): string
{
    $icons = [
        'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'tools' => '<path d="M14.7 6.3a4 4 0 0 0 5 5L21 13l-8 8-2-2"/><path d="M3 21l6-6"/>',
        'book' => '<path d="M4 4h6a4 4 0 0 1 4 4v12a3 3 0 0 0-3-3H4z"/><path d="M20 4h-4a4 4 0 0 0-4 4v12a3 3 0 0 1 3-3h5z"/>',
        'laptop' => '<rect x="3" y="4" width="18" height="12" rx="1"/><path d="M2 20h20"/>',
        'page' => '<path d="M6 2h9l5 5v15H6z"/><path d="M15 2v5h5"/>',
        'case' => '<rect x="3" y="7" width="18" height="13" rx="1"/><path d="M9 7V4h6v3"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="1"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
        'star' => '<path d="M12 3l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.8 6.2 20.9l1.1-6.5L2.6 9.8l6.5-.9z"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'upload' => '<path d="M12 16V4"/><path d="M6 10l6-6 6 6"/><path d="M4 20h16"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'inbox' => '<path d="M3 13h5l2 3h4l2-3h5"/><path d="M5 5h14l2 8v6H3v-6z"/>',
    ];
    $body = $icons[$name] ?? $icons['grid'];
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

function admin_card(string $title, string $bodyHtml, string $class = ''): void
{
    ?>
    <section class="card <?= e($class) ?>">
      <?php if ($title !== ''): ?>
        <header class="card__head"><h2><?= e($title) ?></h2></header>
      <?php endif; ?>
      <div class="card__body"><?= $bodyHtml ?></div>
    </section>
    <?php
}

function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

function format_date(string $value, bool $withTime = true): string
{
    if ($value === '') {
        return '-';
    }
    try {
        $dt = new DateTimeImmutable($value);
        return $dt->format($withTime ? 'd.m.Y H:i' : 'd.m.Y');
    } catch (Throwable $e) {
        return $value;
    }
}

function status_badge(string $status): string
{
    $map = [
        'published' => ['Opublikowana', 'ok'],
        'draft' => ['Wersja robocza', 'warn'],
        'pending' => ['Do zatwierdzenia', 'warn'],
        'approved' => ['Zatwierdzona', 'ok'],
        'enabled' => ['Aktywne', 'ok'],
        'disabled' => ['Wyłączone', 'muted'],
        'ok' => ['OK', 'ok'],
        'error' => ['Błąd', 'err'],
    ];
    [$label, $tone] = $map[$status] ?? [$status, 'muted'];
    return '<span class="badge badge--' . e($tone) . '">' . e($label) . '</span>';
}
