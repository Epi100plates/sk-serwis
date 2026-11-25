<?php
/**
 * POST /api/zgloszenie - przyjęcie zgłoszenia serwisowego z formularza.
 *
 * Zabezpieczenia: Origin/Referer, token double-submit + time-trap, honeypot,
 * filtr botów, limit 3 zgłoszeń / 10 min / IP, limity rozmiaru i prepared statements.
 * Odpowiedź: JSON dla JS, a dla wysyłki natywnej 303 na /dziekujemy/.
 * Do logów nie trafiają dane osobowe (telefon, imię, treść usterki).
 */

require __DIR__ . '/_init.php';

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
if ($method !== 'POST') {
    header('Allow: POST');
    if (public_wants_json()) {
        public_json(['ok' => false, 'errors' => ['form' => 'Niedozwolona metoda.']], 405);
    }
    form_error_page('Niedozwolona metoda', 'Formularz przyjmuje wyłącznie zgłoszenia wysłane metodą POST.', 405);
}

$json = public_wants_json();

try {
    // Maksymalny rozmiar żądania - formularz jest mały.
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 20000) {
        tickets_log('formularz: odrzucono - zbyt duże żądanie');
        form_fail($json, ['form' => 'Zgłoszenie jest zbyt duże.'], 413);
    }

    // Wyłącznie żądania z naszej strony (ochrona przed CSRF i obcymi formularzami).
    if (!public_same_origin()) {
        tickets_log('formularz: odrzucono - niezgodny Origin/Referer');
        form_fail($json, ['form' => 'Żądanie zostało odrzucone.'], 403);
    }

    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 20000) {
        tickets_log('formularz: odrzucono - zbyt duże ciało żądania');
        form_fail($json, ['form' => 'Zgłoszenie jest zbyt duże.'], 413);
    }

    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType === 'application/json') {
        $decoded = json_decode($raw, true);
        $input = is_array($decoded) ? $decoded : null;
    } else {
        $input = $_POST;
    }
    if (!is_array($input)) {
        tickets_log('formularz: odrzucono - nieczytelne dane');
        form_fail($json, ['form' => 'Nie udało się odczytać danych formularza.'], 400);
    }

    // Filtr botów - po nagłówku User-Agent, bez ujawniania szczegółów.
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (tickets_is_bot($userAgent)) {
        tickets_log('formularz: odrzucono - filtr botów');
        form_fail($json, ['form' => 'Nie udało się przetworzyć zgłoszenia.'], 403);
    }

    // Honeypot: pole ukryte przed użytkownikiem, wypełniane wyłącznie przez boty.
    foreach (['bot-field', 'website'] as $honeypot) {
        if (trim((string) ($input[$honeypot] ?? '')) !== '') {
            tickets_log('formularz: odrzucono - honeypot');
            form_fail($json, ['form' => 'Nie udało się przetworzyć zgłoszenia.'], 422);
        }
    }

    // Token formularza (double-submit cookie) + time-trap.
    $token = trim((string) ($input['token'] ?? ''));
    if ($token !== '') {
        $tsInput = trim((string) ($input['ts'] ?? ''));
        if (!form_token_valid($token, $tsInput)) {
            tickets_log('formularz: odrzucono - nieprawidłowy lub wygasły token');
            form_fail($json, ['form' => 'Sesja formularza wygasła - odśwież stronę i wyślij zgłoszenie ponownie.'], 422);
        }
    }

    // Limit tempa: 3 zgłoszenia / 10 minut / IP.
    if (!tickets_rate_limit_ok(3, 10)) {
        header('Retry-After: 600');
        form_fail($json, ['form' => 'Wysłano już kilka zgłoszeń z tego adresu. Odczekaj kilka minut albo zadzwoń: 500 613 500.'], 429);
    }

    $data = [
        'name' => (string) ($input['name'] ?? ($input['imie'] ?? '')),
        'phone' => (string) ($input['phone'] ?? ($input['telefon'] ?? '')),
        'device' => (string) ($input['device'] ?? ($input['sprzet'] ?? '')),
        'problem' => (string) ($input['problem'] ?? ($input['usterka'] ?? '')),
        'consent' => $input['consent'] ?? ($input['zgoda'] ?? false),
        'source_page' => (string) ($input['source_page'] ?? ''),
    ];

    $clientToken = trim((string) ($input['client_token'] ?? ''));
    $result = tickets_create($data, $clientToken);

    if (empty($result['ok'])) {
        form_fail($json, $result['errors'] ?? ['form' => 'Popraw zaznaczone pola i wyślij ponownie.'], 422);
    }

    if ($json) {
        $number = (string) ($result['number'] ?? '');
        public_json([
            'ok' => true,
            'number' => $number,
            'duplicate' => !empty($result['duplicate']),
            'message' => 'Dziękujemy. Zgłoszenie zostało przyjęte, jego numer to ' . $number
                . '. Skontaktujemy się w godzinach pracy serwisu. Jeśli sprawa jest pilna - zadzwoń: 500 613 500.',
        ], 200);
    }

    // Wysyłka bez JavaScriptu: przekierowanie bez danych osobowych w adresie.
    header('Location: /dziekujemy/', true, 303);
    exit;
} catch (Throwable $e) {
    tickets_log('formularz: błąd wewnętrzny (' . get_class($e) . ')');
    form_fail($json, ['form' => 'Serwis chwilowo nie może przyjąć zgłoszenia. Zadzwoń: 500 613 500.'], 500);
}

/**
 * Sprawdza token formularza: format, czas wydania, zgodność z ciasteczkiem.
 */
function form_token_valid(string $token, string $tsInput): bool
{
    if (!preg_match('/^(\d{10})\.([a-f0-9]{32})$/', $token, $m)) {
        return false;
    }
    $issued = (int) $m[1];
    if ($tsInput !== '' && (string) $issued !== $tsInput) {
        return false;
    }
    $now = time();
    if ($issued > $now + 300) {
        return false;
    }
    if ($now - $issued < 3) {
        return false;
    }
    if ($now - $issued > 7200) {
        return false;
    }
    $cookie = (string) ($_COOKIE['sk_form_csrf'] ?? '');
    if ($cookie === '') {
        return false;
    }
    return hash_equals(hash('sha256', $token), $cookie);
}

/**
 * Odpowiedź błędu: JSON dla JS, czytelna strona HTML dla wysyłki natywnej.
 */
function form_fail(bool $json, array $errors, int $status): void
{
    if ($json) {
        public_json(['ok' => false, 'errors' => $errors], $status);
    }
    $message = (string) reset($errors);
    form_error_page('Nie udało się wysłać zgłoszenia', $message, $status);
}

/**
 * Minimalna strona błędu dla formularza bez JavaScriptu (bez danych osobowych).
 */
function form_error_page(string $title, string $message, int $status): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . $safeTitle . ' | Studio-Komp</title>'
        . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f6f5;color:#242a30;margin:0;padding:48px 20px}'
        . 'main{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e4e0;border-radius:8px;padding:28px}'
        . 'h1{font-size:24px;letter-spacing:-0.03em;margin:0 0 12px}p{line-height:1.6;margin:0 0 16px}'
        . 'a{color:#242a30;font-weight:600}</style></head><body><main>'
        . '<h1>' . $safeTitle . '</h1><p>' . $safeMessage . '</p>'
        . '<p><a href="/kontakt/">Wróć do formularza kontaktowego</a></p>'
        . '<p>Pilna sprawa? Zadzwoń: <a href="tel:+48500613500">500 613 500</a>.</p>'
        . '</main></body></html>';
    exit;
}
