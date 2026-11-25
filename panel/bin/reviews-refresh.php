<?php
/**
 * Odświeżanie oceny i liczby opinii Google (Places API) + zapis do strony.
 * Cron (np. co 6 h): php /srv/studio-komp/panel/bin/reviews-refresh.php
 */

if (PHP_SAPI !== 'cli') {
    exit('Skrypt CLI.');
}

require __DIR__ . '/../bootstrap.php';

if (reviews_api_key() === '') {
    echo "Brak klucza Google Places API w konfiguracji - pomijam.\n";
    exit(0);
}

$result = reviews_refresh();
if (!$result['ok']) {
    echo 'Blad: ' . $result['error'] . "\n";
    exit(1);
}

echo 'Pobrano: ocena ' . $result['rating'] . ', opinii: ' . $result['count'] . "\n";

$apply = reviews_apply_to_site();
if (!$apply['ok']) {
    echo 'Nie zapisano do strony: ' . $apply['error'] . "\n";
    exit(1);
}

echo 'Zapisano do src/data/site.json (widoczne po publikacji).' . "\n";
