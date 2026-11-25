<?php
/**
 * Własny, pierwszostronny pomiar: zbieranie zdarzeń i pageview oraz zapytania
 * do dashboardu. Bez fingerprintingu, bez pełnych adresów IP.
 */

function analytics_is_bot(string $ua): bool
{
    if ($ua === '') {
        return true;
    }
    return (bool) preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headless|python-requests|curl|wget|monitor|uptime|lighthouse/i', $ua);
}

function analytics_device(string $ua): string
{
    $ua = strtolower($ua);
    if (preg_match('/ipad|tablet|kindle|playbook|silk/', $ua)) {
        return 'tablet';
    }
    if (preg_match('/mobile|iphone|android.*mobile|windows phone|opera mini/', $ua)) {
        return 'mobile';
    }
    if (preg_match('/android/', $ua)) {
        return 'tablet';
    }
    return 'desktop';
}

function analytics_source(array $input, string $referrerHost): array
{
    $utmSource = strtolower(str_limit((string) ($input['utm_source'] ?? ''), 80));
    $utmMedium = strtolower(str_limit((string) ($input['utm_medium'] ?? ''), 80));
    $utmCampaign = strtolower(str_limit((string) ($input['utm_campaign'] ?? ''), 120));
    $gclid = (string) ($input['gclid'] ?? '');

    if ($gclid !== '' || $utmMedium === 'cpc' || $utmMedium === 'ppc' || in_array($utmSource, ['googleads', 'google_ads', 'adwords'], true)) {
        $source = 'google_ads';
    } elseif ($utmSource !== '') {
        if (in_array($utmSource, ['facebook', 'instagram', 'fb', 'ig', 'linkedin', 'tiktok', 'youtube'], true)) {
            $source = 'social';
        } elseif (in_array($utmSource, ['google', 'bing', 'duckduckgo'], true) && in_array($utmMedium, ['organic', ''], true)) {
            $source = 'google_organic';
        } else {
            $source = 'campaign';
        }
    } elseif ($referrerHost !== '') {
        if (preg_match('/(^|\.)google\./', $referrerHost)) {
            $source = 'google_organic';
        } elseif (preg_match('/(facebook|instagram|linkedin|tiktok|youtube|twitter|x\.com)/', $referrerHost)) {
            $source = 'social';
        } else {
            $source = 'referral';
        }
    } else {
        $source = 'direct';
    }

    return [
        'source' => $source,
        'medium' => $utmMedium,
        'campaign' => $utmCampaign,
        'utm_source' => $utmSource,
    ];
}

function analytics_visitor_id(): string
{
    $cookie = $_COOKIE['sk_vid'] ?? '';
    if (preg_match('/^[a-f0-9]{16}$/', $cookie)) {
        return $cookie;
    }
    return '';
}

function analytics_rate_ok(): bool
{
    $window = (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
    $st = db()->prepare('SELECT COUNT(*) FROM events WHERE created_at >= ? AND page = ?');
    $st->execute([$window, '']);
    return true; // limit egzekwowany niżej w record_pageview po IP-hashu
}

function analytics_record_pageview(array $input): void
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (analytics_is_bot($ua)) {
        return;
    }
    $ipHash = security_ip_hash();
    // Rate limit: max 120 odsłon / 10 min z jednego IP-hash (ochrona przed botami)
    $since = (new DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s');
    $st = db()->prepare('SELECT COUNT(*) FROM pageviews WHERE created_at >= ? AND visitor_id = ?');
    $st->execute([$since, $ipHash]);
    if ((int) $st->fetchColumn() > 120) {
        return;
    }

    $path = str_limit((string) ($input['path'] ?? '/'), 300);
    if ($path === '' || $path[0] !== '/') {
        $path = '/';
    }
    $referrer = (string) ($input['referrer'] ?? '');
    $referrerHost = $referrer ? strtolower((string) parse_url($referrer, PHP_URL_HOST)) : '';
    if ($referrerHost && $referrerHost === strtolower((string) parse_url((string) cfg('site_url'), PHP_URL_HOST))) {
        $referrerHost = '';
    }

    $source = analytics_source($input, $referrerHost);
    $device = analytics_device($ua);
    $visitorId = analytics_visitor_id();
    $isNew = 0;
    if ($visitorId === '') {
        $visitorId = bin2hex(random_bytes(8));
        $isNew = 1;
        setcookie('sk_vid', $visitorId, [
            'expires' => time() + 365 * 86400,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        $st = db()->prepare('SELECT COUNT(*) FROM pageviews WHERE visitor_id = ?');
        $st->execute([$visitorId]);
        $isNew = ((int) $st->fetchColumn() === 0) ? 1 : 0;
    }

    $sessionId = $_COOKIE['sk_sid'] ?? '';
    if (!preg_match('/^[a-f0-9]{16}$/', $sessionId)) {
        $sessionId = bin2hex(random_bytes(8));
        setcookie('sk_sid', $sessionId, [
            'expires' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $st = db()->prepare('INSERT INTO pageviews (created_at, page, device, source, medium, campaign, referrer_host, visitor_id, session_id, is_new_visitor) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $st->execute([
        now(), $path, $device, $source['source'], $source['medium'], $source['campaign'],
        $referrerHost, $visitorId, $sessionId, $isNew,
    ]);
}

function analytics_record_event(array $input): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (analytics_is_bot($ua)) {
        return false;
    }
    $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($input['name'] ?? '')));
    if ($name === '') {
        return false;
    }
    $allowed = ['phone_click', 'directions_click', 'map_click', 'service_click', 'price_click', 'review_click',
        'realization_click', 'article_click', 'contact_form_start', 'contact_form_submit', 'CTA_click',
        'gallery_open', 'before_after_interaction', 'video_play', 'generate_lead', 'cases_filter', 'pageview'];
    if (!in_array($name, $allowed, true)) {
        return false;
    }

    // Rate limit per IP-hash: max N zdarzeń na minutę
    $since = (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
    $ipHash = security_ip_hash();
    $st = db()->prepare('SELECT COUNT(*) FROM events WHERE created_at >= ? AND visitor_id = ?');
    $st->execute([$since, $ipHash]);
    if ((int) $st->fetchColumn() > (int) cfg('analytics_max_events_per_minute', 60)) {
        return false;
    }

    $page = str_limit((string) ($input['page'] ?? '/'), 300);
    if ($page === '' || $page[0] !== '/') {
        $page = '/';
    }
    $referrer = (string) ($input['referrer'] ?? '');
    $referrerHost = $referrer ? strtolower((string) parse_url($referrer, PHP_URL_HOST)) : '';
    if ($referrerHost && $referrerHost === strtolower((string) parse_url((string) cfg('site_url'), PHP_URL_HOST))) {
        $referrerHost = '';
    }
    $source = analytics_source($input, $referrerHost);
    $meta = $input['meta'] ?? [];
    $metaJson = is_array($meta) ? json_encode(array_slice($meta, 0, 12), JSON_UNESCAPED_UNICODE) : '{}';
    if (strlen($metaJson) > 1000) {
        $metaJson = '{}';
    }

    $st = db()->prepare('INSERT INTO events (created_at, name, page, location, target, element, device, source, medium, campaign, referrer_host, visitor_id, session_id, meta) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([
        now(), $name,
        $page,
        str_limit((string) ($input['location'] ?? ''), 80),
        str_limit((string) ($input['target'] ?? ''), 300),
        str_limit((string) ($input['element'] ?? ''), 120),
        analytics_device($ua),
        $source['source'],
        $source['medium'],
        $source['campaign'],
        $referrerHost,
        analytics_visitor_id(),
        $_COOKIE['sk_sid'] ?? '',
        $metaJson,
    ]);
    return true;
}

/* ------------------------------------------------------------- raporty */

function analytics_bounds(string $from, string $to): array
{
    return [$from . ' 00:00:00', $to . ' 23:59:59'];
}

function analytics_scalar(string $table, string $expr, string $from, string $to, array $where = []): float
{
    $sql = "SELECT $expr FROM $table WHERE created_at BETWEEN ? AND ?";
    $params = [$from, $to];
    foreach ($where as $key => $value) {
        $sql .= " AND $key = ?";
        $params[] = $value;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    return (float) $st->fetchColumn();
}

function analytics_summary(string $from, string $to): array
{
    $events = ['phone_click' => 0, 'directions_click' => 0, 'map_click' => 0, 'contact_form_submit' => 0,
        'generate_lead' => 0, 'CTA_click' => 0, 'service_click' => 0, 'price_click' => 0,
        'review_click' => 0, 'realization_click' => 0, 'article_click' => 0, 'gallery_open' => 0,
        'before_after_interaction' => 0, 'video_play' => 0, 'contact_form_start' => 0];
    $st = db()->prepare('SELECT name, COUNT(*) AS c FROM events WHERE created_at BETWEEN ? AND ? GROUP BY name');
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $row) {
        $events[$row['name']] = (int) $row['c'];
    }

    return [
        'pageviews' => (int) analytics_scalar('pageviews', 'COUNT(*)', $from, $to),
        'visitors' => (int) analytics_scalar('pageviews', 'COUNT(DISTINCT visitor_id)', $from, $to),
        'sessions' => (int) analytics_scalar('pageviews', 'COUNT(DISTINCT session_id)', $from, $to),
        'new_visitors' => (int) analytics_scalar('pageviews', 'COALESCE(SUM(is_new_visitor),0)', $from, $to),
        'events' => $events,
        'conversions' => $events['generate_lead'] + $events['contact_form_submit'],
    ];
}

function analytics_series(string $from, string $to): array
{
    $days = [];
    $start = new DateTimeImmutable(substr($from, 0, 10));
    $end = new DateTimeImmutable(substr($to, 0, 10));
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        $days[$d->format('Y-m-d')] = ['pageviews' => 0, 'visitors' => 0, 'phone' => 0, 'directions' => 0, 'forms' => 0, 'conversions' => 0, 'mobile' => 0, 'desktop' => 0, 'tablet' => 0];
    }

    $st = db()->prepare("SELECT substr(created_at,1,10) AS d, COUNT(*) c, COUNT(DISTINCT visitor_id) v, SUM(CASE WHEN device='mobile' THEN 1 ELSE 0 END) m, SUM(CASE WHEN device='desktop' THEN 1 ELSE 0 END) de, SUM(CASE WHEN device='tablet' THEN 1 ELSE 0 END) t FROM pageviews WHERE created_at BETWEEN ? AND ? GROUP BY d");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $row) {
        $d = $row['d'];
        if (!isset($days[$d])) {
            $days[$d] = ['pageviews' => 0, 'visitors' => 0, 'phone' => 0, 'directions' => 0, 'forms' => 0, 'conversions' => 0, 'mobile' => 0, 'desktop' => 0, 'tablet' => 0];
        }
        $days[$d]['pageviews'] = (int) $row['c'];
        $days[$d]['visitors'] = (int) $row['v'];
        $days[$d]['mobile'] = (int) $row['m'];
        $days[$d]['desktop'] = (int) $row['de'];
        $days[$d]['tablet'] = (int) $row['t'];
    }

    $st = db()->prepare("SELECT substr(created_at,1,10) AS d, name, COUNT(*) c FROM events WHERE created_at BETWEEN ? AND ? GROUP BY d, name");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $row) {
        if (!isset($days[$row['d']])) {
            continue;
        }
        $map = ['phone_click' => 'phone', 'directions_click' => 'directions', 'contact_form_submit' => 'forms', 'generate_lead' => 'conversions'];
        if (isset($map[$row['name']])) {
            $days[$row['d']][$map[$row['name']]] += (int) $row['c'];
        }
    }

    return array_map(fn($d, $v) => ['date' => $d] + $v, array_keys($days), array_values($days));
}

function analytics_devices(string $from, string $to): array
{
    $out = [];
    foreach (['mobile', 'desktop', 'tablet', 'other'] as $device) {
        $where = $device === 'other' ? "device NOT IN ('mobile','desktop','tablet')" : 'device = ?';
        $params = [$from, $to];
        if ($device !== 'other') {
            $params[] = $device;
        }
        $st = db()->prepare("SELECT COUNT(*) pv, COUNT(DISTINCT visitor_id) visitors, COUNT(DISTINCT session_id) sessions FROM pageviews WHERE created_at BETWEEN ? AND ? AND $where");
        $st->execute($params);
        $row = $st->fetch() ?: ['pv' => 0, 'visitors' => 0, 'sessions' => 0];
        $events = ['phone_click' => 0, 'directions_click' => 0, 'contact_form_submit' => 0, 'generate_lead' => 0];
        $st = db()->prepare("SELECT name, COUNT(*) c FROM events WHERE created_at BETWEEN ? AND ? AND $where GROUP BY name");
        $st->execute($params);
        foreach ($st->fetchAll() as $e) {
            if (isset($events[$e['name']])) {
                $events[$e['name']] = (int) $e['c'];
            }
        }
        $out[$device] = [
            'pageviews' => (int) $row['pv'],
            'visitors' => (int) $row['visitors'],
            'sessions' => (int) $row['sessions'],
            'events' => $events,
            'conversions' => $events['generate_lead'] + $events['contact_form_submit'],
        ];
    }
    return $out;
}

function analytics_top_pages(string $from, string $to, int $limit = 20, string $order = 'pageviews'): array
{
    $orderSql = $order === 'conversions'
        ? 'conversions DESC'
        : ($order === 'phone' ? 'phone DESC' : 'pageviews DESC');
    $st = db()->prepare("
        SELECT p.page,
               COUNT(*) AS pageviews,
               COUNT(DISTINCT p.visitor_id) AS visitors
        FROM pageviews p
        WHERE p.created_at BETWEEN ? AND ?
        GROUP BY p.page
        ORDER BY pageviews DESC
        LIMIT " . (int) $limit);
    $st->execute([$from, $to]);
    $rows = $st->fetchAll();

    foreach ($rows as &$row) {
        $st = db()->prepare("SELECT name, COUNT(*) c FROM events WHERE created_at BETWEEN ? AND ? AND page = ? GROUP BY name");
        $st->execute([$from, $to, $row['page']]);
        $ev = ['phone_click' => 0, 'directions_click' => 0, 'contact_form_submit' => 0, 'generate_lead' => 0];
        foreach ($st->fetchAll() as $e) {
            if (isset($ev[$e['name']])) {
                $ev[$e['name']] = (int) $e['c'];
            }
        }
        $row['phone'] = $ev['phone_click'];
        $row['directions'] = $ev['directions_click'];
        $row['forms'] = $ev['contact_form_submit'];
        $row['conversions'] = $ev['generate_lead'] + $ev['contact_form_submit'];
        $row['label'] = analytics_page_label($row['page']);
        $sources = db()->prepare("SELECT source, COUNT(*) c FROM pageviews WHERE created_at BETWEEN ? AND ? AND page = ? GROUP BY source ORDER BY c DESC LIMIT 1");
        $sources->execute([$from, $to, $row['page']]);
        $row['top_source'] = $sources->fetchColumn() ?: 'direct';
    }
    unset($row);

    usort($rows, function ($a, $b) use ($orderSql) {
        if (str_starts_with($orderSql, 'phone')) {
            return $b['phone'] <=> $a['phone'];
        }
        if (str_starts_with($orderSql, 'conversions')) {
            return $b['conversions'] <=> $a['conversions'];
        }
        return $b['pageviews'] <=> $a['pageviews'];
    });
    return $rows;
}

function analytics_page_label(string $path): string
{
    $map = [
        '/' => 'Strona główna',
        '/cennik/' => 'Cennik',
        '/opinie/' => 'Opinie',
        '/poradnik/' => 'Poradnik',
        '/realizacje/' => 'Realizacje',
        '/kontakt/' => 'Kontakt',
        '/uslugi/' => 'Usługi',
        '/o-serwisie/' => 'O serwisie',
    ];
    return $map[$path] ?? $path;
}

function analytics_sources(string $from, string $to): array
{
    $st = db()->prepare("SELECT source, medium, campaign, COUNT(*) pageviews, COUNT(DISTINCT visitor_id) visitors FROM pageviews WHERE created_at BETWEEN ? AND ? GROUP BY source, medium, campaign ORDER BY pageviews DESC LIMIT 30");
    $st->execute([$from, $to]);
    $rows = $st->fetchAll();

    foreach ($rows as &$row) {
        $st = db()->prepare("SELECT name, COUNT(*) c FROM events WHERE created_at BETWEEN ? AND ? AND source = ? GROUP BY name");
        $st->execute([$from, $to, $row['source']]);
        $ev = ['phone_click' => 0, 'directions_click' => 0, 'generate_lead' => 0];
        foreach ($st->fetchAll() as $e) {
            if (isset($ev[$e['name']])) {
                $ev[$e['name']] = (int) $e['c'];
            }
        }
        $row['phone'] = $ev['phone_click'];
        $row['directions'] = $ev['directions_click'];
        $row['conversions'] = $ev['generate_lead'];
    }
    unset($row);
    return $rows;
}

function analytics_events_table(string $from, string $to): array
{
    $st = db()->prepare("SELECT name, COUNT(*) c, COUNT(DISTINCT visitor_id) visitors, COUNT(DISTINCT page) pages FROM events WHERE created_at BETWEEN ? AND ? GROUP BY name ORDER BY c DESC");
    $st->execute([$from, $to]);
    return $st->fetchAll();
}

function analytics_compare(string $from1, string $to1, string $from2, string $to2): array
{
    $a = analytics_summary($from1, $to1);
    $b = analytics_summary($from2, $to2);
    $pct = fn($old, $new) => $old > 0 ? round((($new - $old) / $old) * 100, 1) : ($new > 0 ? 100.0 : 0.0);
    return [
        'current' => $a,
        'previous' => $b,
        'change' => [
            'pageviews' => $pct($b['pageviews'], $a['pageviews']),
            'visitors' => $pct($b['visitors'], $a['visitors']),
            'phone' => $pct($b['events']['phone_click'], $a['events']['phone_click']),
            'directions' => $pct($b['events']['directions_click'], $a['events']['directions_click']),
            'forms' => $pct($b['events']['contact_form_submit'], $a['events']['contact_form_submit']),
            'conversions' => $pct($b['conversions'], $a['conversions']),
        ],
    ];
}

function analytics_cleanup(): int
{
    $days = (int) cfg('analytics_retention_days', 400);
    $cutoff = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');
    $a = db()->prepare('DELETE FROM pageviews WHERE created_at < ?');
    $a->execute([$cutoff]);
    $b = db()->prepare('DELETE FROM events WHERE created_at < ?');
    $b->execute([$cutoff]);
    $c = db()->prepare('DELETE FROM login_attempts WHERE created_at < ?');
    $c->execute([(new DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s')]);
    return $a->rowCount() + $b->rowCount();
}
