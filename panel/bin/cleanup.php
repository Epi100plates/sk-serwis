<?php
/**
 * Porządki: retencja analityki, historia treści, logi logowania.
 * Uruchamiaj raz dziennie (cron): php /srv/studio-komp/panel/bin/cleanup.php
 */

if (PHP_SAPI !== 'cli') {
    exit('Skrypt CLI.');
}

require __DIR__ . '/../bootstrap.php';

$removed = analytics_cleanup();

// Historia treści: maksymalnie 60 wpisów na plik, 180 dni retencji
$pdo = db();
$pdo->exec("DELETE FROM content_versions WHERE id NOT IN (
    SELECT id FROM content_versions cv
    WHERE (SELECT COUNT(*) FROM content_versions c2 WHERE c2.kind = cv.kind AND c2.target = cv.target AND c2.id >= cv.id) <= 60
)");
$pdo->exec("DELETE FROM content_versions WHERE created_at < datetime('now', '-180 days')");
$pdo->exec("DELETE FROM audit_log WHERE created_at < datetime('now', '-365 days')");

// Sprzątanie osieroconych miniatur
$mediaDir = rtrim((string) cfg('uploads_dir'), '/');
$thumbs = $mediaDir . '/thumbs';
$orphans = 0;
if (is_dir($thumbs)) {
    $known = $pdo->query('SELECT filename FROM media')->fetchAll(PDO::FETCH_COLUMN);
    $knownThumbs = [];
    foreach ($known as $file) {
        $knownThumbs[pathinfo($file, PATHINFO_FILENAME) . '.webp'] = true;
    }
    foreach (scandir($thumbs) ?: [] as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        if (!isset($knownThumbs[$file])) {
            @unlink($thumbs . '/' . $file);
            $orphans++;
        }
    }
}

echo 'Usunieto rekordow analityki: ' . $removed . "\n";
echo 'Usunieto osieroconych miniatur: ' . $orphans . "\n";
echo "OK\n";
