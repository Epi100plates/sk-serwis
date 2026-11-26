<?php
/**
 * POST /api/service-sync.php - synchronizacja statusu zlecenia z aplikacji
 * serwisowej (serwis.mati-it.pl) do zgłoszenia w CMS (sk.mati-it.pl/panel).
 *
 * Aplikacja warsztatu jest źródłem prawdy o zleceniu; CMS odpowiada za publiczną
 * stronę statusu, token i kolejkę SMS. Łącznikiem jest podpisane HMAC-em żądanie
 * HTTPS - bez sesji panelu i bez danych logowania pracownika.
 *
 * Żądanie:
 *   Content-Type: application/json
 *   X-SK-Timestamp: <unix>
 *   X-SK-Signature: base64url(HMAC-SHA256(secret, timestamp . "\n" . rawBody))
 *   {"reference":"SRV-2026-000123","phone":"600100200","device":"...","issue":"...",
 *    "status":"pending","note":"...","express":true,"event":"accepted",
 *    "planned_at":"2026-11-28 15:00","accepted_at":"2026-11-26 10:00"}
 *
 * Odpowiedź: {"ok":true,"url":"...","number":"...","status":"new","sms_queued":["client_accepted"]}
 * Błędy (JSON, bez ujawniania konfiguracji): 400/401/429/503.
 *
 * Do logów nie trafiają telefon, treść usterki ani token z linku - tylko numer
 * zlecenia, status i liczba zakolejkowanych wiadomości.
 *
 * Kontrakt i mapowanie statusów: docs/status-klienta.md, sekcja 11.
 */

require __DIR__ . '/_init.php';

/** Maksymalny rozmiar ciała żądania (ładunek to kilkaset bajtów). */
const SERVICE_SYNC_MAX_BODY = 20000;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
if ($method !== 'POST') {
    header('Allow: POST');
    service_sync_fail('method_not_allowed', 405);
}

// Brak sekretu = integracja wyłączona. Komunikat jest jasny, ale nie zdradza,
// czego brakuje w konfiguracji (żadnych nazw kluczy ani ścieżek plików).
$secret = tickets_sync_secret();
if ($secret === '') {
    tickets_log('sync: odrzucono - integracja nieskonfigurowana (brak sekretu)');
    service_sync_fail('integration_disabled', 503);
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    service_sync_fail('invalid_content_type', 400);
}

$declaredLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$raw = (string) file_get_contents('php://input');
if ($declaredLength > SERVICE_SYNC_MAX_BODY || strlen($raw) > SERVICE_SYNC_MAX_BODY) {
    tickets_log('sync: odrzucono - zbyt duże żądanie');
    service_sync_fail('payload_too_large', 400);
}

// Znacznik czasu i podpis: podpis obejmuje timestamp i całe ciało żądania,
// więc podmiana któregokolwiek z nich unieważnia żądanie (ochrona przed replay).
$timestamp = trim((string) ($_SERVER['HTTP_X_SK_TIMESTAMP'] ?? ''));
$signature = trim((string) ($_SERVER['HTTP_X_SK_SIGNATURE'] ?? ''));

if (!tickets_sync_timestamp_ok($timestamp)) {
    tickets_log('sync: odrzucono - znacznik czasu poza oknem ±' . TICKET_SYNC_CLOCK_SKEW . ' s');
    service_sync_fail('stale_timestamp', 401);
}

// Limit tempa liczy wszystkie żądania z poprawnym znacznikiem czasu (także te
// z złym podpisem), żeby ograniczyć zgadywanie sekretu.
if (!tickets_sync_rate_ok()) {
    tickets_log('sync: odrzucono - limit tempa');
    header('Retry-After: 60');
    service_sync_fail('rate_limited', 429);
}

if (!tickets_sync_signature_ok($secret, $timestamp, $raw, $signature)) {
    tickets_log('sync: odrzucono - nieprawidłowy podpis');
    service_sync_fail('invalid_signature', 401);
}

$payload = json_decode($raw, true, 8);
if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
    tickets_log('sync: odrzucono - nieczytelny JSON');
    service_sync_fail('invalid_json', 400);
}

$result = tickets_sync_external($payload);
if (empty($result['ok'])) {
    $error = (string) ($result['error'] ?? 'invalid_payload');
    if ($error === 'storage_error' || $error === 'conflict') {
        tickets_log('sync: błąd zapisu (' . $error . ')');
        service_sync_fail('service_unavailable', 503);
    }
    tickets_log('sync: odrzucono ładunek (' . $error . ')');
    service_sync_fail($error, 400);
}

tickets_log('sync: zlecenie ' . (string) $result['number'] . ' -> ' . (string) $result['status']
    . (!empty($result['created']) ? ' (nowe zgłoszenie)' : ' (aktualizacja)')
    . ', SMS w kolejce: ' . count((array) ($result['sms_queued'] ?? [])));

service_sync_json([
    'ok' => true,
    'url' => (string) ($result['url'] ?? ''),
    'number' => (string) ($result['number'] ?? ''),
    'status' => (string) ($result['status'] ?? ''),
    'sms_queued' => array_values((array) ($result['sms_queued'] ?? [])),
], 200);

/**
 * Odpowiedź błędu w JSON. Kody: 400 (złe dane), 401 (podpis/czas), 405 (metoda),
 * 429 (limit tempa), 503 (integracja wyłączona albo błąd zapisu).
 */
function service_sync_fail(string $error, int $status): void
{
    $data = ['ok' => false, 'error' => $error];
    if ($error === 'integration_disabled') {
        $data['message'] = 'Integracja z aplikacją serwisową nie jest włączona w konfiguracji panelu.';
    }
    service_sync_json($data, $status);
}

/** Odpowiedź JSON bez cache i bez sniffowania typu treści. */
function service_sync_json(array $data, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
