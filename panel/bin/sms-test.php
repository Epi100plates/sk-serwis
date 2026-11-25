<?php
/**
 * Test wysyłki SMS z konfiguracji panelu - do sprawdzenia dostawcy po
 * wyłączeniu trybu testowego. Skrypt NIE wysyła nic bez flagi --send.
 *
 * Użycie (CLI, na VPS jako użytkownik panelu):
 *   runuser -u skcms -- php /srv/studio-komp/panel/bin/sms-test.php --to=600100200
 *   runuser -u skcms -- php /srv/studio-komp/panel/bin/sms-test.php --to=600100200 --send
 *
 * Bez --send: pokazuje konfigurację (dostawca, tryb testowy, czy jest token)
 * i treść przykładowego SMS-a, ale nic nie wysyła i nie zapisuje w bazie.
 * Z --send: wysyła jedną wiadomość-test (nie dotyczy zgłoszeń) i wypisuje
 * odpowiedź dostawcy. Kod wyjścia 0 = sukces albo tryb testowy.
 */

if (PHP_SAPI !== 'cli') {
    exit('Skrypt CLI.');
}

$configFile = getenv('SK_PANEL_CONFIG') ?: '/srv/studio-komp-cms/config.php';
putenv('SK_PANEL_CONFIG=' . $configFile);
require dirname(__DIR__) . '/api/_init.php';

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

if (isset($args['help'])) {
    echo "Użycie: php sms-test.php --to=NUMER [--send]\n";
    echo "  --to    numer odbiorcy testu (9 cyfr lub +48...)\n";
    echo "  --send  naprawdę wyślij (bez tej flagi tylko podgląd)\n";
    exit(0);
}

$to = is_string($args['to'] ?? null) ? (string) $args['to'] : '';
if ($to === '') {
    fwrite(STDERR, "Podaj numer: --to=600100200\n");
    exit(1);
}

$sms = tickets_sms_config();
$message = 'Studio-Komp: test wiadomosci z panelu. Jesli ja widzisz, wysylka SMS dziala.';

echo 'Konfiguracja: ', PHP_EOL;
echo '  dostawca:       ', $sms['provider'], PHP_EOL;
echo '  tryb testowy:   ', $sms['test_mode'] ? 'TAK (nic nie zostanie wyslane)' : 'NIE (realna wysylka)', PHP_EOL;
echo '  token API:      ', $sms['token'] !== '' ? 'ustawiony' : 'BRAK', PHP_EOL;
echo '  nadawca:        ', $sms['sender'] !== '' ? $sms['sender'] : '(domyslny dostawcy)', PHP_EOL;
echo '  odbiorca testu: ', $to, PHP_EOL;
echo '  tresc:          ', $message, PHP_EOL;

if (!isset($args['send'])) {
    echo PHP_EOL, 'Podglad - nic nie wyslano. Dodaj --send, zeby wyslac.', PHP_EOL;
    exit(0);
}

if ($sms['test_mode']) {
    echo PHP_EOL, 'Tryb testowy wlaczony - wylacz go w panelu (Ustawienia -> Formularz), zeby wyslac realnie.', PHP_EOL;
    exit(0);
}
if ($sms['token'] === '') {
    fwrite(STDERR, PHP_EOL . "Brak tokenu API dostawcy SMS w ustawieniach." . PHP_EOL);
    exit(1);
}

$result = sms_send($to, $message, $sms);
if (empty($result['ok'])) {
    fwrite(STDERR, PHP_EOL . 'BLAD: ' . (string) ($result['error'] ?? 'nieznany') . PHP_EOL);
    exit(1);
}

echo PHP_EOL, 'Wyslano. ID u dostawcy: ', (string) ($result['provider_message_id'] ?? '-'),
    ', status: ', (string) ($result['delivery_status'] ?? '-'), PHP_EOL;
exit(0);
