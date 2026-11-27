<?php
/**
 * Przekazywanie zgłoszeń z formularza do aplikacji warsztatu (serwis.mati-it.pl).
 *
 * CMS jest miejscem, w którym powstaje zgłoszenie (formularz publiczny), ale
 * praca nad nim dzieje się w aplikacji serwisowej. Ten plik wysyła podpisane
 * HMAC-em żądanie POST /api/intake-tickets i zapisuje wynik w wierszu
 * zgłoszenia. Nieudana wysyłka nie blokuje formularza - worker próbuje ponownie
 * co minutę, więc żadne zgłoszenie nie ginie przez chwilowy brak sieci.
 *
 * Bezpieczeństwo: sekret poza repozytorium (config.php), podpis obejmuje
 * znacznik czasu i całe ciało żądania, do logów nie trafiają dane osobowe.
 */

/** Maksymalny rozmiar odpowiedzi, którą analizujemy (payload jest mały). */
const SERVICE_INTAKE_MAX_RESPONSE = 20000;

/** Ile razy worker ponawia wysyłkę, zanim zostawi zgłoszenie w panelu. */
const SERVICE_INTAKE_MAX_ATTEMPTS = 8;

/** Zgłoszenia starsze niż tyle dni nie są dosyłane po włączeniu integracji. */
const SERVICE_INTAKE_BACKFILL_DAYS = 30;

function service_intake_secret(): string
{
    return trim((string) (function_exists('cfg') ? cfg('service_intake_secret', '') : ''));
}

function service_intake_url(): string
{
    $url = trim((string) (function_exists('cfg') ? cfg('service_intake_url', '') : ''));
    if ($url === '') {
        // Obie aplikacje stoją na tym samym VPS; ruch nie wychodzi przez Cloudflare.
        $url = 'http://127.0.0.1:8090/api/intake-tickets';
    }
    return $url;
}

function service_intake_enabled(): bool
{
    return service_intake_secret() !== '';
}

/**
 * Ładunek wysyłany do aplikacji warsztatu. Tylko pola potrzebne do przyjęcia
 * sprzętu - bez danych analitycznych i bez wewnętrznych notatek panelu.
 *
 * @param array<string, mixed> $ticket
 * @return array<string, string>
 */
function service_intake_payload(array $ticket): array
{
    return [
        'number' => (string) ($ticket['number'] ?? ''),
        'name' => (string) ($ticket['name'] ?? ''),
        'phone' => (string) ($ticket['phone'] ?? ''),
        'device' => (string) ($ticket['device'] ?? ''),
        'problem' => (string) ($ticket['problem'] ?? ''),
        'source_page' => (string) ($ticket['source_page'] ?? ''),
        'created_at' => (string) ($ticket['created_at'] ?? ''),
    ];
}

/**
 * Prosty klient HTTP. Osobny od SMS-owego, bo ma krótszy timeout: formularz
 * nie może czekać dziesięciu sekund na aplikację, która akurat nie odpowiada.
 *
 * @param list<string> $headers
 * @return array{ok: bool, status: int, body: string, error: string}
 */
function service_intake_http(string $url, string $body, array $headers, int $timeout = 4): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => 'Błąd połączenia: ' . mb_substr($error, 0, 160, 'UTF-8')];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $responseBody, 'error' => ''];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
    ]);
    $responseBody = @file_get_contents($url, false, $context);
    $status = 0;
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    if (!empty($responseHeaders) && preg_match('/\s(\d{3})\s/', (string) $responseHeaders[0], $m)) {
        $status = (int) $m[1];
    }
    if ($responseBody === false) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'error' => 'Błąd połączenia.'];
    }

    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $responseBody, 'error' => ''];
}

/** Bezpieczny (bez danych osobowych) opis błędu do panelu. */
function service_intake_error_label(array $result): string
{
    $status = (int) ($result['status'] ?? 0);
    if ($status === 503) {
        return 'Aplikacja warsztatu ma wyłączoną integrację (brak sekretu).';
    }
    if ($status === 401) {
        return 'Aplikacja warsztatu odrzuciła podpis (sprawdź sekret).';
    }
    if ($status === 429) {
        return 'Aplikacja warsztatu chwilowo ograniczyła liczbę żądań.';
    }
    if ($status > 0) {
        return 'Aplikacja warsztatu odpowiedziała HTTP ' . $status . '.';
    }
    return trim((string) ($result['error'] ?? '')) !== ''
        ? 'Brak połączenia z aplikacją warsztatu.'
        : 'Nieznany błąd wysyłki.';
}

/**
 * Wysyła jedno zgłoszenie i zapisuje wynik w bazie.
 *
 * @return array{ok: bool, status: string, error: string, attempts: int}
 */
function service_intake_forward(int $ticketId, bool $force = false): array
{
    $ticket = tickets_by_id($ticketId);
    if (!$ticket) {
        return ['ok' => false, 'status' => 'missing', 'error' => 'Zgłoszenie nie istnieje.', 'attempts' => 0];
    }

    // Zlecenia z aplikacji warsztatu (external_ref = SRV-...) są już w panelu
    // serwisowym. Do skrzynki Zgłoszenia trafiają wyłącznie formularze z www.
    if (trim((string) ($ticket['external_ref'] ?? '')) !== '') {
        return ['ok' => false, 'status' => 'skipped', 'error' => '', 'attempts' => 0];
    }

    $pdo = db();
    $current = $pdo->prepare('SELECT service_intake_at, service_intake_attempts FROM tickets WHERE id = ?');
    $current->execute([$ticketId]);
    $row = $current->fetch() ?: [];
    $attempts = (int) ($row['service_intake_attempts'] ?? 0);

    if (!$force && (string) ($row['service_intake_at'] ?? '') !== '') {
        return ['ok' => true, 'status' => 'already', 'error' => '', 'attempts' => $attempts];
    }

    if (!service_intake_enabled()) {
        // Integracja wyłączona: nie zapisujemy błędu, żeby panel nie straszył
        // na zapas. Po ustawieniu sekretu worker dosyła świeże zgłoszenia.
        return ['ok' => false, 'status' => 'disabled', 'error' => '', 'attempts' => $attempts];
    }

    $body = json_encode(service_intake_payload($ticket), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body) || $body === '') {
        return ['ok' => false, 'status' => 'error', 'error' => 'Nie udało się zbudować żądania.', 'attempts' => $attempts];
    }

    $timestamp = (string) time();
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-SK-Timestamp: ' . $timestamp,
        'X-SK-Signature: ' . tickets_sync_signature(service_intake_secret(), $timestamp, $body),
    ];

    $result = service_intake_http(service_intake_url(), $body, $headers);
    $attempts++;
    $now = now();

    if ($result['ok']) {
        $decoded = json_decode($result['body'], true);
        $status = is_array($decoded) ? (string) ($decoded['status'] ?? 'created') : 'created';
        $pdo->prepare('UPDATE tickets SET service_intake_at = ?, service_intake_status = ?, service_intake_error = ?, service_intake_attempts = ? WHERE id = ?')
            ->execute([$now, mb_substr($status, 0, 32), '', $attempts, $ticketId]);
        tickets_log('intake: przekazano zgłoszenie ' . (string) ($ticket['number'] ?? ('#' . $ticketId)) . ' (' . $status . ')');

        return ['ok' => true, 'status' => $status, 'error' => '', 'attempts' => $attempts];
    }

    $error = service_intake_error_label($result);
    $pdo->prepare('UPDATE tickets SET service_intake_error = ?, service_intake_attempts = ? WHERE id = ?')
        ->execute([mb_substr($error, 0, 300), $attempts, $ticketId]);
    tickets_log('intake: nie przekazano zgłoszenia ' . (string) ($ticket['number'] ?? ('#' . $ticketId)) . ' - ' . $error);

    return ['ok' => false, 'status' => 'error', 'error' => $error, 'attempts' => $attempts];
}

/**
 * Dosyłanie zaległych zgłoszeń (worker co minutę). Bierze tylko świeże wpisy
 * (ostatnie 30 dni), żeby po włączeniu integracji nie wysłać naraz całej
 * historii. Nie kończy się błędem, gdy aplikacja warsztatu nie odpowiada.
 *
 * @return array{sent: int, failed: int, skipped: int}
 */
function service_intake_process(int $limit = 20): array
{
    $summary = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
    if (!service_intake_enabled()) {
        $summary['skipped'] = 1;
        return $summary;
    }

    $since = (new DateTimeImmutable('-' . SERVICE_INTAKE_BACKFILL_DAYS . ' days'))->format('Y-m-d H:i:s');
    $st = db()->prepare(
        "SELECT id FROM tickets
         WHERE COALESCE(service_intake_at, '') = ''
           AND archived = 0
           AND (external_ref IS NULL OR external_ref = '')
           AND service_intake_attempts < ?
           AND created_at >= ?
         ORDER BY id ASC
         LIMIT " . max(1, min(100, $limit))
    );
    $st->execute([SERVICE_INTAKE_MAX_ATTEMPTS, $since]);

    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $result = service_intake_forward((int) $id);
        if ($result['ok']) {
            $summary['sent']++;
        } elseif ($result['status'] === 'disabled') {
            $summary['skipped']++;
        } else {
            $summary['failed']++;
        }
    }

    return $summary;
}
