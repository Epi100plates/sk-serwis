<?php
/**
 * Inicjalizacja panelu po wdrożeniu (CLI, jako skcms):
 *  - przeniesienie startowych przekierowań do bazy,
 *  - przeniesienie startowych realizacji do bazy,
 *  - wygenerowanie plików przekierowań,
 *  - sprawdzenie katalogów i konfiguracji.
 */

if (PHP_SAPI !== 'cli') {
    exit('Skrypt CLI.');
}

require __DIR__ . '/../bootstrap.php';

$messages = [];

$seededRedirects = redirects_seed_if_empty();
$messages[] = $seededRedirects > 0
    ? "Dodano startowe przekierowania: $seededRedirects"
    : 'Przekierowania juz istnieja: ' . (int) db()->query('SELECT COUNT(*) FROM redirects')->fetchColumn();

if (is_file(__DIR__ . '/../lib/realizacje.php')) {
    require_once __DIR__ . '/../lib/realizacje.php';
    realizacje_seed_if_empty();
    realizacje_export_json();
    $messages[] = 'Realizacje w bazie: ' . (int) db()->query('SELECT COUNT(*) FROM realizacje')->fetchColumn();
}

$requiredDirs = [
    (string) cfg('data_dir'),
    (string) cfg('data_dir') . '/logs',
    (string) cfg('data_dir') . '/sessions',
    (string) cfg('uploads_dir'),
    (string) cfg('releases_dir'),
];
foreach ($requiredDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $messages[] = (is_dir($dir) ? '[ok] ' : '[BLAD] ') . $dir;
}

$messages[] = 'Uzytkownicy: ' . (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$messages[] = 'Baza: ' . (string) cfg('data_dir') . '/panel.sqlite';

foreach ($messages as $message) {
    echo $message . "\n";
}
