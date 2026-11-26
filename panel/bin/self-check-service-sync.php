<?php
/**
 * Samokontrola integracji CMS <- aplikacja serwisowa (serwis.mati-it.pl):
 * podpis HMAC, okno czasu, walidacja ładunku, idempotencja synchronizacji,
 * mapowanie statusów, kolejka SMS, krótkie tokeny i adresy linków.
 *
 * Działa na tymczasowym katalogu danych (własna baza SQLite w temp), nie dotyka
 * bazy produkcyjnej, nie wysyła SMS-ów (tryb testowy jest domyślny) i nie łączy
 * się z aplikacją Laravel. Testy HTTP idą do wbudowanego serwera PHP na
 * 127.0.0.1, więc nie wymagają nginxa.
 *
 * Uruchomienie (CLI): php panel/bin/self-check-service-sync.php
 * Kod wyjścia 0 = OK.
 */

if (PHP_SAPI !== 'cli') {
    exit('Skrypt CLI.');
}

$tmp = sys_get_temp_dir() . '/sk-sync-check-' . getmypid();
@mkdir($tmp . '/data', 0770, true);

$repoDir = dirname(__DIR__, 2);
$panelDir = dirname(__DIR__);
$secret = 'self-check-secret-' . bin2hex(random_bytes(8));
$config = [
    'repo_dir' => $repoDir,
    'data_dir' => $tmp . '/data',
    'uploads_dir' => $repoDir . '/public/images/uploads',
    'releases_dir' => $tmp . '/releases',
    'webroot' => $repoDir . '/public',
    'preview_dir' => $tmp . '/preview',
    'site_url' => 'https://sk.mati-it.pl',
    'panel_path' => '/panel',
    'service_sync_secret' => $secret,
    'status_base_url' => 'https://status.mati-it.pl',
];
$configFile = $tmp . '/config.php';
file_put_contents($configFile, "<?php\nreturn " . var_export($config, true) . ";\n");

// Konfiguracja bez sekretu - do sprawdzenia odpowiedzi 503 (integracja wyłączona).
$configNoSecret = $config;
$configNoSecret['service_sync_secret'] = '';
$configNoSecretFile = $tmp . '/config-no-secret.php';
file_put_contents($configNoSecretFile, "<?php\nreturn " . var_export($configNoSecret, true) . ";\n");

file_put_contents($tmp . '/server.log', '');
putenv('SK_PANEL_CONFIG=' . $configFile);

$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) SelfCheckSync/1.0';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'sk.mati-it.pl';

require dirname(__DIR__) . '/api/_init.php';

$failures = [];
$skipped = [];
$checks = 0;
$servers = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures[] = $label . ($detail !== '' ? ' [' . $detail . ']' : '');
    }
}

function skip(string $label, string $reason): void
{
    global $skipped;
    $skipped[] = $label . ' (' . $reason . ')';
}

/* ---------------------------------------------------------- serwer HTTP */

/**
 * Flagi PHP dla procesu potomnego: na Windows pdo_sqlite bywa ładowane wyłącznie
 * przez -d extension=..., a wbudowany serwer startuje jako nowy proces PHP bez
 * flag z rodzica.
 */
function self_check_php_flags(): array
{
    $probe = static function (array $flags): bool {
        $cmd = escapeshellarg(PHP_BINARY);
        foreach ($flags as $flag) {
            $cmd .= ' ' . escapeshellarg($flag);
        }
        $cmd .= ' -r ' . escapeshellarg("exit(extension_loaded('pdo_sqlite') ? 0 : 1);") . ' 2>&1';
        $output = [];
        $code = 1;
        @exec($cmd, $output, $code);
        return $code === 0;
    };
    if ($probe([])) {
        return [];
    }
    $flags = ['-d', 'extension=pdo_sqlite'];
    return $probe($flags) ? $flags : [];
}

/** Uruchamia wbudowany serwer PHP i czeka, aż zacznie przyjmować połączenia. */
function self_check_server(array &$servers, string $configFile, string $panelDir, string $logFile): ?array
{
    $flags = self_check_php_flags();
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $port = random_int(18000, 18999);
        putenv('SK_PANEL_CONFIG=' . $configFile);
        $process = @proc_open(
            array_merge([PHP_BINARY], $flags, ['-S', '127.0.0.1:' . $port, '-t', $panelDir]),
            [0 => ['file', $nullDevice, 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
            $pipes
        );
        if (!is_resource($process)) {
            continue;
        }
        for ($i = 0; $i < 60; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket) {
                fclose($socket);
                $server = ['process' => $process, 'port' => $port];
                $servers[] = $server;
                return $server;
            }
            usleep(100000);
        }
        proc_terminate($process);
        proc_close($process);
    }
    return null;
}

function self_check_server_stop(array $server): void
{
    if (is_resource($server['process'])) {
        proc_terminate($server['process']);
        proc_close($server['process']);
    }
}

/** Żądanie HTTP do wbudowanego serwera; zwraca kod, nagłówki i treść. */
function http_request(string $method, string $url, string $body = '', array $headers = []): array
{
    $headerLines = $headers;
    if ($method === 'POST') {
        $headerLines[] = 'Content-Length: ' . strlen($body);
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headerLines),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 15,
    ]]);
    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $statusLine = (string) ($responseHeaders[0] ?? '');
    preg_match('~\s(\d{3})\s?~', $statusLine, $m);
    return [
        'status' => (int) ($m[1] ?? 0),
        'body' => (string) $responseBody,
        'headers' => array_map('strtolower', $responseHeaders),
        'json' => json_decode((string) $responseBody, true),
    ];
}

/** Podpisane żądanie do endpointu synchronizacji. */
function sync_request(array $server, string $secret, array $payload, ?int $timestamp = null, ?string $signature = null, string $contentType = 'application/json'): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ts = (string) ($timestamp ?? time());
    $sig = $signature ?? tickets_sync_signature($secret, $ts, $body);
    return http_request('POST', 'http://127.0.0.1:' . $server['port'] . '/api/service-sync.php', $body, [
        'Content-Type: ' . $contentType,
        'X-SK-Timestamp: ' . $ts,
        'X-SK-Signature: ' . $sig,
    ]);
}

register_shutdown_function(static function () use (&$servers): void {
    foreach ($servers as $server) {
        self_check_server_stop($server);
    }
});

/* --- 1. Podpis HMAC i okno czasu ---------------------------------------- */

$sampleBody = '{"reference":"SRV-2026-000123"}';
$sampleTs = (string) time();
$sampleSig = tickets_sync_signature($secret, $sampleTs, $sampleBody);
check('podpis ma format base64url bez paddingu', preg_match('/^[A-Za-z0-9_-]{43}$/', $sampleSig) === 1, $sampleSig);
check('poprawny podpis przechodzi', tickets_sync_signature_ok($secret, $sampleTs, $sampleBody, $sampleSig));
check('podpis obejmuje całe ciało żądania', !tickets_sync_signature_ok($secret, $sampleTs, $sampleBody . ' ', $sampleSig));
check('podpis obejmuje znacznik czasu', !tickets_sync_signature_ok($secret, (string) ((int) $sampleTs + 1), $sampleBody, $sampleSig));
check('inny sekret odrzucony', !tickets_sync_signature_ok($secret . 'x', $sampleTs, $sampleBody, $sampleSig));
check('pusty podpis odrzucony', !tickets_sync_signature_ok($secret, $sampleTs, $sampleBody, ''));
check('brak sekretu odrzuca podpis', !tickets_sync_signature_ok('', $sampleTs, $sampleBody, $sampleSig));

$now = time();
check('timestamp teraz w oknie', tickets_sync_timestamp_ok((string) $now, $now));
check('timestamp -299 s w oknie', tickets_sync_timestamp_ok((string) ($now - 299), $now));
check('timestamp +299 s w oknie', tickets_sync_timestamp_ok((string) ($now + 299), $now));
check('timestamp -301 s odrzucony', !tickets_sync_timestamp_ok((string) ($now - 301), $now));
check('timestamp +301 s odrzucony', !tickets_sync_timestamp_ok((string) ($now + 301), $now));
check('timestamp nieliczbowy odrzucony', !tickets_sync_timestamp_ok('2026-11-26', $now));
check('pusty timestamp odrzucony', !tickets_sync_timestamp_ok('', $now));

/* --- 2. Walidacja ładunku ----------------------------------------------- */

$basePayload = [
    'reference' => 'SRV-2026-000101',
    'phone' => '600 100 200',
    'device' => 'Lenovo ThinkPad T480',
    'issue' => 'Laptop nie laduje sie po podlaczeniu zasilacza.',
    'status' => 'pending',
];

$valid = tickets_sync_payload_validate($basePayload);
check('poprawny ładunek przechodzi', !empty($valid['ok']), json_encode($valid, JSON_UNESCAPED_UNICODE));
check('telefon znormalizowany do +48', ($valid['data']['phone'] ?? '') === '+48600100200');
check('pending mapuje się na new', ($valid['data']['status'] ?? '') === 'new');

$map = tickets_sync_status_map();
check('mapa ma sześć statusów aplikacji', count($map) === 6, (string) count($map));
check('awaiting_parts -> in_progress', ($map['awaiting_parts'] ?? '') === 'in_progress');
check('ready_for_pickup -> done', ($map['ready_for_pickup'] ?? '') === 'done');
check('released -> closed', ($map['released'] ?? '') === 'closed');
check('cancelled -> cancelled', ($map['cancelled'] ?? '') === 'cancelled');
check('każdy status mapowania istnieje w TICKET_STATUSES', count(array_diff(array_values($map), array_keys(TICKET_STATUSES))) === 0);

$payloadVariants = [
    'nieznany status' => ['status' => 'spaghetti'],
    'nieznane pole' => ['foo' => 'bar'],
    'zły numer zlecenia' => ['reference' => 'SRV-26-1'],
    'numer zlecenia małymi literami' => ['reference' => 'srv-2026-000101'],
    'zły telefon' => ['phone' => '12345'],
    'pusty sprzęt' => ['device' => ''],
    'zbyt długi sprzęt' => ['device' => str_repeat('X', 200)],
    'pusty opis usterki' => ['issue' => ''],
    'zbyt długi opis usterki' => ['issue' => str_repeat('X', 2100)],
    'zbyt długa notatka' => ['note' => str_repeat('X', 501)],
    'zły termin' => ['planned_at' => '28.11.2026'],
    'termin przyjęcia w przyszłości' => ['accepted_at' => gmdate('Y-m-d H:i:s', time() + 86400)],
    'express nie jest bool' => ['express' => 'yes'],
    'zły event' => ['event' => 'zły event'],
    'pole tablicą' => ['device' => ['x']],
];
foreach ($payloadVariants as $label => $override) {
    $result = tickets_sync_payload_validate(array_merge($basePayload, $override));
    check('odrzucony ładunek: ' . $label, empty($result['ok']), json_encode($result, JSON_UNESCAPED_UNICODE));
}

$allowed = tickets_sync_payload_validate(array_merge($basePayload, [
    'note' => 'Matryca zamówiona.',
    'express' => true,
    'event' => 'accepted',
    'planned_at' => '2026-11-28 15:00',
    'accepted_at' => '2026-11-26T10:15:00+01:00',
]));
check('pola opcjonalne akceptowane', !empty($allowed['ok']), json_encode($allowed, JSON_UNESCAPED_UNICODE));
check('notatka zachowana', ($allowed['data']['note'] ?? '') === 'Matryca zamówiona.');
check('HTML w notatce usunięty', (tickets_sync_payload_validate(array_merge($basePayload, ['note' => '<b>Gotowe</b>']))['data']['note'] ?? '') === 'Gotowe');

/* --- 3. Synchronizacja: utworzenie zgłoszenia --------------------------- */

$first = tickets_sync_external($basePayload + ['note' => 'Zlecenie z aplikacji.']);
check('zgłoszenie utworzone', !empty($first['ok']) && !empty($first['created']), json_encode($first, JSON_UNESCAPED_UNICODE));
check('numer zgłoszenia = reference zlecenia', ($first['number'] ?? '') === 'SRV-2026-000101', (string) ($first['number'] ?? ''));
check('odpowiedź ma link do statusu', str_starts_with((string) ($first['url'] ?? ''), 'https://status.mati-it.pl/'), (string) ($first['url'] ?? ''));
check('kolejka client_accepted po utworzeniu', ($first['sms_queued'] ?? []) === ['client_accepted'], json_encode($first['sms_queued'] ?? []));

$synced = tickets_find_by_external_ref('SRV-2026-000101');
$syncedId = (int) ($synced['id'] ?? 0);
check('external_ref zapisany', $synced !== null && (string) $synced['external_ref'] === 'SRV-2026-000101');
check('źródło zgłoszenia to serwis', ($synced['source_page'] ?? '') === 'serwis');
check('imię puste (nie zmyślamy danych)', ($synced['name'] ?? 'x') === '');
check('client_token wyprowadzony z numeru zlecenia', str_starts_with((string) ($synced['client_token'] ?? ''), 'srvc-'));
check('status po mapowaniu to new', ($synced['status'] ?? '') === 'new');
check('notatka publiczna zapisana', ($synced['public_note'] ?? '') === 'Zlecenie z aplikacji.');
check('adres linku to krótki host', str_contains(tickets_tracking_link($synced ?? []), 'https://status.mati-it.pl/'));

$templates = array_column(tickets_notifications($syncedId), 'template');
check('SMS do serwisu nie powstaje', !in_array('new_ticket', $templates, true), implode(',', $templates));
check('klient dostaje jeden SMS o przyjęciu', count(array_filter($templates, static fn ($t) => $t === 'client_accepted')) === 1);
check('historia ma wpis o przyjęciu', count(tickets_history($syncedId)) === 1);
check('token w bazie: tylko wersja', ((int) ($synced['status_token_version'] ?? 0)) > 0);

/* --- 4. Idempotencja powtórzonego żądania ------------------------------- */

$historyBefore = count(tickets_history($syncedId));
$notificationsBefore = count(tickets_notifications($syncedId));
$repeat = tickets_sync_external($basePayload + ['note' => 'Zlecenie z aplikacji.']);
check('powtórka nie tworzy nowego zgłoszenia', empty($repeat['created']) && (int) ($repeat['id'] ?? 0) === $syncedId, json_encode($repeat, JSON_UNESCAPED_UNICODE));
check('powtórka zwraca ten sam numer', ($repeat['number'] ?? '') === 'SRV-2026-000101');
check('powtórka zwraca ten sam link', ($repeat['url'] ?? '') === ($first['url'] ?? ''));
check('powtórka nie dubluje SMS', ($repeat['sms_queued'] ?? []) === [], json_encode($repeat['sms_queued'] ?? []));
check('powtórka nie dopisuje historii', count(tickets_history($syncedId)) === $historyBefore);
check('powtórka nie dubluje powiadomień', count(tickets_notifications($syncedId)) === $notificationsBefore);
check('liczba zgłoszeń z tym numerem = 1', (int) db()->query("SELECT COUNT(*) FROM tickets WHERE external_ref = 'SRV-2026-000101'")->fetchColumn() === 1);

/* --- 5. Aktualizacja: części, gotowość, wydanie ------------------------- */

$awaitingPayload = [
    'reference' => 'SRV-2026-000101',
    'phone' => '600 100 200',
    'device' => 'Lenovo ThinkPad T480 (16 GB)',
    'issue' => 'Laptop nie laduje sie po podlaczeniu zasilacza.',
    'status' => 'awaiting_parts',
    'note' => 'Czekamy na matrycę.',
];
$awaiting = tickets_sync_external($awaitingPayload);
check('awaiting_parts zapisany jako in_progress', ($awaiting['status'] ?? '') === 'in_progress', (string) ($awaiting['status'] ?? ''));
$synced = tickets_by_id($syncedId) ?: [];
check('sprzęt zaktualizowany', ($synced['device'] ?? '') === 'Lenovo ThinkPad T480 (16 GB)');
check('notatka zaktualizowana', ($synced['public_note'] ?? '') === 'Czekamy na matrycę.');
check('historia rośnie przy zmianie statusu', count(tickets_history($syncedId)) === 2);
check('zmiana na awaiting_parts nie wysyła SMS', ($awaiting['sms_queued'] ?? []) === []);

$awaitingRepeat = tickets_sync_external($awaitingPayload);
check('powtórka awaiting_parts nie dopisuje historii', count(tickets_history($syncedId)) === 2);
check('powtórka awaiting_parts nie kolejkuje SMS', ($awaitingRepeat['sms_queued'] ?? []) === []);

$readyPayload = [
    'reference' => 'SRV-2026-000101',
    'phone' => '600 100 200',
    'device' => 'Lenovo ThinkPad T480 (16 GB)',
    'issue' => 'Laptop nie laduje sie po podlaczeniu zasilacza.',
    'status' => 'ready_for_pickup',
    'note' => 'Sprzęt gotowy do odbioru.',
];
$ready = tickets_sync_external($readyPayload);
check('ready_for_pickup -> done', ($ready['status'] ?? '') === 'done', (string) ($ready['status'] ?? ''));
check('SMS client_ready zakolejkowany', ($ready['sms_queued'] ?? []) === ['client_ready'], json_encode($ready['sms_queued'] ?? []));

$synced = tickets_by_id($syncedId) ?: [];
$view = ticket_public_view($synced);
check('etap klienta Gotowy do wydania', $view['stage'] === 'ready' && $view['stage_label'] === 'Gotowy do wydania');

$readyRepeat = tickets_sync_external($readyPayload);
check('powtórka nie dubluje client_ready', count(array_filter(
    array_column(tickets_notifications($syncedId), 'template'),
    static fn ($t) => $t === 'client_ready'
)) === 1);
check('powtórka gotowości nic nie kolejkuje', ($readyRepeat['sms_queued'] ?? []) === []);

$released = tickets_sync_external([
    'reference' => 'SRV-2026-000101',
    'phone' => '600 100 200',
    'device' => 'Lenovo ThinkPad T480 (16 GB)',
    'issue' => 'Laptop nie laduje sie po podlaczeniu zasilacza.',
    'status' => 'released',
]);
check('released -> closed', ($released['status'] ?? '') === 'closed', (string) ($released['status'] ?? ''));
$releasedView = ticket_public_view(tickets_by_id($syncedId) ?: []);
check('u klienta etap Odebrany', $releasedView['stage'] === 'picked_up' && $releasedView['stage_label'] === 'Odebrany');
check('krok "Przyjęty" ma datę także po wydaniu', ($releasedView['steps'][0]['at'] ?? '') !== '', (string) ($releasedView['steps'][0]['at'] ?? ''));

/* --- 5b. Data przyjęcia z aplikacji (accepted_at) ------------------------ */

$withAccepted = tickets_sync_external([
    'reference' => 'SRV-2026-000104',
    'phone' => '604 000 555',
    'device' => 'MSI Modern 15',
    'issue' => 'Brak dzwieku po aktualizacji systemu.',
    'status' => 'pending',
    'accepted_at' => '2026-11-20T09:30:00+01:00',
]);
$withAcceptedId = (int) ($withAccepted['id'] ?? 0);
$withAcceptedTicket = tickets_by_id($withAcceptedId) ?: [];
$withAcceptedHistory = tickets_history($withAcceptedId);
$withAcceptedView = ticket_public_view($withAcceptedTicket);

check('accepted_at -> created_at zgłoszenia', ($withAcceptedTicket['created_at'] ?? '') === '2026-11-20 09:30:00', (string) ($withAcceptedTicket['created_at'] ?? ''));
check('accepted_at -> data wpisu historii', ($withAcceptedHistory[0]['created_at'] ?? '') === '2026-11-20 09:30:00', (string) ($withAcceptedHistory[0]['created_at'] ?? ''));
check('data przyjęcia widoczna u klienta', $withAcceptedView['received_date'] === '20.11.2026' && $withAcceptedView['received_time'] === '09:30', $withAcceptedView['received_date'] . ' ' . $withAcceptedView['received_time']);
check('data przyjęcia jako etap "Przyjęty"', ($withAcceptedView['steps'][0]['at'] ?? '') === '20.11.2026, 09:30', (string) ($withAcceptedView['steps'][0]['at'] ?? ''));
check('updated_at to czas synchronizacji, nie data przyjęcia', ($withAcceptedTicket['updated_at'] ?? '') !== ($withAcceptedTicket['created_at'] ?? ''));

$withoutAccepted = tickets_sync_external([
    'reference' => 'SRV-2026-000105',
    'phone' => '605 000 666',
    'device' => 'Acer Nitro 5',
    'issue' => 'Przegrzewa sie podczas pracy.',
    'status' => 'pending',
]);
$withoutAcceptedTicket = tickets_by_id((int) ($withoutAccepted['id'] ?? 0)) ?: [];
check('bez accepted_at zostaje czas synchronizacji', abs(strtotime((string) ($withoutAcceptedTicket['created_at'] ?? '')) - time()) < 120, (string) ($withoutAcceptedTicket['created_at'] ?? ''));

/* --- 5c. Zgłoszenie utworzone od razu jako gotowe do wydania ------------ */

$directReady = tickets_sync_external([
    'reference' => 'SRV-2026-000106',
    'phone' => '606 000 777',
    'device' => 'HP EliteBook 840',
    'issue' => 'Wymiana klawiatury, sprzet gotowy do odbioru.',
    'status' => 'ready_for_pickup',
    'accepted_at' => '2026-11-18 08:15:00',
]);
$directReadyId = (int) ($directReady['id'] ?? 0);
$directReadyTicket = tickets_by_id($directReadyId) ?: [];
$directReadyHistory = tickets_history($directReadyId);
$directReadyView = ticket_public_view($directReadyTicket);

check('utworzenie od razu jako done: oba SMS w kolejce', ($directReady['sms_queued'] ?? []) === ['client_accepted', 'client_ready'], json_encode($directReady['sms_queued'] ?? []));
check('historia: przyjęcie (data z aplikacji) + bieżący status', count($directReadyHistory) === 2
    && ($directReadyHistory[0]['status'] ?? '') === 'new'
    && ($directReadyHistory[1]['status'] ?? '') === 'done', json_encode(array_column($directReadyHistory, 'status')));
check('data przyjęcia w pierwszym wpisie historii', ($directReadyHistory[0]['created_at'] ?? '') === '2026-11-18 08:15:00', (string) ($directReadyHistory[0]['created_at'] ?? ''));
check('krok "Przyjęty" ma datę (nie "W trakcie naprawy")', ($directReadyView['steps'][0]['at'] ?? '') === '18.11.2026, 08:15', (string) ($directReadyView['steps'][0]['at'] ?? ''));
check('krok "Gotowy do wydania" ma datę', ($directReadyView['steps'][2]['at'] ?? '') !== '', (string) ($directReadyView['steps'][2]['at'] ?? ''));
check('etap klienta Gotowy do wydania po pierwszej synchronizacji', $directReadyView['stage'] === 'ready' && $directReadyView['stage_label'] === 'Gotowy do wydania', (string) $directReadyView['stage']);

// Stare dane (sprzed poprawki): zgłoszenie ma w historii tylko bieżący status,
// więc data "Przyjęty" może pochodzić wyłącznie z tickets.created_at.
db()->prepare('INSERT INTO tickets (number, external_ref, created_at, updated_at, name, phone, device, problem, source_page, status, client_token)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([
    'SRV-2026-000107',
    'SRV-2026-000107',
    '2026-11-17 07:45:00',
    now(),
    '',
    '+48606000888',
    'Dell Vostro 3520',
    'Usterka zgloszona telefonicznie.',
    'serwis',
    'done',
    'srvc-legacy-check',
]);
$legacySyncId = (int) db()->lastInsertId();
db()->prepare('INSERT INTO ticket_history (ticket_id, status, note, username, created_at) VALUES (?,?,?,?,?)')
    ->execute([$legacySyncId, 'done', 'Stan z aplikacji.', 'serwis', now()]);
$legacySyncView = ticket_public_view(tickets_by_id($legacySyncId) ?: []);
check('stare dane: krok "Przyjęty" ma datę z created_at', ($legacySyncView['steps'][0]['at'] ?? '') === '17.11.2026, 07:45', (string) ($legacySyncView['steps'][0]['at'] ?? ''));
check('stare dane: krok "Gotowy do wydania" ma datę z historii', ($legacySyncView['steps'][2]['at'] ?? '') !== '', (string) ($legacySyncView['steps'][2]['at'] ?? ''));

/* --- 6. Anulowanie ------------------------------------------------------ */

$cancelCreate = tickets_sync_external([
    'reference' => 'SRV-2026-000102',
    'phone' => '601 000 222',
    'device' => 'Dell Latitude 5420',
    'issue' => 'Klient zrezygnowal z naprawy.',
    'status' => 'pending',
]);
$cancelId = (int) ($cancelCreate['id'] ?? 0);
check('drugie zlecenie utworzone', !empty($cancelCreate['ok']) && $cancelId > 0);
$cancelled = tickets_sync_external([
    'reference' => 'SRV-2026-000102',
    'phone' => '601 000 222',
    'device' => 'Dell Latitude 5420',
    'issue' => 'Klient zrezygnowal z naprawy.',
    'status' => 'cancelled',
    'note' => 'Naprawa anulowana na prośbę klienta.',
]);
check('cancelled -> cancelled', ($cancelled['status'] ?? '') === 'cancelled', (string) ($cancelled['status'] ?? ''));
$cancelView = ticket_public_view(tickets_by_id($cancelId) ?: []);
$cancelKeys = array_column($cancelView['steps'], 'key');
check('etap klienta Anulowane', $cancelView['stage'] === 'cancelled' && $cancelView['stage_label'] === 'Anulowane');
check('widok anulowania bez etapu Odebrany', !in_array('picked_up', $cancelKeys, true), implode(',', $cancelKeys));
check('widok anulowania bez etapu Gotowy do wydania', !in_array('ready', $cancelKeys, true));
check('JSON anulowania z etapem cancelled', str_contains((string) json_encode(['ok' => true, 'status' => $cancelView]), '"stage":"cancelled"'));

/* --- 7. Tokeny: nowy format i zgodność ze starym ------------------------ */

$shortToken = tickets_tracking_token($syncedId);
check('nowy token: base36.base36.22 znaki', preg_match('/^[0-9a-z]{1,7}\.[0-9a-z]{1,7}\.[A-Za-z0-9_-]{22}$/', $shortToken) === 1, $shortToken);
check('nowy token krótszy niż 45 znaków', strlen($shortToken) < 45, (string) strlen($shortToken));
check('nowy token odnajduje zgłoszenie', tickets_find_by_tracking_token($shortToken) !== null);
$tampered = substr($shortToken, 0, -1) . (str_ends_with($shortToken, 'A') ? 'B' : 'A');
check('podrobiony krótki token odrzucony', tickets_find_by_tracking_token($tampered) === null);

$version = (int) (tickets_by_id($syncedId)['status_token_version'] ?? 0);
$legacyToken = $syncedId . '.' . $version . '.' . tickets_tracking_mac($syncedId, $version);
check('stary token 43-znakowy nadal działa', tickets_find_by_tracking_token($legacyToken) !== null, $legacyToken);
check('stary token z inną wersją odrzucony', tickets_find_by_tracking_token($syncedId . '.' . ($version - 1) . '.' . tickets_tracking_mac($syncedId, $version)) === null);
foreach (['', 'abc', '1.2.3', '../../config.php', str_repeat('A', 200)] as $bad) {
    check('odrzucony token: ' . mb_substr($bad, 0, 24, 'UTF-8'), tickets_find_by_tracking_token($bad) === null);
}

/* --- 8. Adresy linków: status_base_url ---------------------------------- */

$token = tickets_tracking_token($syncedId);
check('krótki link z status_base_url', tickets_tracking_url($token) === 'https://status.mati-it.pl/' . $token, tickets_tracking_url($token));

// Bez status_base_url wracamy do dotychczasowego adresu - stare SMS-y i nginx bez zmian.
$config['status_base_url'] = '';
check('bez status_base_url link /status/<token>/', tickets_tracking_url($token) === 'https://sk.mati-it.pl/status/' . $token . '/', tickets_tracking_url($token));

// Token z roota jest przyjmowany tylko na hoście z status_base_url.
$config['status_base_url'] = 'https://status.mati-it.pl';
$_SERVER['HTTP_HOST'] = 'status.mati-it.pl';
$_SERVER['REQUEST_URI'] = '/' . $token;
check('token w rootcie na hoście statusu', tickets_tracking_token_from_request() === $token, tickets_tracking_token_from_request());
$_SERVER['REQUEST_URI'] = '/' . $token . '/';
check('token w rootcie z ukośnikiem', tickets_tracking_token_from_request() === $token);
$_SERVER['HTTP_HOST'] = 'sk.mati-it.pl';
$_SERVER['REQUEST_URI'] = '/' . $token;
check('root na innym hoście nie jest tokenem', tickets_tracking_token_from_request() === '');
$_SERVER['REQUEST_URI'] = '/panel/zgloszenia';
check('ścieżka panelu nie jest tokenem', tickets_tracking_token_from_request() === '');
$_SERVER['REQUEST_URI'] = '/status/' . $token . '/';
check('stary adres /status/ nadal działa', tickets_tracking_token_from_request() === $token);
$_GET = ['t' => $token];
$_SERVER['REQUEST_URI'] = '/?t=' . $token;
check('parametr ?t= nadal działa', tickets_tracking_token_from_request() === $token);
$_GET = [];
$_SERVER['REQUEST_URI'] = '/';

/* --- 9. Limit tempa ----------------------------------------------------- */

check('limit tempa przepuszcza pierwsze żądanie', tickets_sync_rate_ok(1, 60) === true);
check('limit tempa blokuje kolejne', tickets_sync_rate_ok(1, 60) === false);

/* --- 10. Zgłoszenia z formularza nadal działają ------------------------- */

$formA = tickets_create([
    'name' => 'Formularz A',
    'phone' => '602 000 111',
    'device' => 'HP ProBook 450',
    'problem' => 'Zgłoszenie z formularza - sprawdzenie indeksu external_ref.',
    'source_page' => '/kontakt/',
    'consent' => 1,
], 'selfcheck-form-a-' . time());
$formB = tickets_create([
    'name' => 'Formularz B',
    'phone' => '602 000 112',
    'device' => 'Acer Aspire 5',
    'problem' => 'Drugie zgłoszenie z formularza - wiele NULL-i w external_ref.',
    'source_page' => '/kontakt/',
    'consent' => 1,
], 'selfcheck-form-b-' . time());
check('dwa zgłoszenia z formularza (NULL external_ref)', !empty($formA['ok']) && !empty($formB['ok']), json_encode([$formA, $formB], JSON_UNESCAPED_UNICODE));

/* --- 11. Migracja istniejącej bazy (stary schemat bez external_ref) ------ */

// Schemat `tickets` z wersji przed integracją - kolumny dodaje db_ensure_column.
$legacyFile = $tmp . '/legacy.sqlite';
$legacy = new PDO('sqlite:' . $legacyFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$legacy->exec('CREATE TABLE tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    number TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    name TEXT NOT NULL,
    phone TEXT NOT NULL,
    device TEXT NOT NULL DEFAULT \'\',
    problem TEXT NOT NULL,
    source_page TEXT NOT NULL DEFAULT \'\',
    ip_hash TEXT NOT NULL DEFAULT \'\',
    user_agent TEXT NOT NULL DEFAULT \'\',
    status TEXT NOT NULL DEFAULT \'new\',
    consent_at TEXT NOT NULL DEFAULT \'\',
    consent_version TEXT NOT NULL DEFAULT \'\',
    client_token TEXT NOT NULL DEFAULT \'\',
    archived INTEGER NOT NULL DEFAULT 0,
    notes TEXT NOT NULL DEFAULT \'\',
    public_note TEXT NOT NULL DEFAULT \'\',
    status_token_version INTEGER NOT NULL DEFAULT 0
)');
$legacy->exec("INSERT INTO tickets (number, created_at, updated_at, name, phone, device, problem, client_token)
    VALUES ('SK-2026-000001','2026-11-01 10:00:00','2026-11-01 10:00:00','Ala','+48600100200','Lenovo','Usterka','legacy-token-a')");
$legacy->exec("INSERT INTO tickets (number, created_at, updated_at, name, phone, device, problem, client_token)
    VALUES ('SK-2026-000002','2026-11-02 10:00:00','2026-11-02 10:00:00','Ola','+48600100201','Dell','Usterka','legacy-token-b')");

db_init($legacy);

$legacyColumns = array_column($legacy->query('PRAGMA table_info(tickets)')->fetchAll(), 'name');
check('migracja: kolumna external_ref dodana', in_array('external_ref', $legacyColumns, true), implode(',', $legacyColumns));
$legacyIndexes = array_column($legacy->query('PRAGMA index_list(tickets)')->fetchAll(), 'name');
check('migracja: unikalny indeks external_ref utworzony', in_array('idx_tickets_external_ref', $legacyIndexes, true), implode(',', $legacyIndexes));
check('migracja: stare zgłoszenia nietknięte', (int) $legacy->query('SELECT COUNT(*) FROM tickets')->fetchColumn() === 2);
check('migracja: dwa NULL-e w external_ref dozwolone', (int) $legacy->query('SELECT COUNT(*) FROM tickets WHERE external_ref IS NULL')->fetchColumn() === 2);

$legacy->exec("UPDATE tickets SET external_ref = 'SRV-2026-000900' WHERE number = 'SK-2026-000001'");
$duplicateRejected = false;
try {
    $legacy->exec("UPDATE tickets SET external_ref = 'SRV-2026-000900' WHERE number = 'SK-2026-000002'");
} catch (PDOException $e) {
    $duplicateRejected = true;
}
check('migracja: duplikat external_ref odrzucony przez indeks', $duplicateRejected);
$legacy = null;

/* --- 12. Endpoint HTTP: 503 bez sekretu -------------------------------- */

$server = self_check_server($servers, $configNoSecretFile, $panelDir, $tmp . '/server.log');
if ($server === null) {
    skip('endpoint HTTP bez sekretu', 'nie udało się uruchomić wbudowanego serwera PHP');
} else {
    $response = sync_request($server, $secret, $basePayload);
    check('brak sekretu -> 503', $response['status'] === 503, 'HTTP ' . $response['status']);
    check('brak sekretu -> integration_disabled', ($response['json']['error'] ?? '') === 'integration_disabled', $response['body']);
    check('komunikat 503 nie ujawnia konfiguracji', !str_contains($response['body'], 'service_sync_secret') && !str_contains($response['body'], $secret), $response['body']);
    self_check_server_stop($server);
    $servers = array_values(array_filter($servers, static fn ($item) => $item['port'] !== $server['port']));
}

/* --- 13. Endpoint HTTP: pełna ścieżka ---------------------------------- */

$server = self_check_server($servers, $configFile, $panelDir, $tmp . '/server.log');
if ($server === null) {
    skip('endpoint HTTP (podpisany)', 'nie udało się uruchomić wbudowanego serwera PHP');
} else {
    $payload = [
        'reference' => 'SRV-2026-000103',
        'phone' => '603 000 444',
        'device' => 'Asus ZenBook 14',
        'issue' => 'Brak obrazu na matrycy po aktualizacji systemu.',
        'status' => 'pending',
        'note' => 'Zlecenie przyjęte w aplikacji.',
    ];
    $response = sync_request($server, $secret, $payload);
    check('poprawny podpis -> 200', $response['status'] === 200, 'HTTP ' . $response['status'] . ' ' . $response['body']);
    check('odpowiedź ok', ($response['json']['ok'] ?? false) === true, $response['body']);
    check('odpowiedź: numer = reference', ($response['json']['number'] ?? '') === 'SRV-2026-000103');
    $httpTicket = tickets_find_by_external_ref('SRV-2026-000103') ?: [];
    check(
        'odpowiedź: link do statusu',
        ($response['json']['url'] ?? '') === 'https://status.mati-it.pl/' . tickets_tracking_token((int) ($httpTicket['id'] ?? 0))
    );
    check('odpowiedź: kolejka client_accepted', ($response['json']['sms_queued'] ?? []) === ['client_accepted'], $response['body']);
    $headersFlat = implode("\n", $response['headers']);
    check('nagłówek Cache-Control: no-store', str_contains($headersFlat, 'cache-control: no-store'), $headersFlat);
    check('nagłówek X-Content-Type-Options: nosniff', str_contains($headersFlat, 'x-content-type-options: nosniff'));

    $repeatResponse = sync_request($server, $secret, $payload);
    check('powtórka przez HTTP -> 200 z tym samym numerem', $repeatResponse['status'] === 200 && ($repeatResponse['json']['number'] ?? '') === 'SRV-2026-000103');
    check('powtórka przez HTTP nie kolejkuje SMS', ($repeatResponse['json']['sms_queued'] ?? []) === [], $repeatResponse['body']);

    $badSignature = sync_request($server, $secret, $payload, null, 'nieprawidlowy-podpis');
    check('zły podpis -> 401', $badSignature['status'] === 401, 'HTTP ' . $badSignature['status'] . ' ' . $badSignature['body']);
    check('zły podpis -> invalid_signature', ($badSignature['json']['error'] ?? '') === 'invalid_signature', $badSignature['body']);

    $stale = sync_request($server, $secret, $payload, time() - 400);
    check('stary timestamp -> 401', $stale['status'] === 401, 'HTTP ' . $stale['status']);
    check('stary timestamp -> stale_timestamp', ($stale['json']['error'] ?? '') === 'stale_timestamp', $stale['body']);

    $unknownStatus = sync_request($server, $secret, array_merge($payload, ['status' => 'nie_ma_takiego']));
    check('nieznany status -> 400', $unknownStatus['status'] === 400, 'HTTP ' . $unknownStatus['status']);
    check('nieznany status -> invalid_status', ($unknownStatus['json']['error'] ?? '') === 'invalid_status', $unknownStatus['body']);

    $unknownField = sync_request($server, $secret, array_merge($payload, ['cost' => 100]));
    check('nieznane pole -> 400', $unknownField['status'] === 400 && ($unknownField['json']['error'] ?? '') === 'unknown_field', $unknownField['body']);

    $badType = sync_request($server, $secret, $payload, null, null, 'text/plain');
    check('zły Content-Type -> 400', $badType['status'] === 400 && ($badType['json']['error'] ?? '') === 'invalid_content_type', $badType['body']);

    $getResponse = http_request('GET', 'http://127.0.0.1:' . $server['port'] . '/api/service-sync.php');
    check('GET -> 405', $getResponse['status'] === 405, 'HTTP ' . $getResponse['status']);
    check('GET -> Allow: POST', in_array('allow: post', $getResponse['headers'], true), implode(' | ', $getResponse['headers']));

    $tooLargeTs = (string) time();
    $tooLarge = http_request('POST', 'http://127.0.0.1:' . $server['port'] . '/api/service-sync.php', str_repeat('x', 25000), [
        'Content-Type: application/json',
        'X-SK-Timestamp: ' . $tooLargeTs,
        'X-SK-Signature: ' . tickets_sync_signature($secret, $tooLargeTs, str_repeat('x', 25000)),
    ]);
    check('zbyt duże ciało -> 400', $tooLarge['status'] === 400 && ($tooLarge['json']['error'] ?? '') === 'payload_too_large', 'HTTP ' . $tooLarge['status'] . ' ' . $tooLarge['body']);

    // Gotowość zgłoszenia utworzonego przez HTTP: done + client_ready.
    $readyResponse = sync_request($server, $secret, array_merge($payload, ['status' => 'ready_for_pickup', 'note' => 'Gotowy do odbioru.']));
    check('HTTP ready_for_pickup -> done', $readyResponse['status'] === 200 && ($readyResponse['json']['status'] ?? '') === 'done', $readyResponse['body']);
    check('HTTP ready_for_pickup -> client_ready', ($readyResponse['json']['sms_queued'] ?? []) === ['client_ready'], $readyResponse['body']);
}

/* --- podsumowanie ------------------------------------------------------ */

foreach ($servers as $server) {
    self_check_server_stop($server);
}

// Kolejka nie może nic wysłać: worker nie był uruchamiany, tryb testowy bez wysyłki.
check('żadna wiadomość nie została wysłana (bez workera)', count(array_filter(
    array_column(db()->query('SELECT status FROM notifications')->fetchAll(), 'status'),
    static fn ($s) => $s === 'sent'
)) === 0);

$cleanup = $tmp . '/data';
foreach (glob($cleanup . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cleanup);
foreach (glob($tmp . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($tmp);

echo "Sprawdzone warunki: {$checks}\n";
if ($skipped) {
    echo 'Pominięte (' . count($skipped) . "):\n";
    foreach ($skipped as $entry) {
        echo ' - ' . $entry . "\n";
    }
}
if ($failures) {
    echo 'BŁĘDY (' . count($failures) . "):\n";
    foreach ($failures as $failure) {
        echo ' - ' . $failure . "\n";
    }
    exit(1);
}

echo "OK - podpis, idempotencja, mapowanie statusów, SMS, krótkie tokeny i endpoint HTTP działają zgodnie z oczekiwaniami.\n";
