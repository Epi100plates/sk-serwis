<?php
/**
 * Uwierzytelnianie, sesje, CSRF, limity logowania, 2FA (TOTP).
 */

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name(cfg('session_name', 'sk_panel'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => cfg('panel_path', '/panel'),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return $user = null;
    }
    $st = db()->prepare('SELECT id, username, role, totp_enabled, last_login_at FROM users WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        session_destroy();
        return $user = null;
    }
    return $user = $row;
}

function require_login(): void
{
    if (!current_user()) {
        $next = $_SERVER['REQUEST_URI'] ?? '/panel/';
        header('Location: ' . panel_url('login', ['next' => $next]));
        exit;
    }

    // Wylogowanie po bezczynności + rotacja identyfikatora sesji
    $now = time();
    $last = (int) ($_SESSION['last_seen'] ?? $now);
    if ($now - $last > (int) cfg('idle_timeout', 1800)) {
        auth_logout();
        flash('info', 'Sesja wygasła z powodu bezczynności. Zaloguj się ponownie.');
        header('Location: ' . panel_url('login'));
        exit;
    }
    $_SESSION['last_seen'] = $now;

    $rotated = (int) ($_SESSION['rotated_at'] ?? 0);
    if ($now - $rotated > (int) cfg('session_rotate', 900)) {
        session_regenerate_id(true);
        $_SESSION['rotated_at'] = $now;
    }
}

function auth_login(string $username, string $password, ?string $totp = null): array
{
    $username = trim($username);
    $ipHash = security_ip_hash();
    $window = (int) cfg('login_window', 900);

    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ip_hash = ? AND success = 0 AND created_at >= ?');
    $st->execute([$username, $ipHash, (new DateTimeImmutable("-{$window} seconds"))->format('Y-m-d H:i:s')]);
    $recentFails = (int) $st->fetchColumn();

    if ($recentFails >= (int) cfg('login_max_attempts', 5)) {
        return ['ok' => false, 'error' => 'Zbyt wiele prób logowania. Odczekaj kilka minut i spróbuj ponownie.'];
    }

    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $user = $st->fetch();

    $ok = $user && password_verify($password, $user['password_hash']);

    if ($ok && $user['totp_enabled'] && cfg('totp_enabled', true)) {
        if (!$totp || !totp_verify((string) $user['totp_secret'], $totp)) {
            $ok = false;
            $error = 'Nieprawidłowy kod 2FA.';
        }
    }

    $log = db()->prepare('INSERT INTO login_attempts (username, ip_hash, success, created_at) VALUES (?,?,?,?)');
    $log->execute([$username, $ipHash, $ok ? 1 : 0, now()]);

    if (!$ok) {
        if ($user) {
            db()->prepare('UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?')->execute([$user['id']]);
        }
        return ['ok' => false, 'error' => $error ?? 'Nieprawidłowy login lub hasło.'];
    }

    auth_start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_seen'] = time();
    $_SESSION['rotated_at'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    db()->prepare('UPDATE users SET failed_attempts = 0, last_login_at = ?, last_login_ip = ? WHERE id = ?')
        ->execute([now(), $ipHash, $user['id']]);

    audit('login', $username);
    return ['ok' => true, 'user' => $user];
}

function auth_logout(): void
{
    if (function_exists('audit') && current_user()) {
        audit('logout', current_user()['username']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || $token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Nieprawidłowy token CSRF. Odśwież stronę i spróbuj ponownie.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/* ------------------------------------------------------------------ 2FA */

function base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $bits = '';
    for ($i = 0, $len = strlen($b32); $i < $len; $i++) {
        $bits .= str_pad(decbin(strpos($alphabet, $b32[$i])), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $bytes .= chr(bindec(substr($bits, $i, 8)));
    }
    return $bytes;
}

function totp_code(string $secret, ?int $timeSlice = null): string
{
    $timeSlice = $timeSlice ?? (int) floor(time() / 30);
    $key = base32_decode($secret);
    $time = pack('N*', 0) . pack('N*', $timeSlice);
    $hash = hash_hmac('sha1', $time, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $value = substr($hash, $offset, 4);
    $number = unpack('N', $value)[1] & 0x7FFFFFFF;
    return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
}

function totp_verify(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) {
        return false;
    }
    $slice = (int) floor(time() / 30);
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(totp_code($secret, $slice + $i), $code)) {
            return true;
        }
    }
    return false;
}

function totp_new_secret(): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 32; $i++) {
        $secret .= $alphabet[random_int(0, 31)];
    }
    return $secret;
}

function totp_uri(string $secret, string $username, string $issuer = 'Studio-Komp'): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $username)
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}
