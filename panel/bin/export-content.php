<?php
/**
 * Eksport treści z bazy do plików używanych przez build:
 *  - realizacje → src/data/realizacje.json
 *  - przekierowania → nginx/_redirects/.htaccess
 * Uruchamiane automatycznie przez panel; można też ręcznie (CLI).
 */

if (PHP_SAPI !== 'cli') {
    exit('Skrypt CLI.');
}

require __DIR__ . '/../bootstrap.php';

require_once __DIR__ . '/../lib/realizacje.php';

realizacje_export_json();
redirects_sync_files();

echo "Wyeksportowano realizacje i przekierowania.\n";
