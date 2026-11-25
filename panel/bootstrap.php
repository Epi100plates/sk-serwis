<?php
/**
 * Bootstrap panelu: konfiguracja, sesja, baza, helpery, ochrona nagłówków.
 * Panel działa wyłącznie po HTTPS (Cloudflare Tunnel) - ciasteczka Secure.
 */

declare(strict_types=1);

const SK_PANEL_ROOT = __DIR__;

$configCandidates = [
    getenv('SK_PANEL_CONFIG') ?: '',
    '/srv/studio-komp-cms/config.php',
    dirname(__DIR__) . '/panel-config.php',
    __DIR__ . '/config.php',
];

$config = null;
foreach ($configCandidates as $candidate) {
    if ($candidate && is_file($candidate)) {
        $config = require $candidate;
        break;
    }
}
if (!is_array($config)) {
    http_response_code(500);
    exit('Brak konfiguracji panelu (/srv/studio-komp-cms/config.php).');
}

function cfg(string $key, $default = null)
{
    global $config;
    return $config[$key] ?? $default;
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_iso(): string
{
    return (new DateTimeImmutable('now'))->format('c');
}

function base_path(): string
{
    $path = rtrim((string) cfg('panel_path', '/panel'), '/');
    return $path === '' ? '' : $path;
}

function panel_url(string $page = '', array $query = []): string
{
    $url = base_path() . '/' . ltrim($page, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

function redirect_to(string $url): void
{
    header('Location: ' . $url, true, 302);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, $default = '')
{
    return $_POST[$key] ?? $default;
}

function get(string $key, $default = '')
{
    return $_GET[$key] ?? $default;
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function str_limit(string $value, int $limit): string
{
    return mb_substr(trim($value), 0, $limit, 'UTF-8');
}

function daterange_input(): array
{
    $tz = new DateTimeZone('Europe/Warsaw');
    $to = get('to') ?: (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $from = get('from') ?: (new DateTimeImmutable('-29 days', $tz))->format('Y-m-d');
    try {
        $fromDate = new DateTimeImmutable($from, $tz);
        $toDate = new DateTimeImmutable($to, $tz);
    } catch (Throwable $e) {
        $fromDate = new DateTimeImmutable('-29 days', $tz);
        $toDate = new DateTimeImmutable('now', $tz);
    }
    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }
    return [
        'from' => $fromDate->format('Y-m-d'),
        'to' => $toDate->format('Y-m-d'),
        'from_dt' => $fromDate->setTime(0, 0, 0),
        'to_dt' => $toDate->setTime(23, 59, 59),
    ];
}

/* --------------------------------------------------------- config sanity */

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
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https://www.google-analytics.com; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/content.php';
require_once __DIR__ . '/lib/publish.php';
require_once __DIR__ . '/lib/media.php';
require_once __DIR__ . '/lib/analytics.php';
require_once __DIR__ . '/lib/redirects.php';
require_once __DIR__ . '/lib/reviews.php';
require_once __DIR__ . '/lib/tickets.php';
require_once __DIR__ . '/lib/sms.php';
require_once __DIR__ . '/lib/mailer.php';

if (PHP_SAPI !== 'cli') {
    auth_start_session();
}

// Indeksuj bazę przy pierwszym żądaniu
db();
