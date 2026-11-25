<?php
/**
 * Lekka inicjalizacja publicznego API formularza (bez sesji panelu).
 * Ładuje tylko konfigurację, bazę i biblioteki zgłoszeń - nie uruchamia
 * sesji panelu, 2FA ani routingu CMS.
 */

declare(strict_types=1);

if (!defined('SK_PANEL_ROOT')) {
    define('SK_PANEL_ROOT', dirname(__DIR__));
}

$configCandidates = [
    getenv('SK_PANEL_CONFIG') ?: '',
    '/srv/studio-komp-cms/config.php',
    dirname(SK_PANEL_ROOT) . '/panel-config.php',
    SK_PANEL_ROOT . '/config.php',
];

$config = null;
foreach ($configCandidates as $candidate) {
    if ($candidate && is_file($candidate)) {
        $config = require $candidate;
        break;
    }
}

if (!is_array($config)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":false,"errors":{"form":"Serwis chwilowo niedostępny."}}';
    }
    exit(1);
}

if (!function_exists('cfg')) {
    function cfg(string $key, $default = null)
    {
        global $config;
        return $config[$key] ?? $default;
    }
}

date_default_timezone_set('Europe/Warsaw');
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$logDir = (string) cfg('data_dir') . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0770, true);
}
ini_set('error_log', $logDir . '/php-error.log');

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Robots-Tag: noindex, nofollow');
}

require_once SK_PANEL_ROOT . '/lib/db.php';
require_once SK_PANEL_ROOT . '/lib/tickets.php';
require_once SK_PANEL_ROOT . '/lib/sms.php';
require_once SK_PANEL_ROOT . '/lib/mailer.php';

/** Odpowiedź JSON i zakończenie żądania publicznego API. */
function public_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Czy klient oczekuje odpowiedzi JSON (fetch/XHR/API). */
function public_wants_json(): bool
{
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        return true;
    }
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return str_contains($accept, 'application/json');
}

/** Czy żądanie pochodzi z naszej strony (Origin/Referer zgodne z site_url). */
function public_same_origin(): bool
{
    $siteHost = (string) parse_url((string) cfg('site_url'), PHP_URL_HOST);
    if ($siteHost === '') {
        return false;
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && $origin !== 'null') {
        $host = (string) parse_url($origin, PHP_URL_HOST);
        return $host !== '' && strcasecmp($host, $siteHost) === 0;
    }
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer !== '') {
        $host = (string) parse_url($referer, PHP_URL_HOST);
        return $host !== '' && strcasecmp($host, $siteHost) === 0;
    }
    return false;
}

/** Czy połączenie jest HTTPS (także za Cloudflare Tunnel). */
function public_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

// Inicjalizacja tabel (idempotentna) przy pierwszym żądaniu API.
db();
