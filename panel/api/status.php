<?php
/**
 * GET /status/<token>/ - publiczna strona statusu naprawy dla klienta.
 *
 * Link trafia do klienta SMS-em (szablony `client_accepted` i `client_ready`)
 * i jest sekretem: token jest podpisany kluczem serwera (HMAC-SHA256), więc
 * wyciek samej bazy nie wystarcza do jego policzenia. Nowy token (panel)
 * unieważnia poprzedni link.
 *
 * Odpowiedzi:
 *   - HTML w stylu strony (panel/views/status.php),
 *   - JSON przy `?format=json` lub nagłówku Accept: application/json,
 *   - 404 dla nieprawidłowego/unieważnionego tokenu (bez ujawniania szczegółów),
 *   - 429 po przekroczeniu limitu tempa (30 żądań/min na hash IP).
 *
 * Strona nie zawiera danych osobowych: numer zgłoszenia, sprzęt, etapy, daty,
 * publiczna notatka serwisu i dane kontaktowe firmy.
 */

require __DIR__ . '/_init.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$isHead = $method === 'HEAD';
if ($method !== 'GET' && !$isHead) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false,"error":"method_not_allowed"}';
    exit;
}

header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; font-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");

$wantsJson = (string) ($_GET['format'] ?? '') === 'json' || public_wants_json();

if (!ticket_status_rate_ok()) {
    tickets_log('status: odrzucono - limit tempa');
    if ($wantsJson) {
        status_json(['ok' => false, 'error' => 'rate_limited'], 429, $isHead);
    }
    header('Retry-After: 60');
    status_fail_page(429, 'Zbyt wiele żądań', 'Odczekaj chwilę i odśwież stronę.');
}

$token = tickets_tracking_token_from_request();
$ticket = $token === '' ? null : tickets_find_by_tracking_token($token);
if ($ticket && (int) ($ticket['archived'] ?? 0) === 1) {
    $ticket = null;
}

if (!$ticket) {
    if ($wantsJson) {
        status_json(['ok' => false, 'error' => 'not_found'], 404, $isHead);
    }
    http_response_code(404);
    status_render_view(null, $isHead);
    exit;
}

$status = ticket_public_view($ticket);

if ($wantsJson) {
    status_json(['ok' => true, 'status' => $status], 200, $isHead);
}

http_response_code(200);
status_render_view($status, $isHead);

/**
 * Renderuje szablon strony statusu; gdy pliku brak (niepełne wdrożenie),
 * pokazuje minimalną stronę awaryjną zamiast błędu 500.
 */
function status_render_view(?array $status, bool $headOnly = false): void
{
    $view = SK_PANEL_ROOT . '/views/status.php';
    if (!is_file($view)) {
        status_fail_page(200, 'Status naprawy', 'Strona statusu jest chwilowo niedostępna. Zadzwoń: 500 613 500.');
    }
    header('Content-Type: text/html; charset=utf-8');
    if ($headOnly) {
        exit;
    }
    $status_refresh = 0;
    require $view;
}

/** Odpowiedź JSON (dla HEAD bez treści, żeby działał podgląd nagłówków). */
function status_json(array $data, int $code, bool $headOnly = false): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    if ($headOnly) {
        exit;
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Minimalna strona komunikatu (błąd lub brak szablonu) - bez danych klienta.
 */
function status_fail_page(int $status, string $title, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    // Bez stylów inline: arkusz /tracking/status.css, bo CSP blokuje inline style.
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<meta name="theme-color" content="#ffd400">'
        . '<title>' . $safeTitle . ' - Studio-Komp</title>'
        . '<link rel="stylesheet" href="/tracking/status.css?v=1"></head><body>'
        . '<header class="masthead"><div class="masthead__inner">'
        . '<span class="brand"><span class="brand__mark" aria-hidden="true"></span>Studio-Komp</span>'
        . '<span class="masthead__meta mono">Serwis laptopów · Wrocław</span>'
        . '</div></header>'
        . '<main class="page" id="main"><div class="empty">'
        . '<h1 class="empty__title">' . $safeTitle . '</h1>'
        . '<p class="empty__text">' . $safeMessage . '</p>'
        . '<p class="empty__text"><a class="phone" href="tel:+48500613500">Zadzwoń: 500 613 500</a></p>'
        . '<p class="empty__text mono">Studio-Komp · Kotlarska 14 · Wrocław</p>'
        . '</div></main>'
        . '<footer class="foot"><div class="foot__inner"><span class="foot__meta mono">Studio-Komp · Kotlarska 14 · Wrocław</span></div></footer>'
        . '</body></html>';
    exit;
}
