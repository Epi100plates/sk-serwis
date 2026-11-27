<?php
/**
 * Zgłoszenia serwisowe: walidacja, zapis w transakcji, numer zgłoszenia,
 * historia statusów, kolejka powiadomień (SMS/e-mail), limity tempa.
 * Plik nie zależy od sesji panelu - używany też przez publiczne API
 * (/api/zgloszenie) oraz przez worker cron.
 */

const TICKET_STATUSES = [
    'new' => 'Nowe',
    'read' => 'Odczytane',
    'in_progress' => 'W trakcie kontaktu',
    'done' => 'Zrealizowane',
    'closed' => 'Zamknięte',
    'cancelled' => 'Anulowane',
];

const TICKET_CONSENT_VERSION = '2026-11-25';

/** Maksymalna liczba prób dostarczenia powiadomienia. */
const TICKET_NOTIFY_MAX_ATTEMPTS = 5;

/** Backoff po nieudanej próbie (w minutach), indeks = numer próby - 1. */
const TICKET_NOTIFY_BACKOFF_MINUTES = [1, 5, 15, 60];

/** Domyślna treść SMS - bez opisu usterki i bez danych wrażliwych. */
const TICKET_SMS_TEMPLATE_DEFAULT = 'Studio-Komp: nowe zgloszenie nr {number}. {imie}, {sprzet}, tel. {telefon}. Szczegoly w panelu: {link}';

const TICKET_NOTIFY_CHANNELS = ['sms', 'email'];

/**
 * Etapy naprawy widoczne dla klienta. To jedyne miejsce mapowania statusów
 * z bazy (TICKET_STATUSES) na to, co widzi klient - wszystkie etapy pośrednie
 * pokazują się jako "W trakcie naprawy".
 */
const CLIENT_STAGES = [
    'accepted' => 'Przyjęty',
    'in_progress' => 'W trakcie naprawy',
    'ready' => 'Gotowy do wydania',
    'picked_up' => 'Odebrany',
    'cancelled' => 'Anulowane',
];

const CLIENT_STAGE_BY_STATUS = [
    'new' => 'accepted',
    'read' => 'accepted',
    'in_progress' => 'in_progress',
    'done' => 'ready',
    'closed' => 'picked_up',
    'cancelled' => 'cancelled',
];

/**
 * Mapowanie statusów aplikacji serwisowej (serwis.mati-it.pl) na statusy
 * zgłoszenia w CMS. Jedyne miejsce z tą listą - korzysta z niego endpoint
 * panel/api/service-sync.php i tickets_sync_external().
 *
 * uwaga: awaiting_parts nie ma osobnego statusu w CMS, bo klient i tak widzi
 * "W trakcie naprawy"; nieznany status jest odrzucany, nigdy mapowany
 * domyślnie, żeby literówka w aplikacji nie zmieniła stanu naprawy.
 */
const TICKET_SYNC_STATUS_MAP = [
    'pending' => 'new',
    'in_progress' => 'in_progress',
    'awaiting_parts' => 'in_progress',
    'ready_for_pickup' => 'done',
    'released' => 'closed',
    'cancelled' => 'cancelled',
];

function tickets_sync_status_map(): array
{
    return TICKET_SYNC_STATUS_MAP;
}

/** Domyślne treści SMS do klienta (edytowalne w panelu: Ustawienia -> Formularz). */
const TICKET_CLIENT_SMS_DEFAULTS = [
    'client_accepted' => 'Studio-Komp: przyjelismy sprzet ({sprzet}). Status naprawy: {link}',
    'client_ready' => 'Studio-Komp: {sprzet} jest gotowy do odbioru. Kotlarska 14, pon.-pt. 7:00-15:00. Status: {link}',
];

/** Limity publicznej strony statusu ( żądania / okno w sekundach / IP-hash ). */
const TICKET_STATUS_RATE_LIMIT = 30;
const TICKET_STATUS_RATE_WINDOW = 60;

/* ------------------------------------------------------------------ logi */

/**
 * Log techniczny zgłoszeń. Celowo bez imienia, telefonu i treści usterki.
 */
function tickets_log(string $message): void
{
    $dir = rtrim((string) cfg('data_dir'), '/') . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $message) . "\n";
    @file_put_contents($dir . '/tickets.log', $line, FILE_APPEND | LOCK_EX);
}

/* ------------------------------------------------------------- etykiety */

function ticket_status_label(string $status): string
{
    return TICKET_STATUSES[$status] ?? $status;
}

/** Status zgłoszenia -> etap widoczny na stronie klienta. */
function ticket_client_stage(string $status): string
{
    return CLIENT_STAGE_BY_STATUS[$status] ?? 'accepted';
}

function ticket_status_tone(string $status): string
{
    $map = [
        'new' => 'warn',
        'read' => 'info',
        'in_progress' => 'info',
        'done' => 'ok',
        'closed' => 'muted',
        'cancelled' => 'err',
    ];
    return $map[$status] ?? 'muted';
}

function ticket_notification_status_label(string $status): string
{
    $map = [
        'pending' => 'W kolejce',
        'sent' => 'Wysłane',
        'failed' => 'Błąd',
        'skipped' => 'Pominięte',
        'test' => 'Tryb testowy',
    ];
    return $map[$status] ?? $status;
}

function ticket_notification_status_tone(string $status): string
{
    $map = [
        'pending' => 'warn',
        'sent' => 'ok',
        'failed' => 'err',
        'skipped' => 'muted',
        'test' => 'info',
    ];
    return $map[$status] ?? 'muted';
}

/* ------------------------------------------------------------ normalizacja */

/**
 * Usuwa HTML, encje, znaki kontrolne i nadmiar białych znaków.
 * Dla $multiline zachowuje akapity (pojedyncze znaki nowej linii).
 */
function tickets_normalize_text(string $value, int $max = 0, bool $multiline = false): string
{
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = strip_tags($value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

    if ($multiline) {
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/ *\n */u', "\n", $value) ?? $value;
        $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;
        $value = trim($value);
    } else {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);
    }

    if ($max > 0) {
        $value = mb_substr($value, 0, $max, 'UTF-8');
    }
    return $value;
}

/**
 * Normalizuje polski numer telefonu do postaci +48XXXXXXXXX.
 * Zwraca pusty string, gdy numer jest niepoprawny.
 */
function tickets_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($digits, '0048')) {
        $digits = substr($digits, 4);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '48')) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 10 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    if (!preg_match('/^[1-9]\d{8}$/', $digits)) {
        return '';
    }
    return '+48' . $digits;
}

/** Numer krajowy do treści SMS: 600 613 500. */
function tickets_phone_national(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '48') && strlen($digits) === 11) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) !== 9) {
        return $phone;
    }
    return substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 3);
}

/** Numer do wyświetlenia w panelu: +48 600 613 500. */
function tickets_display_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '48') && strlen($digits) === 11) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) !== 9) {
        return $phone;
    }
    return '+48 ' . substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 3);
}

/** Zamiana polskich znaków na ASCII - SMS w GSM-7 jest tańszy i krótszy. */
function tickets_sms_ascii(string $value): string
{
    $map = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{00A0}" => ' ',
    ];
    return strtr($value, $map);
}

/* --------------------------------------------------------------- filtry */

function tickets_is_bot(string $userAgent): bool
{
    if ($userAgent === '') {
        return true;
    }
    return (bool) preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headless|python-requests|curl|wget|monitor|uptime|lighthouse|scrapy|httpclient/i', $userAgent);
}

/* ------------------------------------------------------------- walidacja */

/**
 * Walidacja i normalizacja zgłoszenia.
 * @return array{ok:bool,errors:array<string,string>,data:array<string,string>}
 */
function tickets_validate(array $input): array
{
    $errors = [];

    $name = tickets_normalize_text((string) ($input['name'] ?? ''));
    $nameLength = mb_strlen($name, 'UTF-8');
    if ($name === '') {
        $errors['imie'] = 'Podaj imię.';
    } elseif ($nameLength < 2) {
        $errors['imie'] = 'Imię jest za krótkie - wpisz co najmniej 2 znaki.';
    } elseif ($nameLength > 80) {
        $errors['imie'] = 'Imię może mieć maksymalnie 80 znaków.';
    }

    $phoneRaw = trim((string) ($input['phone'] ?? ''));
    $phone = tickets_normalize_phone($phoneRaw);
    if ($phoneRaw === '') {
        $errors['telefon'] = 'Podaj numer telefonu.';
    } elseif ($phone === '') {
        $errors['telefon'] = 'Podaj numer telefonu w formacie 9 cyfr (np. 600 000 000).';
    }

    $device = tickets_normalize_text((string) ($input['device'] ?? ''));
    if (mb_strlen($device, 'UTF-8') > 120) {
        $errors['sprzet'] = 'Nazwa sprzętu może mieć maksymalnie 120 znaków.';
    }

    $problem = tickets_normalize_text((string) ($input['problem'] ?? ''), 0, true);
    $problemLength = mb_strlen($problem, 'UTF-8');
    if ($problem === '') {
        $errors['usterka'] = 'Opisz, co się dzieje ze sprzętem.';
    } elseif ($problemLength < 10) {
        $errors['usterka'] = 'Opis usterki jest za krótki - napisz co najmniej 10 znaków.';
    } elseif ($problemLength > 2000) {
        $errors['usterka'] = 'Opis usterki może mieć maksymalnie 2000 znaków.';
    }

    $consent = $input['consent'] ?? false;
    $consentOk = in_array($consent, [true, 1, '1', 'on', 'true', 'yes'], true);
    if (!$consentOk) {
        $errors['zgoda'] = 'Zgoda na kontakt w sprawie zgłoszenia jest wymagana.';
    }

    $sourcePage = tickets_normalize_text((string) ($input['source_page'] ?? ''));
    if ($sourcePage !== '' && ($sourcePage[0] !== '/' || mb_strlen($sourcePage, 'UTF-8') > 160)) {
        $sourcePage = '';
    }

    $data = [
        'name' => $name,
        'phone' => $phone,
        'device' => $device,
        'problem' => $problem,
        'source_page' => $sourcePage,
    ];

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'data' => $data,
    ];
}

/* ------------------------------------------------------------- limit tempa */

/**
 * Sprawdza limit i zapisuje próbę. Limit: 3 zgłoszenia / 10 minut / IP-hash.
 * Zliczane są wszystkie próby wysłania formularza (także odrzucone przez walidację).
 */
function tickets_rate_limit_ok(int $limit = 3, int $windowMinutes = 10): bool
{
    $pdo = db();
    $ipHash = security_ip_hash();
    $since = (new DateTimeImmutable("-{$windowMinutes} minutes"))->format('Y-m-d H:i:s');

    $st = $pdo->prepare('SELECT COUNT(*) FROM form_rate WHERE ip_hash = ? AND created_at >= ?');
    $st->execute([$ipHash, $since]);
    if ((int) $st->fetchColumn() >= $limit) {
        tickets_log('rate limit: odrzucono próbę formularza (limit ' . $limit . '/' . $windowMinutes . ' min)');
        return false;
    }

    $pdo->prepare('INSERT INTO form_rate (ip_hash, created_at) VALUES (?, ?)')->execute([$ipHash, now()]);
    return true;
}

/** Usuwa stare wpisy limitów tempa (wywoływane przez worker). */
function tickets_cleanup_rate(): int
{
    $since = (new DateTimeImmutable('-2 days'))->format('Y-m-d H:i:s');
    $removed = 0;
    foreach (['form_rate', 'sync_rate'] as $table) {
        $st = db()->prepare('DELETE FROM ' . $table . ' WHERE created_at < ?');
        $st->execute([$since]);
        $removed += $st->rowCount();
    }
    return $removed;
}

/* ---------------------------------------------------------- zapis zgłoszenia */

function tickets_next_number(PDO $pdo): string
{
    $year = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y');

    // Licznik roczny w tabeli ticket_counters: numery nie wracają do obiegu po
    // usunięciu zgłoszenia. Przy pierwszym użyciu roku licznik startuje od
    // najwyższego numeru, jaki już istnieje w bazie.
    $pdo->prepare(
        'INSERT OR IGNORE INTO ticket_counters (year, last)
         VALUES (?, COALESCE((SELECT MAX(CAST(SUBSTR(number, 9) AS INTEGER)) FROM tickets WHERE SUBSTR(number, 4, 4) = ?), 0))'
    )->execute([$year, $year]);
    $pdo->prepare('UPDATE ticket_counters SET last = last + 1 WHERE year = ?')->execute([$year]);

    $st = $pdo->prepare('SELECT last FROM ticket_counters WHERE year = ? LIMIT 1');
    $st->execute([$year]);
    $next = (int) $st->fetchColumn();

    return sprintf('SK-%s-%06d', $year, $next);
}

function tickets_generate_client_token(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Tworzy zgłoszenie w transakcji: ticket + historia + kolejka powiadomień.
 * Idempotencja: ten sam client_token zwraca istniejące zgłoszenie (bez duplikatu).
 *
 * @return array{ok:bool,errors:array<string,string>,id?:int,number?:string,duplicate?:bool}
 */
function tickets_create(array $data, string $clientToken): array
{
    $validated = tickets_validate($data);
    if (!$validated['ok']) {
        return ['ok' => false, 'errors' => $validated['errors']];
    }

    $clientToken = trim($clientToken);
    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $clientToken)) {
        $clientToken = tickets_generate_client_token();
    }

    $clean = $validated['data'];
    $consentAt = now();
    $userAgent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300, 'UTF-8');
    $ipHash = function_exists('security_ip_hash') ? security_ip_hash() : '';

    $pdo = db();

    // Idempotencja: powtórzone żądanie z tym samym client_token nie tworzy nowego wpisu.
    $existing = tickets_find_by_token($clientToken);
    if ($existing) {
        tickets_log('formularz: powtórzone żądanie dla zgłoszenia ' . $existing['number']);
        return [
            'ok' => true,
            'id' => (int) $existing['id'],
            'number' => (string) $existing['number'],
            'duplicate' => true,
        ];
    }

    $attempts = 0;
    while (true) {
        $attempts++;
        try {
            $pdo->beginTransaction();
            $number = tickets_next_number($pdo);
            $now = now();

            $st = $pdo->prepare(
                'INSERT INTO tickets (number, created_at, updated_at, name, phone, device, problem, source_page, ip_hash, user_agent, status, consent_at, consent_version, client_token)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $st->execute([
                $number,
                $now,
                $now,
                $clean['name'],
                $clean['phone'],
                $clean['device'],
                $clean['problem'],
                $clean['source_page'],
                $ipHash,
                $userAgent,
                'new',
                $consentAt,
                TICKET_CONSENT_VERSION,
                $clientToken,
            ]);
            $ticketId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO ticket_history (ticket_id, status, note, username, created_at) VALUES (?,?,?,?,?)')
                ->execute([$ticketId, 'new', 'Zgłoszenie przyjęte z formularza.', 'system', $now]);

            $ticket = $clean + ['id' => $ticketId, 'number' => $number];
            tickets_enqueue_notifications($pdo, $ticketId, $ticket);
            // Link do śledzenia naprawy trafia do klienta od razu po przyjęciu sprzętu.
            tickets_enqueue_client_sms($pdo, $ticketId, $ticket, 'client_accepted');

            $pdo->commit();
            tickets_log('formularz: utworzono zgłoszenie ' . $number);

            // Przekazanie do aplikacji warsztatu po commicie: formularz nie może
            // czekać na drugą aplikację, a nieudana wysyłka jest ponawiana przez
            // worker (kolumny service_intake_* w tabeli tickets).
            if (function_exists('service_intake_forward')) {
                try {
                    service_intake_forward($ticketId);
                } catch (Throwable $e) {
                    tickets_log('intake: wyjątek przy przekazywaniu zgłoszenia ' . $number . ' (' . get_class($e) . ')');
                }
            }

            return ['ok' => true, 'id' => $ticketId, 'number' => $number, 'duplicate' => false];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Kolizja numeru (równoległe zgłoszenia) - ponów z nowym numerem.
            if ($attempts < 5 && str_contains($e->getMessage(), 'UNIQUE')) {
                usleep(random_int(20000, 80000));
                continue;
            }
            tickets_log('formularz: błąd zapisu zgłoszenia (' . get_class($e) . ')');
            throw $e;
        }
    }
}

function tickets_find_by_token(string $clientToken): ?array
{
    if ($clientToken === '') {
        return null;
    }
    $st = db()->prepare('SELECT id, number, created_at FROM tickets WHERE client_token = ? LIMIT 1');
    $st->execute([$clientToken]);
    $row = $st->fetch();
    return $row ?: null;
}

function tickets_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM tickets WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function tickets_history(int $ticketId): array
{
    $st = db()->prepare('SELECT * FROM ticket_history WHERE ticket_id = ? ORDER BY id ASC');
    $st->execute([$ticketId]);
    return $st->fetchAll();
}

function tickets_notifications(int $ticketId): array
{
    $st = db()->prepare('SELECT * FROM notifications WHERE ticket_id = ? ORDER BY id ASC');
    $st->execute([$ticketId]);
    return $st->fetchAll();
}

/** Liczniki zgłoszeń wg statusów (bez zarchiwizowanych). */
function tickets_counts(): array
{
    $counts = array_fill_keys(array_keys(TICKET_STATUSES), 0);
    $rows = db()->query('SELECT status, COUNT(*) AS c FROM tickets WHERE archived = 0 GROUP BY status')->fetchAll();
    foreach ($rows as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int) $row['c'];
        }
    }
    $counts['all'] = array_sum($counts);
    return $counts;
}

/* --------------------------------------------------- kolejka powiadomień */

/**
 * Kolejkuje powiadomienia o nowym zgłoszeniu (SMS do serwisu + opcjonalnie e-mail).
 * UNIQUE(ticket_id, channel, template) gwarantuje najwyżej jedną wiadomość danego
 * rodzaju na zgłoszenie - ponowienie formularza nie tworzy duplikatów.
 */
function tickets_enqueue_notifications(PDO $pdo, int $ticketId, array $ticket): void
{
    $now = now();
    $smsRecipient = trim(setting_get('sms_recipient'));
    $smsProvider = setting_get('sms_provider', 'smsapi');
    if (!in_array($smsProvider, ['smsapi', 'serwersms'], true)) {
        $smsProvider = 'smsapi';
    }
    $mailRecipient = trim(setting_get('smtp_to'));

    $smsPayload = json_encode([
        'template' => 'new_ticket',
        'number' => (string) ($ticket['number'] ?? ''),
        'message' => tickets_sms_masked_preview($ticket),
    ], JSON_UNESCAPED_UNICODE);

    $mailPayload = json_encode([
        'template' => 'new_ticket',
        'number' => (string) ($ticket['number'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);

    $st = $pdo->prepare(
        'INSERT OR IGNORE INTO notifications (ticket_id, channel, provider, recipient, template, payload, status, attempts, created_at, updated_at, next_attempt_at)
         VALUES (?,?,?,?,?,?,?,0,?,?,?)'
    );
    $st->execute([$ticketId, 'sms', $smsProvider, $smsRecipient, 'new_ticket', $smsPayload, 'pending', $now, $now, $now]);
    $st->execute([$ticketId, 'email', 'smtp', $mailRecipient, 'new_ticket', $mailPayload, 'pending', $now, $now, $now]);
}

/** Podgląd SMS z zamaskowanymi danymi - trafia wyłącznie do kolumny payload. */
function tickets_sms_masked_preview(array $ticket): string
{
    $name = (string) ($ticket['name'] ?? '');
    $first = $name === '' ? '?' : mb_substr($name, 0, 1, 'UTF-8') . '***';
    $phone = (string) ($ticket['phone'] ?? '');
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    $last = $digits === '' ? '***' : '***' . substr($digits, -3);
    $number = (string) ($ticket['number'] ?? '');
    return mb_substr('SMS nr ' . $number . ' do ' . $first . ', tel. ' . $last, 0, 120, 'UTF-8');
}

/** Szablon SMS z ustawień (pusty = domyślny). */
function tickets_sms_template(): string
{
    $template = trim(setting_get('sms_template'));
    if ($template === '') {
        $template = TICKET_SMS_TEMPLATE_DEFAULT;
    }
    return $template;
}

/** Adres panelu ze zgłoszeniami - używany w treści SMS. */
function tickets_panel_tickets_url(): string
{
    $base = rtrim((string) cfg('site_url'), '/');
    return $base . rtrim((string) cfg('panel_path', '/panel'), '/') . '/zgloszenia';
}

/**
 * Renderuje treść SMS na podstawie szablonu i danych zgłoszenia.
 * Treść nie zawiera opisu usterki.
 */
function tickets_sms_message(array $ticket, ?string $template = null): string
{
    $template = $template ?? tickets_sms_template();
    $device = trim((string) ($ticket['device'] ?? ''));
    $vars = [
        '{number}' => (string) ($ticket['number'] ?? ''),
        '{imie}' => (string) ($ticket['name'] ?? ''),
        '{sprzet}' => $device !== '' ? $device : 'sprzet',
        '{telefon}' => tickets_phone_national((string) ($ticket['phone'] ?? '')),
        '{link}' => tickets_panel_tickets_url(),
    ];
    $message = strtr($template, $vars);
    $message = tickets_sms_ascii($message);
    $message = preg_replace('/\s+/u', ' ', $message) ?? $message;
    return mb_substr(trim($message), 0, 459, 'UTF-8');
}

/* --------------------------------------- śledzenie naprawy (dla klienta) */

/** Klucz HMAC tokenów śledzenia - plik w data_dir, poza repozytorium. */
function tickets_tracking_key(): string
{
    static $key = null;
    if (is_string($key)) {
        return $key;
    }
    $configured = trim((string) (function_exists('cfg') ? cfg('tracking_secret', '') : ''));
    if ($configured !== '') {
        return $key = $configured;
    }
    $file = rtrim((string) cfg('data_dir'), '/') . '/tracking.key';
    if (!is_file($file)) {
        @mkdir(dirname($file), 0770, true);
        @file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($file, 0600);
    }
    $stored = trim((string) @file_get_contents($file));
    if ($stored === '') {
        throw new RuntimeException('Brak klucza tokenów śledzenia naprawy.');
    }
    return $key = $stored;
}

/** Podpis tokenu: HMAC-SHA256 (base64url) z id zgłoszenia i wersji tokenu. */
function tickets_tracking_mac(int $ticketId, int $version): string
{
    $raw = hash_hmac('sha256', 'ticket|' . $ticketId . '|' . $version, tickets_tracking_key(), true);
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * Podpis w nowym, krótkim formacie: pierwsze 16 bajtów HMAC-SHA256 w base64url
 * (22 znaki). 128 bitów wystarcza na podpisany link, a adres jest o 21 znaków
 * krótszy niż w starym formacie. Stary podpis (43 znaki) jest nadal akceptowany,
 * bo linki wysłane wcześniej SMS-em muszą działać.
 */
function tickets_tracking_mac_short(int $ticketId, int $version): string
{
    $raw = hash_hmac('sha256', 'ticket|' . $ticketId . '|' . $version, tickets_tracking_key(), true);
    return rtrim(strtr(base64_encode(substr($raw, 0, 16)), '+/', '-_'), '=');
}

const TICKET_TOKEN_BASE36 = '0123456789abcdefghijklmnopqrstuvwxyz';

/** Liczba w base36 (cyfry i małe litery) - krótszy zapis id zgłoszenia i wersji. */
function tickets_base36(int $value): string
{
    if ($value <= 0) {
        return '0';
    }
    $out = '';
    while ($value > 0) {
        $out = TICKET_TOKEN_BASE36[$value % 36] . $out;
        $value = intdiv($value, 36);
    }
    return $out;
}

/** Odczyt base36; null dla niepoprawnego zapisu albo zera. */
function tickets_base36_decode(string $value): ?int
{
    if ($value === '' || strlen($value) > 7 || !preg_match('/^[0-9a-z]+$/', $value)) {
        return null;
    }
    $number = 0;
    foreach (str_split($value) as $char) {
        $digit = strpos(TICKET_TOKEN_BASE36, $char);
        if ($digit === false) {
            return null;
        }
        $number = $number * 36 + $digit;
    }
    return $number > 0 ? $number : null;
}

/**
 * Token śledzenia w formacie <id>.<wersja>.<podpis>, id i wersja w base36.
 * Nie wymaga kolumny z sekretem: link da się odtworzyć w panelu, a wyciek samej
 * bazy nie wystarcza do jego policzenia. Nowa wersja (refresh) unieważnia
 * poprzedni link.
 */
function tickets_tracking_token(int $ticketId, bool $refresh = false): string
{
    $ticket = tickets_by_id($ticketId);
    if (!$ticket) {
        return '';
    }
    $version = (int) ($ticket['status_token_version'] ?? 0);
    if ($version <= 0 || $refresh) {
        // Wersja zawsze rośnie, żeby odświeżenie w tej samej sekundzie też unieważniło link.
        $version = max(time(), $version + 1);
        db()->prepare('UPDATE tickets SET status_token_version = ? WHERE id = ?')->execute([$version, $ticketId]);
    }
    return tickets_base36($ticketId) . '.' . tickets_base36($version) . '.' . tickets_tracking_mac_short($ticketId, $version);
}

/**
 * Bazowy adres krótkiej strony statusu albo '' (wtedy stary adres /status/<token>/).
 * Krótkie linki (status.mati-it.pl/<token>) wymagają wpisu w konfiguracji panelu.
 */
function tickets_tracking_base_url(): string
{
    $base = trim((string) (function_exists('cfg') ? cfg('status_base_url', '') : ''));
    return $base !== '' ? rtrim($base, '/') : '';
}

function tickets_tracking_url(string $token): string
{
    if ($token === '') {
        return '';
    }
    $base = tickets_tracking_base_url();
    if ($base !== '') {
        return $base . '/' . $token;
    }
    return rtrim((string) cfg('site_url'), '/') . '/status/' . $token . '/';
}

/** Gotowy link do strony statusu dla zgłoszenia ('' gdy brak numeru id). */
function tickets_tracking_link(array $ticket): string
{
    $id = (int) ($ticket['id'] ?? 0);
    return $id > 0 ? tickets_tracking_url(tickets_tracking_token($id)) : '';
}

/**
 * Token z żądania: parametr ?t=, ścieżka /status/<token>/ albo - gdy ustawiono
 * status_base_url - token w rootcie na skonfigurowanym hoście (krótkie linki).
 */
function tickets_tracking_token_from_request(): string
{
    $param = trim((string) ($_GET['t'] ?? ''));
    if ($param !== '') {
        return $param;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (preg_match('~/status/([^/?#]+)~', $uri, $m)) {
        return $m[1];
    }

    // Krótki adres: token jest w rootcie. Przyjmujemy go wyłącznie wtedy, gdy
    // żądanie przyszło na host z status_base_url - inaczej każda ścieżka CMS-a
    // (np. /panel/) byłaby traktowana jak token.
    $base = tickets_tracking_base_url();
    if ($base === '') {
        return '';
    }
    $baseHost = strtolower((string) parse_url($base, PHP_URL_HOST));
    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($baseHost === '' || $host === '' || strcasecmp($host, $baseHost) !== 0) {
        return '';
    }
    $path = (string) parse_url($uri, PHP_URL_PATH);
    if (preg_match('~^/([^/?#]+)/?$~', $path, $m) && tickets_tracking_token_parts($m[1]) !== null) {
        return $m[1];
    }
    return '';
}

/**
 * Rozbija token na części albo zwraca null. Obsługiwane są oba formaty:
 * nowy (id i wersja w base36, podpis 22 znaki) oraz stary (liczby dziesiętne,
 * podpis 43 znaki), bo linki wysłane wcześniej nadal muszą działać.
 *
 * @return array{id:int,version:int,mac:string,legacy:bool}|null
 */
function tickets_tracking_token_parts(string $token): ?array
{
    if (preg_match('/^([0-9a-z]{1,7})\.([0-9a-z]{1,7})\.([A-Za-z0-9_-]{22})$/', $token, $m)) {
        $id = tickets_base36_decode($m[1]);
        $version = tickets_base36_decode($m[2]);
        if ($id === null || $version === null) {
            return null;
        }
        return ['id' => $id, 'version' => $version, 'mac' => $m[3], 'legacy' => false];
    }
    if (preg_match('/^(\d{1,10})\.(\d{1,12})\.([A-Za-z0-9_-]{43})$/', $token, $m)) {
        $id = (int) $m[1];
        $version = (int) $m[2];
        if ($id <= 0 || $version <= 0) {
            return null;
        }
        return ['id' => $id, 'version' => $version, 'mac' => $m[3], 'legacy' => true];
    }
    return null;
}

/** Zgłoszenie na podstawie tokenu; null = nieprawidłowy lub unieważniony link. */
function tickets_find_by_tracking_token(string $token): ?array
{
    $parts = tickets_tracking_token_parts($token);
    if ($parts === null) {
        return null;
    }
    $ticket = tickets_by_id($parts['id']);
    if (!$ticket || (int) ($ticket['status_token_version'] ?? 0) !== $parts['version']) {
        return null;
    }
    $expected = $parts['legacy']
        ? tickets_tracking_mac($parts['id'], $parts['version'])
        : tickets_tracking_mac_short($parts['id'], $parts['version']);
    if (!hash_equals($expected, $parts['mac'])) {
        return null;
    }
    return $ticket;
}

/** Dane kontaktowe serwisu ze strony (src/data/site.json) - bez danych klienta. */
function ticket_public_service(): array
{
    static $service = null;
    if (is_array($service)) {
        return $service;
    }
    $root = defined('SK_PANEL_ROOT') ? dirname(SK_PANEL_ROOT) : dirname(__DIR__, 2);
    $file = rtrim((string) $root, '/') . '/src/data/site.json';
    $site = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $site = is_array($site) ? $site : [];

    $address = (array) ($site['address'] ?? []);
    $street = trim((string) ($address['street'] ?? ''));
    $city = trim(trim((string) ($address['postalCode'] ?? '')) . ' ' . trim((string) ($address['city'] ?? '')));
    $hours = (array) ($site['hours'] ?? []);
    $firstHour = (array) ($hours[0] ?? []);
    $hoursLabel = trim((string) ($firstHour['label'] ?? ''));
    $hoursValue = trim((string) ($firstHour['value'] ?? ''));

    $service = [
        'name' => trim((string) ($site['name'] ?? '')) ?: 'Studio-Komp',
        'phone' => trim((string) ($site['phoneDisplay'] ?? '')) ?: '500 613 500',
        'phone_href' => 'tel:' . preg_replace('/\s+/', '', trim((string) ($site['phoneTel'] ?? '')) ?: '+48500613500'),
        'address' => implode(', ', array_filter([$street, $city])),
        'hours' => $hoursLabel !== '' && $hoursValue !== '' ? $hoursLabel . ': ' . $hoursValue : 'pon.-pt. 7:00-15:00',
        'url' => rtrim((string) cfg('site_url'), '/'),
    ];
    return $service;
}

/**
 * Model widoku publicznej strony statusu. Zawiera wyłącznie dane bezpieczne:
 * numer zgłoszenia, sprzęt, daty, etap i publiczną notatkę serwisu.
 */
function ticket_public_view(array $ticket): array
{
    $ticketId = (int) ($ticket['id'] ?? 0);
    $status = (string) ($ticket['status'] ?? 'new');
    $stage = ticket_client_stage($status);

    if ($stage === 'cancelled') {
        // Anulowanie nie jest wydaniem sprzętu: kończymy na osobnym etapie
        // "Anulowane" i nie pokazujemy ani "Gotowy do wydania", ani "Odebrany".
        $order = ['accepted', 'in_progress', 'cancelled'];
    } else {
        $order = ['accepted', 'in_progress', 'ready'];
        if ($stage === 'picked_up') {
            $order[] = 'picked_up';
        }
    }

    // Czas wejścia w etap: pierwszy wpis historii o statusie mapowanym na ten etap.
    $stageAt = [];
    foreach (tickets_history($ticketId) as $row) {
        $key = ticket_client_stage((string) ($row['status'] ?? ''));
        if (!isset($stageAt[$key]) && (string) ($row['created_at'] ?? '') !== '') {
            $stageAt[$key] = (string) $row['created_at'];
        }
    }
    // Etap "Przyjęty" ma datę zawsze: sprzęt został przyjęty w chwili utworzenia
    // zgłoszenia. Historia uzupełnia tylko pozostałe etapy - dzięki temu data nie
    // ginie, gdy historia zaczyna się od późniejszego statusu (np. zgłoszenie
    // zsynchronizowane z aplikacji już jako "gotowy do wydania").
    if ((string) ($ticket['created_at'] ?? '') !== '') {
        $stageAt['accepted'] = (string) $ticket['created_at'];
    }

    $captions = [
        'accepted' => 'Sprzęt przyjęty w serwisie.',
        'in_progress' => 'Diagnoza i naprawa w toku.',
        'ready' => 'Sprzęt gotowy - zapraszamy do odbioru.',
        'picked_up' => 'Sprzęt wydany klientowi.',
        'cancelled' => 'Naprawa anulowana - szczegóły pod numerem 500 613 500.',
    ];

    $currentIndex = array_search($stage, $order, true);
    $currentIndex = $currentIndex === false ? 0 : (int) $currentIndex;
    $steps = [];
    foreach ($order as $index => $key) {
        if ($stage === 'cancelled') {
            // Etap oznaczamy jako zakończony tylko wtedy, gdy faktycznie jest
            // w historii - przy anulowaniu przed naprawą nie udajemy jej przebiegu.
            $state = $key === 'cancelled' ? 'current' : (isset($stageAt[$key]) ? 'done' : 'todo');
        } else {
            $state = $index < $currentIndex ? 'done' : ($index === $currentIndex ? 'current' : 'todo');
        }
        $at = $index <= $currentIndex ? ticket_format_datetime($stageAt[$key] ?? '') : '';
        $steps[] = [
            'key' => $key,
            'label' => CLIENT_STAGES[$key],
            'state' => $state,
            'at' => $at,
            'caption' => $captions[$key] ?? '',
        ];
    }

    $created = ticket_format_datetime((string) ($ticket['created_at'] ?? ''));
    $createdParts = $created === '' ? ['', ''] : explode(', ', $created);

    return [
        'number' => (string) ($ticket['number'] ?? ''),
        'device' => (string) ($ticket['device'] ?? ''),
        'received_date' => $createdParts[0],
        'received_time' => $createdParts[1] ?? '',
        'stage' => $stage,
        'stage_label' => CLIENT_STAGES[$stage],
        'stage_index' => $currentIndex + 1,
        'stage_total' => count($order),
        'steps' => $steps,
        'public_note' => (string) ($ticket['public_note'] ?? ''),
        'updated_at' => ticket_format_datetime((string) ($ticket['updated_at'] ?? '')),
        'service' => ticket_public_service(),
    ];
}

/** Format daty z bazy na czytelny dla klienta: "25.11.2026, 11:20". */
function ticket_format_datetime(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable $e) {
        return '';
    }
    return $date->format('d.m.Y, H:i');
}

/** Szablon SMS do klienta (pusty wpis w ustawieniach = domyślny). */
function tickets_client_sms_template(string $template): string
{
    $configured = trim(setting_get('sms_client_' . $template));
    if ($configured === '') {
        $configured = TICKET_CLIENT_SMS_DEFAULTS[$template] ?? '';
    }
    return $configured;
}

/** Treść SMS do klienta z linkiem do strony statusu. */
function tickets_client_sms_message(array $ticket, string $template): string
{
    $service = ticket_public_service();
    $device = trim((string) ($ticket['device'] ?? ''));
    $vars = [
        '{numer}' => (string) ($ticket['number'] ?? ''),
        '{number}' => (string) ($ticket['number'] ?? ''),
        '{imie}' => (string) ($ticket['name'] ?? ''),
        '{sprzet}' => $device !== '' ? $device : 'sprzet',
        '{serwis}' => (string) $service['name'],
        '{telefon}' => (string) $service['phone'],
        '{link}' => tickets_tracking_link($ticket),
    ];
    $message = strtr(tickets_client_sms_template($template), $vars);
    $message = tickets_sms_ascii($message);
    $message = preg_replace('/\s+/u', ' ', $message) ?? $message;
    return mb_substr(trim($message), 0, 459, 'UTF-8');
}

/**
 * Kolejkuje SMS do klienta (tylko gdy włączone w ustawieniach i jest telefon).
 * UNIQUE(ticket_id, channel, template) blokuje duplikaty tego samego etapu.
 */
function tickets_enqueue_client_sms(PDO $pdo, int $ticketId, array $ticket, string $template): bool
{
    if (!isset(TICKET_CLIENT_SMS_DEFAULTS[$template])) {
        return false;
    }
    if (setting_get('sms_client_enabled', '1') !== '1') {
        return false;
    }
    $phone = trim((string) ($ticket['phone'] ?? ''));
    if ($phone === '') {
        return false;
    }
    $provider = setting_get('sms_provider', 'smsapi');
    if (!in_array($provider, ['smsapi', 'serwersms'], true)) {
        $provider = 'smsapi';
    }
    $now = now();
    $payload = json_encode([
        'template' => $template,
        'number' => (string) ($ticket['number'] ?? ''),
        'message' => tickets_sms_masked_preview($ticket) . ' - wiadomość do klienta',
    ], JSON_UNESCAPED_UNICODE);
    $st = $pdo->prepare(
        'INSERT OR IGNORE INTO notifications (ticket_id, channel, provider, recipient, template, payload, status, attempts, created_at, updated_at, next_attempt_at)
         VALUES (?,?,?,?,?,?,?,0,?,?,?)'
    );
    $st->execute([$ticketId, 'sms', $provider, $phone, $template, $payload, 'pending', $now, $now, $now]);
    return $st->rowCount() > 0;
}

/**
 * Limit tempa publicznej strony statusu (IP-hash, 30 żądań na minutę).
 * Starsze wpisy są czyszczone okazjonalnie, żeby tabela nie rosła.
 */
function ticket_status_rate_ok(int $limit = TICKET_STATUS_RATE_LIMIT, int $windowSeconds = TICKET_STATUS_RATE_WINDOW): bool
{
    $pdo = db();
    if (random_int(1, 20) === 1) {
        $pdo->prepare('DELETE FROM status_rate WHERE created_at < ?')
            ->execute([(new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s')]);
    }
    $hash = function_exists('security_ip_hash') ? security_ip_hash() : '';
    $since = (new DateTimeImmutable('-' . max(1, $windowSeconds) . ' seconds'))->format('Y-m-d H:i:s');
    $st = $pdo->prepare('SELECT COUNT(*) FROM status_rate WHERE ip_hash = ? AND created_at >= ?');
    $st->execute([$hash, $since]);
    if ((int) $st->fetchColumn() >= $limit) {
        return false;
    }
    $pdo->prepare('INSERT INTO status_rate (ip_hash, created_at) VALUES (?,?)')
        ->execute([$hash, (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')]);
    return true;
}

/** Publiczna notatka serwisu widoczna na stronie statusu (panel, Ustawienia pola). */
function ticket_set_public_note(int $ticketId, string $note): array
{
    $ticket = tickets_by_id($ticketId);
    if (!$ticket) {
        return ['ok' => false, 'error' => 'Nie znaleziono zgłoszenia.'];
    }
    $note = tickets_normalize_text($note, 500, true);
    if ($note === (string) ($ticket['public_note'] ?? '')) {
        return ['ok' => true];
    }
    db()->prepare('UPDATE tickets SET public_note = ?, updated_at = ? WHERE id = ?')
        ->execute([$note, now(), $ticketId]);
    tickets_log('panel: zmieniono publiczną notatkę zgłoszenia ' . (string) ($ticket['number'] ?? ''));
    return ['ok' => true];
}

/** Temat i treść e-maila powiadomienia (HTML + plain). */
function tickets_mail_body(array $ticket): array
{
    $number = (string) ($ticket['number'] ?? '');
    $subject = 'Nowe zgloszenie ' . $number . ' - ' . (string) ($ticket['name'] ?? '');
    $url = tickets_panel_tickets_url();
    $rows = [
        'Numer' => $number,
        'Imię' => (string) ($ticket['name'] ?? ''),
        'Telefon' => tickets_display_phone((string) ($ticket['phone'] ?? '')),
        'Sprzęt' => (string) ($ticket['device'] ?? '') !== '' ? (string) $ticket['device'] : '-',
        'Opis usterki' => (string) ($ticket['problem'] ?? ''),
        'Strona źródłowa' => (string) ($ticket['source_page'] ?? '') !== '' ? (string) $ticket['source_page'] : '-',
        'Data' => (string) ($ticket['created_at'] ?? now()),
    ];
    $htmlRows = '';
    $textRows = '';
    foreach ($rows as $label => $value) {
        $htmlRows .= '<tr><th style="text-align:left;padding:6px 10px;border-bottom:1px solid #e5e5e5;white-space:nowrap;vertical-align:top">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th><td style="padding:6px 10px;border-bottom:1px solid #e5e5e5">'
            . nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8')) . '</td></tr>';
        $textRows .= $label . ': ' . $value . "\n";
    }
    $html = '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>'
        . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title></head><body style="font-family:Arial,sans-serif;color:#242a30">'
        . '<h2 style="font-size:18px">Nowe zgłoszenie serwisowe ' . htmlspecialchars($number, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<table style="border-collapse:collapse;font-size:14px">' . $htmlRows . '</table>'
        . '<p style="font-size:14px"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Otwórz zgłoszenia w panelu</a></p>'
        . '<p style="font-size:12px;color:#777">Wiadomość wygenerowana automatycznie przez panel Studio-Komp. To powiadomienie wewnętrzne, nie marketing.</p>'
        . '</body></html>';
    $text = "Nowe zgłoszenie serwisowe {$number}\n\n" . $textRows . "\nPanel: {$url}\n";
    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/**
 * Przetwarza kolejkę powiadomień (worker cron lub przycisk w panelu).
 * Pobiera wpisy pending/failed z next_attempt_at <= now, maks. 1 próba na wpis w przebiegu.
 *
 * @return array{processed:int,sent:int,test:int,skipped:int,failed:int}
 */
function tickets_process_queue(int $limit = 20): array
{
    $summary = ['processed' => 0, 'sent' => 0, 'test' => 0, 'skipped' => 0, 'failed' => 0];
    $pdo = db();
    $now = now();

    $st = $pdo->prepare(
        "SELECT * FROM notifications
         WHERE status IN ('pending', 'failed') AND attempts < ? AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
         ORDER BY id ASC LIMIT " . max(1, min(100, $limit))
    );
    $st->execute([TICKET_NOTIFY_MAX_ATTEMPTS, $now]);
    $queue = $st->fetchAll();

    $smsConfig = tickets_sms_config();
    $mailConfig = mailer_config();

    foreach ($queue as $notification) {
        $summary['processed']++;
        $ticket = tickets_by_id((int) $notification['ticket_id']);
        if (!$ticket) {
            tickets_notification_update((int) $notification['id'], [
                'status' => 'failed',
                'attempts' => TICKET_NOTIFY_MAX_ATTEMPTS,
                'last_error' => 'Brak zgłoszenia w bazie.',
                'next_attempt_at' => null,
            ]);
            $summary['failed']++;
            tickets_log('kolejka: pominięto powiadomienie #' . $notification['id'] . ' - brak zgłoszenia');
            continue;
        }

        if ($notification['channel'] === 'sms') {
            $template = (string) ($notification['template'] ?? 'new_ticket');
            $isClient = isset(TICKET_CLIENT_SMS_DEFAULTS[$template]);
            $recipient = trim((string) $notification['recipient']);
            if ($recipient === '') {
                $recipient = $isClient
                    ? trim((string) ($ticket['phone'] ?? ''))
                    : trim($smsConfig['recipient']);
            }
            if ($isClient && setting_get('sms_client_enabled', '1') !== '1') {
                $result = ['ok' => false, 'skipped' => true, 'error' => 'Wysyłka SMS do klienta wyłączona w ustawieniach.'];
            } else {
                $message = $isClient ? tickets_client_sms_message($ticket, $template) : tickets_sms_message($ticket);
                $result = sms_send($recipient, $message, $smsConfig);
            }
        } elseif ($notification['channel'] === 'email') {
            $recipient = trim((string) $notification['recipient']);
            if ($recipient === '') {
                $recipient = trim($mailConfig['to']);
            }
            $mail = tickets_mail_body($ticket);
            $result = mailer_send($recipient, $mail['subject'], $mail['html'], $mail['text']);
        } else {
            $result = ['ok' => false, 'error' => 'Nieznany kanał powiadomienia.'];
        }

        $attempts = (int) $notification['attempts'] + 1;
        $fields = [
            'attempts' => $attempts,
            'provider' => (string) ($notification['channel'] === 'sms' ? ($smsConfig['provider'] ?? '') : 'smtp'),
            'recipient' => (string) $recipient,
        ];

        if (!empty($result['test'])) {
            $fields += [
                'status' => 'test',
                'delivery_status' => 'test',
                'last_error' => '',
                'provider_message_id' => '',
                'next_attempt_at' => null,
            ];
            $summary['test']++;
            tickets_log('kolejka: powiadomienie #' . $notification['id'] . ' (SMS) - tryb testowy, bez wysyłki');
        } elseif (!empty($result['skipped'])) {
            $fields += [
                'status' => 'skipped',
                'delivery_status' => 'skipped',
                'last_error' => mb_substr((string) ($result['error'] ?? 'Pominięto.'), 0, 300, 'UTF-8'),
                'next_attempt_at' => null,
            ];
            $summary['skipped']++;
            tickets_log('kolejka: powiadomienie #' . $notification['id'] . ' pominięte (' . $notification['channel'] . ')');
        } elseif (!empty($result['ok'])) {
            $fields += [
                'status' => 'sent',
                'delivery_status' => mb_substr((string) ($result['delivery_status'] ?? 'sent'), 0, 40, 'UTF-8'),
                'last_error' => '',
                'provider_message_id' => mb_substr((string) ($result['provider_message_id'] ?? ''), 0, 120, 'UTF-8'),
                'next_attempt_at' => null,
            ];
            $summary['sent']++;
            tickets_log('kolejka: powiadomienie #' . $notification['id'] . ' (' . $notification['channel'] . ') wysłane');
        } else {
            $summary['failed']++;
            $fields['last_error'] = mb_substr((string) ($result['error'] ?? 'Nieznany błąd wysyłki.'), 0, 300, 'UTF-8');
            $fields['status'] = 'failed';
            $fields['delivery_status'] = 'failed';
            $fields['next_attempt_at'] = tickets_next_attempt_at($attempts);
            if ($fields['next_attempt_at'] === null) {
                $fields['provider_message_id'] = '';
            }
            tickets_log('kolejka: powiadomienie #' . $notification['id'] . ' (' . $notification['channel'] . ') - błąd, próba ' . $attempts);
        }

        tickets_notification_update((int) $notification['id'], $fields);
    }

    return $summary;
}

/**
 * Data następnej próby wg backoff (1, 5, 15, 60 min) lub null po wyczerpaniu prób.
 */
function tickets_next_attempt_at(int $attempts): ?string
{
    if ($attempts >= TICKET_NOTIFY_MAX_ATTEMPTS) {
        return null;
    }
    $index = max(0, min(count(TICKET_NOTIFY_BACKOFF_MINUTES) - 1, $attempts - 1));
    $minutes = TICKET_NOTIFY_BACKOFF_MINUTES[$index];
    return (new DateTimeImmutable("+{$minutes} minutes"))->format('Y-m-d H:i:s');
}

function tickets_notification_update(int $id, array $fields): void
{
    $allowed = ['status', 'attempts', 'last_error', 'provider_message_id', 'delivery_status', 'next_attempt_at', 'provider', 'recipient'];
    $set = [];
    $params = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $fields)) {
            $set[] = $key . ' = ?';
            $params[] = $fields[$key];
        }
    }
    if (!$set) {
        return;
    }
    $set[] = 'updated_at = ?';
    $params[] = now();
    $params[] = $id;
    db()->prepare('UPDATE notifications SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
}

/**
 * Ręczne ponowienie powiadomienia z panelu (reset liczników i statusu).
 * @return array{ok:bool,error?:string}
 */
function tickets_retry_notification(int $notificationId): array
{
    $st = db()->prepare('SELECT * FROM notifications WHERE id = ? LIMIT 1');
    $st->execute([$notificationId]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'Nie znaleziono powiadomienia.'];
    }
    if ($row['status'] === 'sent') {
        return ['ok' => false, 'error' => 'Ta wiadomość została już wysłana.'];
    }
    tickets_notification_update($notificationId, [
        'status' => 'pending',
        'attempts' => 0,
        'last_error' => '',
        'delivery_status' => '',
        'next_attempt_at' => now(),
    ]);
    tickets_log('panel: ręczne ponowienie powiadomienia #' . $notificationId);
    return ['ok' => true];
}

/* --------------------------------- integracja z aplikacją serwisową (sync) */

/** Pola przyjmowane w ładunku synchronizacji - wszystko inne to błąd 400. */
const TICKET_SYNC_ALLOWED_FIELDS = [
    'reference', 'phone', 'device', 'issue', 'status',
    'planned_at', 'accepted_at', 'express', 'event', 'note',
];

/** Numer zlecenia w aplikacji serwisowej: SRV-ROK-000000. */
const TICKET_SYNC_REFERENCE_PATTERN = '/^SRV-\d{4}-\d{6}$/';

/** Limit tempa endpointu synchronizacji (żądania / okno w sekundach). */
const TICKET_SYNC_RATE_LIMIT = 60;
const TICKET_SYNC_RATE_WINDOW = 60;

/** Dopuszczalne odchylenie znacznika czasu żądania (sekundy). */
const TICKET_SYNC_CLOCK_SKEW = 300;

/**
 * Sekret integracji z serwis.mati-it.pl. Pusty = integracja wyłączona
 * (endpoint odpowiada 503, aplikacja działa dalej bez linku do statusu).
 */
function tickets_sync_secret(): string
{
    return trim((string) (function_exists('cfg') ? cfg('service_sync_secret', '') : ''));
}

/** Podpis żądania: base64url(HMAC-SHA256(secret, timestamp . "\n" . rawBody)). */
function tickets_sync_signature(string $secret, string $timestamp, string $body): string
{
    $raw = hash_hmac('sha256', $timestamp . "\n" . $body, $secret, true);
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** Porównanie podpisu w stałym czasie (hash_equals), bez ujawniania różnic. */
function tickets_sync_signature_ok(string $secret, string $timestamp, string $body, string $signature): bool
{
    if ($secret === '' || $signature === '' || $timestamp === '') {
        return false;
    }
    return hash_equals(tickets_sync_signature($secret, $timestamp, $body), $signature);
}

/** Znacznik czasu w oknie ±300 s - ochrona przed powtórzeniem żądania (replay). */
function tickets_sync_timestamp_ok(string $timestamp, int $now = 0, int $skew = TICKET_SYNC_CLOCK_SKEW): bool
{
    if (!preg_match('/^\d{9,11}$/', $timestamp)) {
        return false;
    }
    $now = $now > 0 ? $now : time();
    return abs($now - (int) $timestamp) <= $skew;
}

/**
 * Limit tempa endpointu synchronizacji: 60 żądań / minutę / IP-hash.
 * Liczone są wszystkie żądania (także z złym podpisem), żeby ograniczyć
 * zgadywanie sekretu; stare wpisy są czyszczone okazjonalnie.
 */
function tickets_sync_rate_ok(int $limit = TICKET_SYNC_RATE_LIMIT, int $windowSeconds = TICKET_SYNC_RATE_WINDOW): bool
{
    $pdo = db();
    if (random_int(1, 20) === 1) {
        $pdo->prepare('DELETE FROM sync_rate WHERE created_at < ?')
            ->execute([(new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s')]);
    }
    $hash = function_exists('security_ip_hash') ? security_ip_hash() : '';
    $since = (new DateTimeImmutable('-' . max(1, $windowSeconds) . ' seconds'))->format('Y-m-d H:i:s');
    $st = $pdo->prepare('SELECT COUNT(*) FROM sync_rate WHERE ip_hash = ? AND created_at >= ?');
    $st->execute([$hash, $since]);
    if ((int) $st->fetchColumn() >= $limit) {
        return false;
    }
    $pdo->prepare('INSERT INTO sync_rate (ip_hash, created_at) VALUES (?,?)')
        ->execute([$hash, (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')]);
    return true;
}

/**
 * Walidacja ładunku z aplikacji serwisowej. Odrzuca nieznane pola, złe typy
 * i zbyt długie teksty - aplikacja jest źródłem prawdy o zleceniu, więc
 * literówka ma zostać błędem 400, a nie zmianą stanu naprawy.
 *
 * @return array{ok:bool,error?:string,field?:string,data?:array<string,string>}
 */
function tickets_sync_payload_validate(array $payload): array
{
    $invalid = static function (string $error, string $field = ''): array {
        return ['ok' => false, 'error' => $error] + ($field !== '' ? ['field' => $field] : []);
    };
    $text = static function ($value): ?string {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return null; // tablica, obiekt, bool - zły typ
    };
    $utf8 = static function (string $value): bool {
        return mb_check_encoding($value, 'UTF-8');
    };
    $datetimeOk = static function (string $value): bool {
        if ($value === '' || strlen($value) > 40) {
            return false;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?(Z|[+-]\d{2}:\d{2})?$/', $value)) {
            return false;
        }
        try {
            new DateTimeImmutable($value);
        } catch (Throwable $e) {
            return false;
        }
        return true;
    };

    foreach (array_keys($payload) as $key) {
        if (!in_array((string) $key, TICKET_SYNC_ALLOWED_FIELDS, true)) {
            return $invalid('unknown_field', (string) $key);
        }
    }

    $reference = $text($payload['reference'] ?? null);
    if ($reference === null) {
        return $invalid('invalid_field_type', 'reference');
    }
    $reference = trim($reference);
    if (!preg_match(TICKET_SYNC_REFERENCE_PATTERN, $reference)) {
        return $invalid('invalid_reference', 'reference');
    }

    $phoneRaw = $text($payload['phone'] ?? null);
    if ($phoneRaw === null || !$utf8($phoneRaw)) {
        return $invalid('invalid_phone', 'phone');
    }
    $phone = tickets_normalize_phone($phoneRaw);
    if ($phone === '') {
        return $invalid('invalid_phone', 'phone');
    }

    $deviceRaw = $text($payload['device'] ?? null);
    if ($deviceRaw === null || !$utf8($deviceRaw)) {
        return $invalid('invalid_device', 'device');
    }
    $device = tickets_normalize_text($deviceRaw, 0);
    if ($device === '' || mb_strlen($device, 'UTF-8') > 120) {
        return $invalid('invalid_device', 'device');
    }

    $issueRaw = $text($payload['issue'] ?? null);
    if ($issueRaw === null || !$utf8($issueRaw)) {
        return $invalid('invalid_issue', 'issue');
    }
    $issue = tickets_normalize_text($issueRaw, 0, true);
    if ($issue === '' || mb_strlen($issue, 'UTF-8') > 2000) {
        return $invalid('invalid_issue', 'issue');
    }

    $status = trim((string) ($text($payload['status'] ?? null) ?? ''));
    if (!isset(tickets_sync_status_map()[$status])) {
        return $invalid('invalid_status', 'status');
    }

    $note = '';
    if (array_key_exists('note', $payload)) {
        $noteRaw = $text($payload['note']);
        if ($noteRaw === null || !$utf8($noteRaw)) {
            return $invalid('invalid_note', 'note');
        }
        $note = tickets_normalize_text($noteRaw, 0, true);
        if (mb_strlen($note, 'UTF-8') > 500) {
            return $invalid('invalid_note', 'note');
        }
    }

    $acceptedAt = '';
    if (array_key_exists('accepted_at', $payload) && $payload['accepted_at'] !== null) {
        $acceptedRaw = $text($payload['accepted_at']);
        if ($acceptedRaw === null || !$datetimeOk(trim($acceptedRaw))) {
            return $invalid('invalid_accepted_at', 'accepted_at');
        }
        // Rzeczywista data przyjęcia sprzętu w aplikacji - trafia do created_at
        // zgłoszenia, żeby przerwa w dostępie do CMS nie przesuwała etapu
        // "Przyjęty". Data z przyszłości to błąd aplikacji, nie stan naprawy.
        $timezone = new DateTimeZone('Europe/Warsaw');
        $acceptedDate = new DateTimeImmutable(trim($acceptedRaw), $timezone);
        if ($acceptedDate->getTimestamp() > time() + TICKET_SYNC_CLOCK_SKEW) {
            return $invalid('invalid_accepted_at', 'accepted_at');
        }
        $acceptedAt = $acceptedDate->setTimezone($timezone)->format('Y-m-d H:i:s');
    }

    // planned_at jest tylko walidowane: na stronie statusu nie pokazujemy terminu
    // (nie ma kolumny, a klient dostaje termin w publicznej notatce przy zmianie).
    if (array_key_exists('planned_at', $payload) && $payload['planned_at'] !== null) {
        $plannedRaw = $text($payload['planned_at']);
        if ($plannedRaw === null || !$datetimeOk(trim($plannedRaw))) {
            return $invalid('invalid_planned_at', 'planned_at');
        }
    }

    if (array_key_exists('express', $payload)) {
        $express = $payload['express'];
        $expressOk = is_bool($express) || in_array($express, [0, 1, '0', '1'], true);
        if (!$expressOk) {
            return $invalid('invalid_express', 'express');
        }
    }

    $event = '';
    if (array_key_exists('event', $payload) && $payload['event'] !== null) {
        $eventValue = $text($payload['event']);
        if ($eventValue === null || !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', trim($eventValue))) {
            return $invalid('invalid_event', 'event');
        }
        $event = trim($eventValue);
    }

    return [
        'ok' => true,
        'data' => [
            'reference' => $reference,
            'phone' => $phone,
            'device' => $device,
            'issue' => $issue,
            'status' => tickets_sync_status_map()[$status],
            'note' => $note,
            'event' => $event,
            'accepted_at' => $acceptedAt,
        ],
    ];
}

function tickets_find_by_external_ref(string $reference): ?array
{
    if ($reference === '') {
        return null;
    }
    $st = db()->prepare('SELECT * FROM tickets WHERE external_ref = ? LIMIT 1');
    $st->execute([$reference]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Synchronizacja zlecenia z aplikacji serwisowej: tworzy zgłoszenie po
 * external_ref albo aktualizuje istniejące. Idempotencja: to samo żądanie nie
 * tworzy drugiego zgłoszenia, nie dubluje SMS-a i nie dopisuje historii.
 *
 * @return array{ok:bool,error?:string,id?:int,number?:string,url?:string,status?:string,sms_queued?:array<int,string>,created?:bool}
 */
function tickets_sync_external(array $payload): array
{
    $validated = tickets_sync_payload_validate($payload);
    if (empty($validated['ok'])) {
        return ['ok' => false, 'error' => (string) ($validated['error'] ?? 'invalid_payload')];
    }
    $data = (array) $validated['data'];

    $existing = tickets_find_by_external_ref($data['reference']);
    if ($existing) {
        return tickets_sync_apply($existing, $data);
    }

    $pdo = db();
    $attempts = 0;
    while ($attempts < 3) {
        $attempts++;
        try {
            $pdo->beginTransaction();
            $now = now();
            $ticketId = tickets_sync_insert($pdo, $data, $now);
            $pdo->commit();
            tickets_log('sync: utworzono zgłoszenie ' . $data['reference'] . ' (status ' . $data['status'] . ')');
            return tickets_sync_result($ticketId, $data['status'], $data['reference'], true);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Równoległe żądanie zdążyło utworzyć zgłoszenie - kończymy aktualizacją.
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $existing = tickets_find_by_external_ref($data['reference']);
                if ($existing) {
                    return tickets_sync_apply($existing, $data);
                }
                usleep(random_int(20000, 60000));
                continue;
            }
            tickets_log('sync: błąd zapisu zgłoszenia ' . $data['reference'] . ' (' . get_class($e) . ')');
            return ['ok' => false, 'error' => 'storage_error'];
        }
    }
    return ['ok' => false, 'error' => 'conflict'];
}

/**
 * Wstawia zgłoszenie z aplikacji (numer = reference zlecenia) i kolejkuje SMS
 * do klienta. Nie tworzy powiadomień do serwisu - o zleceniu wie aplikacja.
 */
function tickets_sync_insert(PDO $pdo, array $data, string $now): int
{
    $clientToken = 'srvc-' . substr(hash('sha256', $data['reference']), 0, 24);
    // Data przyjęcia sprzętu z aplikacji jest prawdziwym początkiem naprawy; gdy
    // jej nie ma, zostaje czas synchronizacji (`updated_at` to zawsze czas zapisu).
    $createdAt = (string) ($data['accepted_at'] ?? '');
    if ($createdAt === '') {
        $createdAt = $now;
    }
    $pdo->prepare(
        'INSERT INTO tickets (number, external_ref, created_at, updated_at, name, phone, device, problem,
            source_page, ip_hash, user_agent, status, consent_at, consent_version, client_token,
            public_note, status_token_version)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $data['reference'],
        $data['reference'],
        $createdAt,
        $now,
        '', // imienia nie znamy w tym kanale - nie zmyślamy go
        $data['phone'],
        $data['device'],
        $data['issue'],
        'serwis',
        '',
        '',
        $data['status'],
        '',
        '',
        $clientToken,
        $data['note'],
        0,
    ]);
    $ticketId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO ticket_history (ticket_id, status, note, username, created_at) VALUES (?,?,?,?,?)')
        ->execute([$ticketId, 'new', 'Zgłoszenie przyjęte z aplikacji serwisowej.', 'serwis', $createdAt]);
    if ($data['status'] !== 'new') {
        // Zgłoszenie przyszło już w dalszym statusie (np. gotowe do wydania) - bez
        // tego wpisu etap "Gotowy do wydania" nie miałby daty, a data przyjęcia
        // świeciłaby jako data gotowości.
        $pdo->prepare('INSERT INTO ticket_history (ticket_id, status, note, username, created_at) VALUES (?,?,?,?,?)')
            ->execute([$ticketId, $data['status'], 'Stan z aplikacji serwisowej przy pierwszej synchronizacji.', 'serwis', $now]);
    }

    $ticket = [
        'id' => $ticketId,
        'number' => $data['reference'],
        'name' => '',
        'phone' => $data['phone'],
        'device' => $data['device'],
        'status' => $data['status'],
    ];
    tickets_enqueue_client_sms($pdo, $ticketId, $ticket, 'client_accepted');
    // Gdy pierwsze zgłoszenie przychodzi już jako "gotowy do wydania" (np. CMS był
    // niedostępny przy przyjęciu), klient dostaje od razu SMS o gotowości.
    if ($data['status'] === 'done') {
        tickets_enqueue_client_sms($pdo, $ticketId, $ticket, 'client_ready');
    }
    return $ticketId;
}

/**
 * Aktualizuje istniejące zgłoszenie. Puste pola nie nadpisują zapisanych
 * wartości, historia rośnie tylko przy faktycznej zmianie statusu.
 */
function tickets_sync_apply(array $ticket, array $data): array
{
    $ticketId = (int) $ticket['id'];
    $currentStatus = (string) $ticket['status'];
    $newStatus = (string) $data['status'];
    $statusChanged = $newStatus !== $currentStatus;

    $fields = [];
    $params = [];
    foreach (['phone', 'device'] as $key) {
        if ($data[$key] !== '' && $data[$key] !== (string) ($ticket[$key] ?? '')) {
            $fields[] = $key . ' = ?';
            $params[] = $data[$key];
        }
    }
    if ($data['note'] !== '' && $data['note'] !== (string) ($ticket['public_note'] ?? '')) {
        $fields[] = 'public_note = ?';
        $params[] = $data['note'];
    }
    if ($statusChanged) {
        $fields[] = 'status = ?';
        $params[] = $newStatus;
    }

    $smsQueued = [];
    $pdo = db();
    if ($fields) {
        $pdo->beginTransaction();
        try {
            $fields[] = 'updated_at = ?';
            $params[] = now();
            $params[] = $ticketId;
            $pdo->prepare('UPDATE tickets SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

            if ($statusChanged) {
                $note = 'Status z aplikacji serwisowej: ' . ticket_status_label($newStatus)
                    . ((string) ($data['event'] ?? '') !== '' ? ' (' . $data['event'] . ').' : '.');
                $pdo->prepare('INSERT INTO ticket_history (ticket_id, status, note, username, created_at) VALUES (?,?,?,?,?)')
                    ->execute([$ticketId, $newStatus, tickets_normalize_text($note, 500), 'serwis', now()]);
                if ($newStatus === 'done' && tickets_enqueue_client_sms(
                    $pdo,
                    $ticketId,
                    array_merge($ticket, ['status' => 'done']),
                    'client_ready'
                )) {
                    $smsQueued[] = 'client_ready';
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            tickets_log('sync: błąd aktualizacji zgłoszenia #' . $ticketId . ' (' . get_class($e) . ')');
            return ['ok' => false, 'error' => 'storage_error'];
        }
    }

    if ($statusChanged) {
        tickets_log('sync: zgłoszenie ' . (string) ($ticket['number'] ?? '') . ' -> status ' . $newStatus);
    }
    $result = tickets_sync_result($ticketId, $newStatus, (string) ($ticket['number'] ?? ''), false);
    $result['sms_queued'] = $smsQueued;
    return $result;
}

/**
 * Wspólna odpowiedź synchronizacji: link do strony statusu, numer i status.
 * Token powstaje przy pierwszym wywołaniu (status_token_version = 0), więc
 * aplikacja dostaje gotowy adres do zapisania w karcie zlecenia.
 */
function tickets_sync_result(int $ticketId, string $status, string $number, bool $created): array
{
    $token = tickets_tracking_token($ticketId);
    $url = tickets_tracking_url($token);
    $smsQueued = [];
    if ($created) {
        // Kolejka jest już zapisana; odczyt mówi, co faktycznie powstało.
        foreach (tickets_notifications($ticketId) as $notification) {
            if (($notification['channel'] ?? '') === 'sms'
                && isset(TICKET_CLIENT_SMS_DEFAULTS[(string) ($notification['template'] ?? '')])) {
                $smsQueued[] = (string) $notification['template'];
            }
        }
    }
    return [
        'ok' => true,
        'id' => $ticketId,
        'number' => $number,
        'url' => $url,
        'status' => $status,
        'sms_queued' => $smsQueued,
        'created' => $created,
    ];
}

/* -------------------------------------------------------- zmiana statusu */

/**
 * Zmienia status zgłoszenia i dopisuje wpis do historii.
 * @return array{ok:bool,error?:string}
 */
function tickets_change_status(int $ticketId, string $status, string $note, string $username): array
{
    if (!isset(TICKET_STATUSES[$status])) {
        return ['ok' => false, 'error' => 'Nieprawidłowy status.'];
    }
    $ticket = tickets_by_id($ticketId);
    if (!$ticket) {
        return ['ok' => false, 'error' => 'Nie znaleziono zgłoszenia.'];
    }
    $note = tickets_normalize_text($note, 500, true);
    $username = tickets_normalize_text($username, 60) ?: 'system';
    $now = now();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE tickets SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, $now, $ticketId]);
        $pdo->prepare('INSERT INTO ticket_history (ticket_id, status, note, username, created_at) VALUES (?,?,?,?,?)')
            ->execute([$ticketId, $status, $note, $username, $now]);
        // Klient dostaje SMS z linkiem dopiero przy przejściu na "gotowy do wydania".
        if ($status === 'done' && (string) ($ticket['status'] ?? '') !== 'done') {
            tickets_enqueue_client_sms($pdo, $ticketId, $ticket + ['status' => 'done'], 'client_ready');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tickets_log('panel: błąd zmiany statusu zgłoszenia #' . $ticketId);
        return ['ok' => false, 'error' => 'Nie udało się zapisać statusu.'];
    }
    return ['ok' => true];
}

/** Usuwa zgłoszenie razem z historią i powiadomieniami (tylko administrator). */
function tickets_delete(int $ticketId): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM notifications WHERE ticket_id = ?')->execute([$ticketId]);
        $pdo->prepare('DELETE FROM ticket_history WHERE ticket_id = ?')->execute([$ticketId]);
        $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tickets_log('panel: błąd usuwania zgłoszenia #' . $ticketId);
        return false;
    }
    tickets_log('panel: usunięto zgłoszenie #' . $ticketId);
    return true;
}
