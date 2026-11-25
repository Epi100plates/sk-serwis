<?php
/**
 * Minimalny klient SMTP (bez composera i bez bibliotek zewnętrznych):
 * połączenie przez socket, SSL lub STARTTLS, AUTH LOGIN, nagłówki UTF-8,
 * treść HTML + plain. Konfiguracja wyłącznie z tabeli settings panelu.
 * Brak konfiguracji = powiadomienie e-mail jest pomijane (status "skipped").
 */

/**
 * Konfiguracja poczty z bazy panelu (bez logowania hasła).
 */
function mailer_config(): array
{
    $secure = strtolower(trim(setting_get('smtp_secure', 'tls')));
    if (!in_array($secure, ['none', 'tls', 'ssl'], true)) {
        $secure = 'tls';
    }
    return [
        'enabled' => setting_get('mail_enabled', '0') === '1',
        'host' => trim(setting_get('smtp_host')),
        'port' => max(1, min(65535, (int) setting_get('smtp_port', '587'))),
        'secure' => $secure,
        'user' => trim(setting_get('smtp_user')),
        'pass' => (string) setting_get('smtp_pass'),
        'from' => trim(setting_get('smtp_from')),
        'from_name' => trim(setting_get('smtp_from_name')) ?: 'Studio-Komp',
        'to' => trim(setting_get('smtp_to')),
    ];
}

/** Czy poczta jest w ogóle skonfigurowana do wysyłki. */
function mailer_configured(): bool
{
    $cfg = mailer_config();
    return $cfg['enabled']
        && $cfg['host'] !== ''
        && $cfg['from'] !== ''
        && filter_var($cfg['from'], FILTER_VALIDATE_EMAIL) !== false;
}

function mailer_valid_email(string $email): bool
{
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Wysyła wiadomość e-mail przez SMTP.
 *
 * @return array{ok:bool,skipped?:bool,error?:string,delivery_status?:string}
 */
function mailer_send(string $to, string $subject, string $html, string $text): array
{
    $cfg = mailer_config();

    if (!$cfg['enabled']) {
        return ['ok' => false, 'skipped' => true, 'error' => 'Powiadomienia e-mail są wyłączone w ustawieniach.'];
    }
    if ($cfg['host'] === '' || $cfg['from'] === '') {
        return ['ok' => false, 'skipped' => true, 'error' => 'Brak konfiguracji SMTP (host/nadawca).'];
    }
    if (!mailer_valid_email($cfg['from'])) {
        return ['ok' => false, 'skipped' => true, 'error' => 'Nieprawidłowy adres nadawcy w konfiguracji SMTP.'];
    }
    if (!mailer_valid_email($to)) {
        return ['ok' => false, 'skipped' => true, 'error' => 'Brak poprawnego adresu odbiorcy powiadomień.'];
    }

    $subject = preg_replace('/[\r\n]+/', ' ', $subject) ?? $subject;
    $fromName = preg_replace('/[\r\n]+/', ' ', $cfg['from_name']) ?? $cfg['from_name'];
    $domain = (string) parse_url((string) cfg('site_url'), PHP_URL_HOST) ?: 'localhost';
    $boundary = 'sk-' . bin2hex(random_bytes(12));

    $headers = [
        'Date: ' . date('r'),
        'From: ' . mailer_encode_header($fromName) . ' <' . $cfg['from'] . '>',
        'To: <' . $to . '>',
        'Subject: ' . mailer_encode_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: Studio-Komp panel',
    ];

    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . mailer_dot_stuff($text) . "\r\n\r\n--" . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . mailer_dot_stuff($html) . "\r\n\r\n--" . $boundary . "--\r\n";

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

    $port = $cfg['port'];
    $scheme = $cfg['secure'] === 'ssl' ? 'ssl://' : 'tcp://';
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($scheme . $cfg['host'] . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return ['ok' => false, 'error' => 'SMTP: nie można połączyć się z serwerem ' . $cfg['host'] . ':' . $port . ' (' . mb_substr((string) $errstr, 0, 120, 'UTF-8') . ')'];
    }
    stream_set_timeout($fp, 15);

    $fail = static function (string $message) use ($fp): array {
        @fclose($fp);
        return ['ok' => false, 'error' => $message];
    };

    [$code, $response] = mailer_smtp_read($fp);
    if ($code !== 220) {
        return $fail('SMTP: serwer nie przywitał sesji (kod ' . $code . ').');
    }

    $helo = 'EHLO ' . $domain;
    [$ok, $code] = mailer_smtp_command($fp, $helo, [250]);
    if (!$ok) {
        return $fail('SMTP: odrzucono EHLO (kod ' . $code . ').');
    }

    if ($cfg['secure'] === 'tls') {
        [$ok, $code] = mailer_smtp_command($fp, 'STARTTLS', [220]);
        if (!$ok) {
            return $fail('SMTP: serwer nie zgodził się na STARTTLS (kod ' . $code . ').');
        }
        $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($crypto !== true) {
            return $fail('SMTP: nie udało się ustanowić szyfrowania TLS.');
        }
        [$ok, $code] = mailer_smtp_command($fp, $helo, [250]);
        if (!$ok) {
            return $fail('SMTP: odrzucono EHLO po STARTTLS (kod ' . $code . ').');
        }
    }

    if ($cfg['user'] !== '') {
        [$ok, $code] = mailer_smtp_command($fp, 'AUTH LOGIN', [334]);
        if (!$ok) {
            return $fail('SMTP: serwer nie zgodził się na logowanie AUTH LOGIN (kod ' . $code . ').');
        }
        [$ok, $code] = mailer_smtp_command($fp, base64_encode($cfg['user']), [334]);
        if (!$ok) {
            return $fail('SMTP: odrzucono nazwę użytkownika (kod ' . $code . ').');
        }
        [$ok, $code] = mailer_smtp_command($fp, base64_encode($cfg['pass']), [235]);
        if (!$ok) {
            return $fail('SMTP: nie udało się zalogować (kod ' . $code . ').');
        }
    }

    [$ok, $code] = mailer_smtp_command($fp, 'MAIL FROM:<' . $cfg['from'] . '>', [250]);
    if (!$ok) {
        return $fail('SMTP: odrzucono nadawcę (kod ' . $code . ').');
    }
    [$ok, $code] = mailer_smtp_command($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
    if (!$ok) {
        return $fail('SMTP: odrzucono odbiorcę (kod ' . $code . ').');
    }
    [$ok, $code] = mailer_smtp_command($fp, 'DATA', [354]);
    if (!$ok) {
        return $fail('SMTP: serwer nie przyjął DATA (kod ' . $code . ').');
    }

    fwrite($fp, $message . "\r\n.\r\n");
    [$code] = mailer_smtp_read($fp);
    if ($code !== 250) {
        return $fail('SMTP: wiadomość odrzucona (kod ' . $code . ').');
    }

    mailer_smtp_command($fp, 'QUIT', [221]);
    @fclose($fp);

    return ['ok' => true, 'delivery_status' => 'sent'];
}

/** Nagłówek z kodowaniem UTF-8 (base64) - poprawne polskie znaki w temacie. */
function mailer_encode_header(string $value): string
{
    $value = trim(preg_replace('/[\r\n]+/', ' ', $value) ?? $value);
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/** Kropka na początku linii musi być zdublowana (RFC 5321). */
function mailer_dot_stuff(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/^\./m', '..', $body) ?? $body;
    return str_replace("\n", "\r\n", $body);
}

/** Odczyt odpowiedzi SMTP (z obsługą wielolinijkowych odpowiedzi). */
function mailer_smtp_read($fp): array
{
    $response = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    $code = (int) substr($response, 0, 3);
    return [$code, $response];
}

/** Wysłanie komendy SMTP i sprawdzenie kodu odpowiedzi. */
function mailer_smtp_command($fp, string $command, array $expected): array
{
    fwrite($fp, $command . "\r\n");
    [$code] = mailer_smtp_read($fp);
    return [in_array($code, $expected, true), $code];
}
