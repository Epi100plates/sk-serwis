<?php
/**
 * Publiczny endpoint pomiaru (sendBeacon). Bez CSRF — chroniony Origin,
 * limitem tempa i walidacją danych. Nie przechowuje adresów IP w postaci jawnej.
 */

require __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$siteHost = (string) parse_url((string) cfg('site_url'), PHP_URL_HOST);
if ($origin !== '') {
    $originHost = (string) parse_url($origin, PHP_URL_HOST);
    if ($originHost !== $siteHost) {
        http_response_code(403);
        exit;
    }
} else {
    $refererHost = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
    if ($refererHost !== '' && $refererHost !== $siteHost) {
        http_response_code(403);
        exit;
    }
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 8192) {
    http_response_code(204);
    exit;
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(204);
    exit;
}

// Zgoda jest warunkiem pomiaru — klient wysyła consent: true tylko po zgodzie.
if (empty($input['consent'])) {
    http_response_code(204);
    exit;
}

try {
    $type = (string) ($input['type'] ?? 'event');
    if ($type === 'pageview') {
        analytics_record_pageview($input);
    } else {
        analytics_record_event($input);
    }
} catch (Throwable $e) {
    log_panel('analytics endpoint: ' . $e->getMessage());
}

http_response_code(204);
