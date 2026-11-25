<?php
/**
 * GET /api/form-token - token formularza (double-submit cookie + time-trap).
 *
 * Zwraca JSON {token, ts} i ustawia cookie sk_form_csrf z hashem tokenu
 * (HttpOnly, Secure gdy HTTPS, SameSite=Lax, ścieżka /, ważność 2 h).
 * Token zawiera czas wydania - serwer odrzuca formularze wysłane wcześniej
 * niż po 3 s i później niż po 2 h.
 */

require __DIR__ . '/_init.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    public_json(['ok' => false, 'errors' => ['form' => 'Niedozwolona metoda.']], 405);
}

$ts = time();
$token = $ts . '.' . bin2hex(random_bytes(16));
$cookieValue = hash('sha256', $token);

setcookie('sk_form_csrf', $cookieValue, [
    'expires' => $ts + 7200,
    'path' => '/',
    'secure' => public_is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);

public_json(['token' => $token, 'ts' => $ts], 200);
