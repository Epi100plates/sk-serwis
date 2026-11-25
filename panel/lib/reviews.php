<?php
/**
 * Opinie Google: cache w bazie + opcjonalne odświeżanie przez Places API.
 * Strona publiczna nie odpytuje Google przy żądaniu — dane trafiają do SQLite,
 * a po zatwierdzeniu do src/data/site.json przy najbliższym buildzie.
 */

function reviews_cache(): ?array
{
    $row = db()->query('SELECT * FROM reviews_cache WHERE id = 1')->fetch();
    if (!$row) {
        db()->exec("INSERT OR IGNORE INTO reviews_cache (id, source, payload) VALUES (1, 'google_places', '')");
        $row = db()->query('SELECT * FROM reviews_cache WHERE id = 1')->fetch();
    }
    return $row ?: null;
}

function reviews_api_key(): string
{
    return trim((string) cfg('google_places_api_key', ''));
}

function reviews_place_id(): string
{
    return trim((string) cfg('google_place_id', ''));
}

function reviews_refresh(): array
{
    $key = reviews_api_key();
    $placeId = reviews_place_id();
    if ($key === '') {
        return ['ok' => false, 'error' => 'Brak klucza Google Places API w konfiguracji panelu (google_places_api_key).'];
    }
    if ($placeId === '') {
        return ['ok' => false, 'error' => 'Brak ID wizytówki (google_place_id).'];
    }

    $url = 'https://maps.googleapis.com/maps/api/place/details/json?place_id=' . rawurlencode($placeId)
        . '&fields=rating,user_ratings_total,name,formatted_address&language=pl&key=' . rawurlencode($key);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $row = reviews_cache();
    if ($body === false || $httpCode !== 200) {
        $error = $curlError !== '' ? $curlError : ('HTTP ' . $httpCode);
        db()->prepare('UPDATE reviews_cache SET error = ?, fetched_at = ? WHERE id = 1')->execute([$error, now()]);
        log_panel('reviews refresh: ' . $error);
        return ['ok' => false, 'error' => 'Google API nie odpowiedziało: ' . $error . '. Zachowano poprzednie dane.'];
    }

    $json = json_decode($body, true);
    $status = $json['status'] ?? '';
    if ($status !== 'OK') {
        $message = (string) ($json['error_message'] ?? $status ?: 'nieznany błąd');
        db()->prepare('UPDATE reviews_cache SET error = ?, fetched_at = ? WHERE id = 1')->execute([$message, now()]);
        log_panel('reviews refresh API status: ' . $message);
        return ['ok' => false, 'error' => 'Google API: ' . $message];
    }

    $result = $json['result'] ?? [];
    $rating = isset($result['rating']) ? (float) $result['rating'] : (float) ($row['rating'] ?? 0);
    $count = isset($result['user_ratings_total']) ? (int) $result['user_ratings_total'] : (int) ($row['review_count'] ?? 0);
    $name = (string) ($result['name'] ?? ($row['business_name'] ?? ''));

    db()->prepare('UPDATE reviews_cache SET rating = ?, review_count = ?, business_name = ?, source = ?, payload = ?, error = ?, fetched_at = ? WHERE id = 1')
        ->execute([$rating, $count, str_limit($name, 200), 'google_places', json_encode($result, JSON_UNESCAPED_UNICODE), '', now()]);

    audit('reviews_refresh', 'google', ['rating' => $rating, 'count' => $count]);
    return ['ok' => true, 'rating' => $rating, 'count' => $count, 'name' => $name];
}

/**
 * Przenosi pobrane dane do src/data/site.json (widoczne po publikacji).
 * Zwraca listę zmian.
 */
function reviews_apply_to_site(): array
{
    $row = reviews_cache();
    if (!$row || $row['rating'] === null || $row['review_count'] === null) {
        return ['ok' => false, 'error' => 'Brak pobranych danych do zastosowania.'];
    }

    $site = data_read('site');
    $oldRating = (float) ($site['reviews']['rating'] ?? 0);
    $oldCount = (int) ($site['reviews']['count'] ?? 0);
    $count = (int) $row['review_count'];

    $site['reviews']['rating'] = (float) $row['rating'];
    $site['reviews']['count'] = $count;
    $site['reviews']['countLabel'] = $count >= 500 ? floor($count / 50) * 50 . '+' : (string) $count;
    $site['reviews']['refreshedAt'] = $row['fetched_at'];

    data_write('site', $site, 'Panel: aktualizacja oceny i liczby opinii Google');
    audit('reviews_apply', 'site.json', ['old' => [$oldRating, $oldCount], 'new' => [(float) $row['rating'], $count]]);

    return [
        'ok' => true,
        'old' => ['rating' => $oldRating, 'count' => $oldCount],
        'new' => ['rating' => (float) $row['rating'], 'count' => $count],
    ];
}

function reviews_public_link(): string
{
    $site = data_read('site');
    return (string) ($site['reviews']['url'] ?? '');
}
