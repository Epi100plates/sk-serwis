<?php
/**
 * Router panelu: /panel/<sekcja>/... → widoki.
 */

require __DIR__ . '/bootstrap.php';

$rawPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/panel/', PHP_URL_PATH);
$base = base_path();
if ($base !== '' && str_starts_with($rawPath, $base)) {
    $rawPath = substr($rawPath, strlen($base));
}
$segments = array_values(array_filter(explode('/', trim($rawPath, '/'))));
$page = $segments[0] ?? '';
$arg1 = $segments[1] ?? '';
$arg2 = $segments[2] ?? '';

// Strony dostępne bez logowania
if ($page === 'login') {
    require __DIR__ . '/pages/login.php';
    exit;
}
if ($page === 'logout') {
    auth_logout();
    redirect_to(panel_url('login'));
}

require_login();

require_once __DIR__ . '/views/layout.php';

$allowed = ['dashboard', 'analytics', 'content', 'editor', 'realizacje', 'kategorie', 'media', 'redirects', 'reviews', 'zgloszenia', 'settings', 'publish', 'users', 'profile', 'audit', 'preview', 'help'];
if ($page === '' || $page === 'dashboard') {
    $page = 'dashboard';
}
if (!in_array($page, $allowed, true)) {
    http_response_code(404);
    layout_head('Nie znaleziono', '');
    echo '<div class="alert alert--err">Nie znaleziono strony panelu.</div>';
    layout_foot();
    exit;
}

// Wymagane uprawnienia administracyjne
if (in_array($page, ['users', 'settings'], true) && (current_user()['role'] ?? '') !== 'admin') {
    http_response_code(403);
    layout_head('Brak uprawnień', '');
    echo '<div class="alert alert--err">Ta sekcja wymaga uprawnień administratora.</div>';
    layout_foot();
    exit;
}

$viewFile = __DIR__ . '/pages/' . $page . '.php';
if (!is_file($viewFile)) {
    http_response_code(404);
    layout_head('Nie znaleziono', '');
    echo '<div class="alert alert--err">Brak widoku tej sekcji.</div>';
    layout_foot();
    exit;
}

require $viewFile;
