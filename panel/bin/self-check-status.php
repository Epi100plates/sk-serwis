<?php
/**
 * Samokontrola publicznej strony statusu naprawy: tokeny śledzenia, mapowanie
 * etapów klienta, kolejka SMS do klienta i model widoku.
 *
 * Działa na tymczasowym katalogu danych, więc nie dotyka bazy produkcyjnej
 * ani nie wysyła żadnych wiadomości (tryb testowy SMS jest domyślny).
 * Uruchomienie (CLI): php panel/bin/self-check-status.php
 * Kod wyjścia 0 = OK.
 */

if (PHP_SAPI !== 'cli') {
    exit('Skrypt CLI.');
}

$tmp = sys_get_temp_dir() . '/sk-status-check-' . getmypid();
@mkdir($tmp . '/data', 0770, true);

$repoDir = dirname(__DIR__, 2);
$config = [
    'repo_dir' => $repoDir,
    'data_dir' => $tmp . '/data',
    'uploads_dir' => $repoDir . '/public/images/uploads',
    'releases_dir' => $tmp . '/releases',
    'webroot' => $repoDir . '/public',
    'preview_dir' => $tmp . '/preview',
    'site_url' => 'https://sk.mati-it.pl',
    'panel_path' => '/panel',
];
$configFile = $tmp . '/config.php';
file_put_contents($configFile, "<?php\nreturn " . var_export($config, true) . ";\n");
putenv('SK_PANEL_CONFIG=' . $configFile);

$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) SelfCheck/1.0';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require dirname(__DIR__) . '/api/_init.php';

$failures = [];
$checks = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures[] = $label . ($detail !== '' ? ' [' . $detail . ']' : '');
    }
}

/* --- 1. Mapowanie statusów bazy na etapy klienta ------------------------- */

check('każdy status ma etap klienta', count(array_diff(array_keys(TICKET_STATUSES), array_keys(CLIENT_STAGE_BY_STATUS))) === 0);
check('cztery etapy z etykietami', count(CLIENT_STAGES) === 4);
check('new -> Przyjęty', ticket_client_stage('new') === 'accepted');
check('read -> Przyjęty', ticket_client_stage('read') === 'accepted');
check('in_progress -> W trakcie naprawy', ticket_client_stage('in_progress') === 'in_progress');
check('done -> Gotowy do wydania', ticket_client_stage('done') === 'ready');
check('closed -> Odebrany', ticket_client_stage('closed') === 'picked_up');
check('nieznany status nie wywraca strony', ticket_client_stage('cokolwiek') === 'accepted');

/* --- 2. Zgłoszenie, token i link ---------------------------------------- */

$created = tickets_create([
    'name' => 'Anna',
    'phone' => '600 100 200',
    'device' => 'Lenovo ThinkPad T480',
    'problem' => 'Laptop nie ładuje się, sprawdzić gniazdo zasilania.',
    'source_page' => '/kontakt/',
    'consent' => 1,
], 'selfcheck' . time());
check('zgłoszenie utworzone', !empty($created['ok']) && !empty($created['id']), json_encode($created, JSON_UNESCAPED_UNICODE));
$ticketId = (int) ($created['id'] ?? 0);
$ticket = tickets_by_id($ticketId);
check('zgłoszenie odczytane', $ticket !== null);

$link = tickets_tracking_link($ticket ?? []);
check('link prowadzi do /status/', str_contains($link, 'https://sk.mati-it.pl/status/'), $link);
$token = tickets_tracking_token_from_request();
check('brak tokenu w żądaniu bez /status/', $token === '');

$parts = [];
preg_match('~/status/([^/]+)/~', $link, $parts);
$rawToken = $parts[1] ?? '';
check('token ma format id.wersja.podpis', preg_match('/^\d+\.\d+\.[A-Za-z0-9_-]{43}$/', $rawToken) === 1, $rawToken);
$found = tickets_find_by_tracking_token($rawToken);
check('token odnajduje zgłoszenie', $found !== null && (int) $found['id'] === $ticketId);

$tampered = substr($rawToken, 0, -1) . (str_ends_with($rawToken, 'A') ? 'B' : 'A');
check('podrobiony podpis odrzucony', $tampered !== $rawToken && tickets_find_by_tracking_token($tampered) === null);
$versionParts = explode('.', $rawToken);
$versionParts[1] = (string) ((int) $versionParts[1] - 1);
check('stara wersja tokenu odrzucona', tickets_find_by_tracking_token(implode('.', $versionParts)) === null);

foreach (['', 'abc', '1.2.3', '../../config.php', "1'; DROP TABLE tickets;--", str_repeat('A', 200)] as $bad) {
    check('odrzucony token: ' . mb_substr($bad, 0, 24, 'UTF-8'), tickets_find_by_tracking_token($bad) === null);
}

/* --- 3. Unieważnianie linku --------------------------------------------- */

$newToken = tickets_tracking_token($ticketId, true);
check('nowy token różni się od starego', $newToken !== $rawToken);
check('stary token po unieważnieniu nie działa', tickets_find_by_tracking_token($rawToken) === null);
check('nowy token działa', tickets_find_by_tracking_token($newToken) !== null);
$ticket = tickets_by_id($ticketId) ?? [];

/* --- 4. Kolejka SMS: przyjęcie i gotowość ------------------------------- */

$notifications = tickets_notifications($ticketId);
$clientAccepted = array_values(array_filter($notifications, static fn ($n) => $n['template'] === 'client_accepted'));
check('SMS o przyjęciu w kolejce', count($clientAccepted) === 1, (string) count($clientAccepted));
check('SMS do klienta na jego numer', ($clientAccepted[0]['recipient'] ?? '') === '+48600100200', (string) ($clientAccepted[0]['recipient'] ?? ''));
check('SMS o przyjęciu w trybie testowym', ($clientAccepted[0]['status'] ?? '') === 'pending', (string) ($clientAccepted[0]['status'] ?? ''));
check('SMS wewnętrzny do serwisu nadal kolejkowany', count(array_filter($notifications, static fn ($n) => $n['template'] === 'new_ticket')) === 2);

$acceptedMessage = tickets_client_sms_message($ticket, 'client_accepted');
check('treść SMS o przyjęciu zawiera link', str_contains($acceptedMessage, '/status/'), $acceptedMessage);
check('treść SMS bez polskich znaków diakrytycznych', preg_match('/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u', $acceptedMessage) === 0, $acceptedMessage);
check('treść SMS mieści się w 459 znakach', mb_strlen($acceptedMessage, 'UTF-8') <= 459);

tickets_change_status($ticketId, 'in_progress', 'Diagnoza w toku.', 'selfcheck');
check('SMS o gotowości nie leci za wcześnie', count(array_filter(tickets_notifications($ticketId), static fn ($n) => $n['template'] === 'client_ready')) === 0);

tickets_change_status($ticketId, 'done', 'Testy zakończone.', 'selfcheck');
$ready = array_values(array_filter(tickets_notifications($ticketId), static fn ($n) => $n['template'] === 'client_ready'));
check('SMS o gotowości po statusie done', count($ready) === 1, (string) count($ready));

tickets_change_status($ticketId, 'done', 'Powtórzenie.', 'selfcheck');
check('powtórny status done nie dubluje SMS', count(array_filter(tickets_notifications($ticketId), static fn ($n) => $n['template'] === 'client_ready')) === 1);

$processed = tickets_process_queue();
check('worker przetworzył kolejkę', ($processed['processed'] ?? 0) >= 3, json_encode($processed));
$ready = array_values(array_filter(tickets_notifications($ticketId), static fn ($n) => $n['template'] === 'client_ready'));
check('worker nie łączył się z dostawcą (tryb testowy)', ($ready[0]['status'] ?? '') === 'test', (string) ($ready[0]['status'] ?? ''));

$ticket = tickets_by_id($ticketId) ?? [];

/* --- 5. Model widoku ---------------------------------------------------- */

ticket_set_public_note($ticketId, 'Wymieniamy matrycę, część dojedzie w czwartek.');
$ticket = tickets_by_id($ticketId) ?? [];
$view = ticket_public_view($ticket);

check('etap widoku to ready', $view['stage'] === 'ready', (string) $view['stage']);
check('etykieta etapu', $view['stage_label'] === 'Gotowy do wydania', (string) $view['stage_label']);
check('indeks etapu 3 z 3', $view['stage_index'] === 3 && $view['stage_total'] === 3);
check('trzy kroki w timeline', count($view['steps']) === 3);
check('stany kroków done/done/current', implode(',', array_column($view['steps'], 'state')) === 'done,done,current', implode(',', array_column($view['steps'], 'state')));
check('daty kroków wypełnione', array_reduce($view['steps'], static fn ($c, $s) => $c && $s['at'] !== '', true));
check('notatka publiczna widoczna', $view['public_note'] === 'Wymieniamy matrycę, część dojedzie w czwartek.');
check('numer zgłoszenia bez zmian', $view['number'] === (string) ($ticket['number'] ?? ''));
check('dane serwisu z site.json', ($view['service']['name'] ?? '') === 'Studio-Komp' && ($view['service']['phone'] ?? '') === '500 613 500', json_encode($view['service'], JSON_UNESCAPED_UNICODE));

$publicJson = (string) json_encode($view, JSON_UNESCAPED_UNICODE);
foreach (['Anna', '600100200', '600 100 200', '+48600100200', 'Laptop nie ładuje'] as $secret) {
    check('brak danych osobowych w widoku: ' . $secret, !str_contains($publicJson, $secret));
}
check('notatka wewnętrzna nie trafia do widoku', !array_key_exists('notes', $view));

/* --- 6. Limit tempa ----------------------------------------------------- */

check('limit tempa przepuszcza pierwsze żądanie', ticket_status_rate_ok(1, 60) === true);
check('limit tempa blokuje kolejne', ticket_status_rate_ok(1, 60) === false);

/* --- podsumowanie ------------------------------------------------------- */

$cleanup = $tmp . '/data';
foreach (glob($cleanup . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cleanup);
@rmdir($tmp);

echo "Sprawdzone warunki: {$checks}\n";
if ($failures) {
    echo 'BŁĘDY (' . count($failures) . "):\n";
    foreach ($failures as $failure) {
        echo ' - ' . $failure . "\n";
    }
    exit(1);
}

echo "OK - tokeny, etapy, kolejka SMS i widok strony statusu działają zgodnie z oczekiwaniami.\n";
