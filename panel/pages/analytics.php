<?php
/**
 * Analityka: przegląd ruchu, podstrony, źródła, zdarzenia i urządzenia.
 * Wszystkie liczby pochodzą z własnego, pierwszo-stronnego pomiaru (endpoint /api/e).
 * Widok tylko czyta dane - brak POST, brak zmian w bazie.
 */

if (!function_exists('layout_head')) {
    require_once dirname(__DIR__) . '/views/layout.php';
}

/* ---------------------------------------------------------- pomocnicze */

/**
 * Bezpieczne pobranie parametru GET jako string (tablice/obiekty → wartość domyślna).
 */
function ana_get_string(string $key, string $default = ''): string
{
    $value = get($key, $default);
    if (is_array($value) || is_object($value)) {
        return $default;
    }
    return is_scalar($value) ? (string) $value : $default;
}

function ana_num($value): string
{
    return number_format((float) $value, 0, ',', ' ');
}

function ana_short_date(string $date): string
{
    if ($date === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($date))->format('d.m');
    } catch (Throwable $e) {
        return $date;
    }
}

function ana_days_label(int $days): string
{
    return $days === 1 ? '1 dzień' : $days . ' dni';
}

/**
 * Odmiana rzeczownika po liczbie (1 telefon, 2 telefony, 5 telefonów).
 */
function ana_plural(int $count, string $one, string $few, string $many): string
{
    if ($count === 1) {
        return $one;
    }
    $mod100 = $count % 100;
    $mod10 = $count % 10;
    if ($mod10 >= 2 && $mod10 <= 4 && !($mod100 >= 12 && $mod100 <= 14)) {
        return $few;
    }
    return $many;
}

function ana_pct_change($current, $previous): float
{
    $current = (float) $current;
    $previous = (float) $previous;
    if ($previous > 0) {
        return round((($current - $previous) / $previous) * 100, 1);
    }
    return $current > 0 ? 100.0 : 0.0;
}

function ana_change_html($change): string
{
    if ($change === null) {
        return '';
    }
    $value = (float) $change;
    $class = $value > 0 ? ' is-up' : ($value < 0 ? ' is-down' : '');
    $arrow = $value > 0 ? '↑' : ($value < 0 ? '↓' : '→');
    $sign = $value > 0 ? '+' : ($value < 0 ? '−' : '');
    return '<span class="kpi__change' . $class . '" title="Zmiana względem poprzedniego okresu o tej samej długości">'
        . $arrow . ' ' . $sign . number_format(abs($value), 1, ',', ' ') . '%</span>';
}

function ana_series_label(string $key): string
{
    $labels = [
        'pageviews' => 'Odsłony',
        'visitors' => 'Użytkownicy',
        'phone' => 'Kliknięcia telefonu',
        'directions' => 'Kliknięcia dojazdu',
        'forms' => 'Wysłane formularze',
        'conversions' => 'Konwersje',
        'mobile' => 'Telefon (mobile)',
        'desktop' => 'Komputer (desktop)',
        'tablet' => 'Tablet',
        'other' => 'Inne',
    ];
    return $labels[$key] ?? $key;
}

function ana_palette(int $index): string
{
    $palette = ['#2d343a', '#ffd400', '#1f5f99', '#1f7a3f', '#a15c00', '#b42318'];
    return $palette[$index % count($palette)];
}

function ana_device_label(string $device): string
{
    $labels = ['mobile' => 'Telefon', 'desktop' => 'Komputer', 'tablet' => 'Tablet', 'other' => 'Inne'];
    return $labels[$device] ?? $device;
}

function ana_device_color(string $device): string
{
    $colors = ['mobile' => '#2d343a', 'desktop' => '#ffd400', 'tablet' => '#1f5f99', 'other' => '#8b918a'];
    return $colors[$device] ?? '#8b918a';
}

function ana_source_label(string $source): string
{
    $labels = [
        'direct' => 'Bezpośredni',
        'google_organic' => 'Google Organic',
        'google_ads' => 'Google Ads',
        'social' => 'Social media',
        'referral' => 'Polecenia (linki)',
        'campaign' => 'Kampania UTM',
    ];
    return $labels[$source] ?? $source;
}

function ana_event_definitions(): array
{
    return [
        'phone_click' => ['Kliknięcie telefonu', 'Kliknięcie w numer telefonu. Nie oznacza zakończonej rozmowy - tylko intencję kontaktu.'],
        'directions_click' => ['Kliknięcie dojazdu', 'Otwarcie nawigacji lub mapy z adresem serwisu (np. Google Maps, Apple Maps).'],
        'map_click' => ['Kliknięcie mapy', 'Otwarcie mapy z lokalizacją serwisu (osadzona mapa lub link).'],
        'service_click' => ['Kliknięcie usługi', 'Wejście w kartę usługi z listy lub menu.'],
        'price_click' => ['Kliknięcie cennika', 'Przejście do cennika albo rozwinięcie szczegółów ceny.'],
        'review_click' => ['Kliknięcie opinii', 'Otwarcie opinii lub profilu Google z opiniami.'],
        'realization_click' => ['Kliknięcie realizacji', 'Wejście w szczegóły realizacji.'],
        'article_click' => ['Kliknięcie artykułu', 'Wejście w artykuł poradnika.'],
        'contact_form_start' => ['Rozpoczęcie formularza', 'Pierwsze wypełnione pole formularza kontaktowego.'],
        'contact_form_submit' => ['Wysłanie formularza', 'Potwierdzone wysłanie formularza kontaktowego (nie samo kliknięcie przycisku).'],
        'cta_click' => ['Kliknięcie CTA', 'Kliknięcie głównego przycisku akcji (np. „Zadzwoń", „Napisz").'],
        'gallery_open' => ['Otwarcie galerii', 'Powiększenie zdjęcia lub otwarcie galerii.'],
        'before_after_interaction' => ['Interakcja przed/po', 'Przesunięcie suwaka zdjęć przed/po.'],
        'video_play' => ['Odtworzenie wideo', 'Start odtwarzania materiału wideo.'],
        'generate_lead' => ['Wygenerowany lead', 'Potwierdzony lead zgłoszony przez integrację formularza (np. web3forms).'],
        'cases_filter' => ['Filtr realizacji', 'Użycie filtra w liście realizacji (kategoria, marka).'],
        'pageview' => ['Odsłona jako zdarzenie', 'Odsłona zgłoszona dodatkowo jako zdarzenie (rezerwa pomiaru).'],
    ];
}

/**
 * Buduje adres analityki, zachowując stan filtrów (zakres, zakładka, porównanie).
 * Wartość null w $overrides usuwa parametr.
 */
function ana_url(array $state, array $overrides = []): string
{
    $query = [
        'range' => (string) ($state['range'] ?? '30'),
        'from' => (string) ($state['from'] ?? ''),
        'to' => (string) ($state['to'] ?? ''),
        'tab' => (string) ($state['tab'] ?? 'overview'),
        'compare' => (string) ($state['compare'] ?? '0'),
    ];
    if ($query['tab'] === 'page' && !empty($state['page'])) {
        $query['page'] = (string) $state['page'];
    }
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
            continue;
        }
        $query[$key] = (string) $value;
    }
    if (($query['tab'] ?? '') !== 'page') {
        unset($query['page']);
    }
    if (($query['range'] ?? '') !== 'custom') {
        unset($query['from'], $query['to']);
    }
    $query = array_filter($query, static function ($value): bool {
        return $value !== '' && $value !== null;
    });
    return panel_url('analytics', $query);
}

/**
 * Skala osi Y: górny limit i krok siatki (liczby całkowite).
 */
function ana_nice_max(int $max): array
{
    if ($max <= 4) {
        return [4, 1];
    }
    $rawStep = $max / 4;
    $magnitude = 10 ** floor(log10($rawStep));
    if ($magnitude < 1) {
        $magnitude = 1.0;
    }
    $normalized = $rawStep / $magnitude;
    if ($normalized <= 1) {
        $nice = 1.0;
    } elseif ($normalized <= 2) {
        $nice = 2.0;
    } elseif ($normalized <= 2.5) {
        $nice = 2.5;
    } elseif ($normalized <= 5) {
        $nice = 5.0;
    } else {
        $nice = 10.0;
    }
    $step = (int) max(1, ceil($nice * $magnitude));
    $top = (int) (ceil($max / $step) * $step);
    return [$top, $step];
}

/**
 * Indeksy etykiet osi X - maksymalnie $max równo rozłożonych punktów.
 */
function ana_label_indexes(int $n, int $max = 6): array
{
    if ($n <= 0) {
        return [];
    }
    $count = max(1, min($max, $n));
    $indexes = [];
    for ($i = 0; $i < $count; $i++) {
        $indexes[] = $count === 1 ? 0 : (int) round($i * ($n - 1) / ($count - 1));
    }
    return array_values(array_unique($indexes));
}

/**
 * Wykres liniowy SVG (bez bibliotek) z siatką, osiami i punktami dla tooltipa.
 * $series: lista wierszy z kluczem 'date' oraz wartościami $keys; $colors: mapa klucz => kolor.
 */
function ana_line_chart(array $series, array $keys, array $colors, string $title): string
{
    $series = array_values($series);
    if (!$series) {
        return '<div class="empty">Brak danych do wykresu.</div>';
    }

    $total = 0;
    $max = 0;
    foreach ($series as $row) {
        foreach ($keys as $key) {
            $value = (int) ($row[$key] ?? 0);
            $total += $value;
            if ($value > $max) {
                $max = $value;
            }
        }
    }
    if ($total === 0) {
        return '<div class="empty">Brak danych w wybranym okresie.</div>';
    }

    $width = 900;
    $height = 220;
    $padL = 46;
    $padR = 16;
    $padT = 18;
    $padB = 28;
    $plotW = $width - $padL - $padR;
    $plotH = $height - $padT - $padB;
    $n = count($series);
    [$top, $step] = ana_nice_max($max);
    $dotR = $n > 120 ? 1.8 : 2.6;
    $hitR = $n > 120 ? 4.5 : 7;

    $xAt = static function (int $index) use ($padL, $plotW, $n): float {
        if ($n <= 1) {
            return $padL + $plotW / 2;
        }
        return $padL + ($index / ($n - 1)) * $plotW;
    };
    $yAt = static function (int $value) use ($padT, $plotH, $top): float {
        return $padT + $plotH - ($value / $top) * $plotH;
    };

    $markup = [];
    $markup[] = '<svg class="chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . e($title) . '">';

    // Siatka pozioma + etykiety osi Y.
    for ($tick = 0; $tick <= $top; $tick += $step) {
        $y = round($yAt($tick), 1);
        $markup[] = '<line x1="' . $padL . '" y1="' . $y . '" x2="' . ($padL + $plotW) . '" y2="' . $y . '" stroke="#e2e5df" stroke-width="1"/>';
        $markup[] = '<text x="' . ($padL - 8) . '" y="' . ($y + 4) . '" text-anchor="end" font-size="11" fill="#6b716b">' . e(ana_num($tick)) . '</text>';
    }

    // Etykiety osi X (daty).
    foreach (ana_label_indexes($n, 6) as $index) {
        $label = ana_short_date((string) ($series[$index]['date'] ?? ''));
        if ($label === '') {
            continue;
        }
        $markup[] = '<text x="' . round($xAt($index), 1) . '" y="' . ($height - 8) . '" text-anchor="middle" font-size="11" fill="#8b918a">' . e($label) . '</text>';
    }

    // Linie i punkty z tooltipem (data-chart-point obsługuje admin.js).
    foreach (array_values($keys) as $keyIndex => $key) {
        $color = $colors[$key] ?? ana_palette($keyIndex);
        $points = [];
        $dots = [];
        foreach ($series as $index => $row) {
            $value = (int) ($row[$key] ?? 0);
            $x = round($xAt($index), 1);
            $y = round($yAt($value), 1);
            $points[] = $x . ',' . $y;
            $tip = format_date((string) ($row['date'] ?? ''), false) . ' · ' . ana_series_label((string) $key) . ': ' . ana_num($value);
            $dots[] = '<circle cx="' . $x . '" cy="' . $y . '" r="' . $dotR . '" fill="' . e($color) . '"/>'
                . '<circle cx="' . $x . '" cy="' . $y . '" r="' . $hitR . '" fill="transparent" data-chart-point="' . e($tip) . '"/>';
        }
        $markup[] = '<polyline fill="none" stroke="' . e($color) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points="' . implode(' ', $points) . '"/>';
        $markup[] = implode('', $dots);
    }
    $markup[] = '</svg>';

    $legend = [];
    foreach (array_values($keys) as $keyIndex => $key) {
        $legend[] = '<span style="color:' . e($colors[$key] ?? ana_palette($keyIndex)) . '">' . e(ana_series_label((string) $key)) . '</span>';
    }

    $tip = '<div data-chart-tip hidden style="position:absolute;top:4px;right:4px;max-width:calc(100% - 16px);background:#2d343a;color:#fff;padding:4px 8px;border-radius:2px;font-size:11.5px;pointer-events:none;z-index:3"></div>';
    return '<div data-chart style="position:relative">' . implode('', $markup)
        . '<div class="chart-legend">' . implode('', $legend) . '</div>' . $tip . '</div>';
}

/**
 * Wykres słupkowy SVG (grupowany). Przy długich zakresach sumuje dni do ~31 grup,
 * żeby słupki pozostały czytelne i klikalne.
 */
function ana_bar_chart(array $series, array $keys, array $colors, string $title): string
{
    $series = array_values($series);
    if (!$series) {
        return '<div class="empty">Brak danych do wykresu.</div>';
    }

    $count = count($series);
    $bucketSize = $count > 31 ? (int) ceil($count / 31) : 1;
    $buckets = [];
    for ($i = 0; $i < $count; $i += $bucketSize) {
        $chunk = array_slice($series, $i, $bucketSize);
        $firstDate = (string) ($chunk[0]['date'] ?? '');
        $lastDate = (string) ($chunk[count($chunk) - 1]['date'] ?? '');
        $label = ana_short_date($firstDate);
        if (count($chunk) > 1 && $lastDate !== '') {
            $label .= '-' . ana_short_date($lastDate);
        }
        $row = ['label' => $label !== '' ? $label : '-'];
        foreach ($keys as $key) {
            $row[$key] = 0;
            foreach ($chunk as $day) {
                $row[$key] += (int) ($day[$key] ?? 0);
            }
        }
        $buckets[] = $row;
    }

    $total = 0;
    $max = 0;
    foreach ($buckets as $row) {
        foreach ($keys as $key) {
            $value = (int) ($row[$key] ?? 0);
            $total += $value;
            if ($value > $max) {
                $max = $value;
            }
        }
    }
    if ($total === 0) {
        return '<div class="empty">Brak danych w wybranym okresie.</div>';
    }

    $width = 900;
    $height = 220;
    $padL = 46;
    $padR = 16;
    $padT = 18;
    $padB = 28;
    $plotW = $width - $padL - $padR;
    $plotH = $height - $padT - $padB;
    $n = count($buckets);
    $k = max(1, count($keys));
    [$top, $step] = ana_nice_max($max);

    $yAt = static function (int $value) use ($padT, $plotH, $top): float {
        return $padT + $plotH - ($value / $top) * $plotH;
    };

    $groupW = $plotW / max(1, $n);
    $barGap = 2.0;
    $barW = ($groupW * 0.72 - $barGap * max(0, $k - 1)) / $k;
    $barW = max(1.5, min(26.0, $barW));
    $baseline = $padT + $plotH;

    $markup = [];
    $markup[] = '<svg class="chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . e($title) . '">';

    for ($tick = 0; $tick <= $top; $tick += $step) {
        $y = round($yAt($tick), 1);
        $markup[] = '<line x1="' . $padL . '" y1="' . $y . '" x2="' . ($padL + $plotW) . '" y2="' . $y . '" stroke="#e2e5df" stroke-width="1"/>';
        $markup[] = '<text x="' . ($padL - 8) . '" y="' . ($y + 4) . '" text-anchor="end" font-size="11" fill="#6b716b">' . e(ana_num($tick)) . '</text>';
    }

    foreach ($buckets as $index => $row) {
        $groupX = $padL + $index * $groupW;
        $barsTotal = $barW * $k + $barGap * max(0, $k - 1);
        $startX = $groupX + ($groupW - $barsTotal) / 2;
        foreach (array_values($keys) as $keyIndex => $key) {
            $value = (int) ($row[$key] ?? 0);
            $barHeight = ($value / $top) * $plotH;
            $x = round($startX + $keyIndex * ($barW + $barGap), 1);
            $y = round($baseline - $barHeight, 1);
            $tip = (string) $row['label'] . ' · ' . ana_series_label((string) $key) . ': ' . ana_num($value);
            $markup[] = '<rect x="' . $x . '" y="' . $y . '" width="' . round($barW, 1) . '" height="' . round(max(0.0, $barHeight), 1) . '" rx="1.5" fill="' . e($colors[$key] ?? ana_palette($keyIndex)) . '" data-chart-point="' . e($tip) . '"><title>' . e($tip) . '</title></rect>';
        }
    }

    foreach (ana_label_indexes($n, 6) as $index) {
        $markup[] = '<text x="' . round($padL + $index * $groupW + $groupW / 2, 1) . '" y="' . ($height - 8) . '" text-anchor="middle" font-size="11" fill="#8b918a">' . e((string) $buckets[$index]['label']) . '</text>';
    }
    $markup[] = '</svg>';

    $legend = [];
    foreach (array_values($keys) as $keyIndex => $key) {
        $legend[] = '<span style="color:' . e($colors[$key] ?? ana_palette($keyIndex)) . '">' . e(ana_series_label((string) $key)) . '</span>';
    }

    $tip = '<div data-chart-tip hidden style="position:absolute;top:4px;right:4px;max-width:calc(100% - 16px);background:#2d343a;color:#fff;padding:4px 8px;border-radius:2px;font-size:11.5px;pointer-events:none;z-index:3"></div>';
    return '<div data-chart style="position:relative">' . implode('', $markup)
        . '<div class="chart-legend">' . implode('', $legend) . '</div>' . $tip . '</div>';
}

/**
 * Poziomy pasek udziału urządzeń w odsłonach (+ legenda kolorów).
 */
function ana_device_bars(array $devices, int $total): string
{
    if ($total <= 0) {
        return '<div class="empty">Brak odsłon w wybranym okresie.</div>';
    }
    $rows = [];
    foreach (['mobile', 'desktop', 'tablet', 'other'] as $device) {
        $row = $devices[$device] ?? [];
        $pageviews = (int) ($row['pageviews'] ?? 0);
        if ($pageviews <= 0) {
            continue;
        }
        $share = round($pageviews / $total * 100, 1);
        $color = ana_device_color($device);
        $rows[] = '<div style="display:grid;gap:4px">'
            . '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;font-size:13px">'
            . '<span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' . e($color) . ';margin-right:7px"></span><strong>' . e(ana_device_label($device)) . '</strong></span>'
            . '<span class="muted small">' . e(ana_num($pageviews)) . ' odsłon · ' . e(number_format($share, 1, ',', ' ')) . '%</span>'
            . '</div>'
            . '<div style="height:8px;background:#eef0ec;border-radius:2px;overflow:hidden"><span style="display:block;height:8px;width:' . e((string) $share) . '%;background:' . e($color) . '"></span></div>'
            . '</div>';
    }
    if (!$rows) {
        return '<div class="empty">Brak danych o urządzeniach.</div>';
    }
    return '<div style="display:grid;gap:12px">' . implode('', $rows) . '</div>';
}

/**
 * Najpopularniejsze podstrony na telefonie - zapytanie tylko do odczytu.
 */
function ana_mobile_pages(string $fromSql, string $toSql, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $st = db()->prepare('SELECT page, COUNT(*) AS pageviews, COUNT(DISTINCT visitor_id) AS visitors FROM pageviews WHERE created_at BETWEEN ? AND ? AND device = ? GROUP BY page ORDER BY pageviews DESC LIMIT ' . $limit);
    $st->execute([$fromSql, $toSql, 'mobile']);
    return $st->fetchAll();
}

/**
 * Szczegóły jednej podstrony: podsumowanie, zdarzenia, źródła i seria dzienna.
 * Wyłącznie SELECT-y, parametry wiązane.
 */
function ana_page_report(string $page, string $fromSql, string $toSql): array
{
    $st = db()->prepare('SELECT COUNT(*) AS pageviews, COUNT(DISTINCT visitor_id) AS visitors, COUNT(DISTINCT session_id) AS sessions, COALESCE(SUM(is_new_visitor), 0) AS new_visitors FROM pageviews WHERE page = ? AND created_at BETWEEN ? AND ?');
    $st->execute([$page, $fromSql, $toSql]);
    $row = $st->fetch() ?: [];
    $summary = [
        'pageviews' => (int) ($row['pageviews'] ?? 0),
        'visitors' => (int) ($row['visitors'] ?? 0),
        'sessions' => (int) ($row['sessions'] ?? 0),
        'new_visitors' => (int) ($row['new_visitors'] ?? 0),
    ];

    $st = db()->prepare('SELECT name, COUNT(*) AS c, COUNT(DISTINCT visitor_id) AS visitors FROM events WHERE page = ? AND created_at BETWEEN ? AND ? GROUP BY name ORDER BY c DESC');
    $st->execute([$page, $fromSql, $toSql]);
    $events = $st->fetchAll();

    $st = db()->prepare('SELECT source, medium, campaign, COUNT(*) AS pageviews, COUNT(DISTINCT visitor_id) AS visitors FROM pageviews WHERE page = ? AND created_at BETWEEN ? AND ? GROUP BY source, medium, campaign ORDER BY pageviews DESC LIMIT 15');
    $st->execute([$page, $fromSql, $toSql]);
    $sources = $st->fetchAll();

    $byDay = [];
    $st = db()->prepare('SELECT substr(created_at, 1, 10) AS day, COUNT(*) AS pageviews, COUNT(DISTINCT visitor_id) AS visitors FROM pageviews WHERE page = ? AND created_at BETWEEN ? AND ? GROUP BY day');
    $st->execute([$page, $fromSql, $toSql]);
    foreach ($st->fetchAll() as $dayRow) {
        $byDay[(string) $dayRow['day']] = $dayRow;
    }

    $series = [];
    $cursor = new DateTimeImmutable(substr($fromSql, 0, 10));
    $end = new DateTimeImmutable(substr($toSql, 0, 10));
    for (; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
        $day = $cursor->format('Y-m-d');
        $series[] = [
            'date' => $day,
            'pageviews' => (int) ($byDay[$day]['pageviews'] ?? 0),
            'visitors' => (int) ($byDay[$day]['visitors'] ?? 0),
        ];
    }

    return [
        'summary' => $summary,
        'events' => $events,
        'sources' => $sources,
        'series' => $series,
        'label' => analytics_page_label($page),
    ];
}

/**
 * Tabela podstron - wspólna dla Przeglądu i zakładki Podstrony.
 */
function ana_pages_table(array $rows, array $state): string
{
    if (!$rows) {
        return '<div class="empty">Brak odsłon podstron w wybranym okresie.</div>';
    }
    $html = '<div class="table-wrap"><table class="table table--compact"><thead><tr>'
        . '<th>Strona</th>'
        . '<th class="num">Odsłony</th>'
        . '<th class="num">Użytkownicy</th>'
        . '<th class="num">Telefon</th>'
        . '<th class="num">Dojazd</th>'
        . '<th class="num">Konwersje</th>'
        . '<th>Główne źródło</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $path = (string) ($row['page'] ?? '');
        $label = (string) ($row['label'] ?? analytics_page_label($path));
        $source = (string) ($row['top_source'] ?? 'direct');
        $html .= '<tr>'
            . '<td><div class="row-title"><a href="' . e(ana_url($state, ['tab' => 'page', 'page' => $path])) . '"><strong>' . e($label) . '</strong></a><small class="mono">' . e($path) . '</small></div></td>'
            . '<td class="num">' . e(ana_num($row['pageviews'] ?? 0)) . '</td>'
            . '<td class="num">' . e(ana_num($row['visitors'] ?? 0)) . '</td>'
            . '<td class="num">' . e(ana_num($row['phone'] ?? 0)) . '</td>'
            . '<td class="num">' . e(ana_num($row['directions'] ?? 0)) . '</td>'
            . '<td class="num">' . e(ana_num($row['conversions'] ?? 0)) . '</td>'
            . '<td><span>' . e(ana_source_label($source)) . '</span> <span class="muted small mono">' . e($source) . '</span></td>'
            . '</tr>';
    }
    return $html . '</tbody></table></div>';
}

/* ----------------------------------------------------------------- logika */

$presets = [
    'today' => ['label' => 'Dzisiaj', 'days' => 1],
    'yesterday' => ['label' => 'Wczoraj', 'days' => 1],
    '7' => ['label' => '7 dni', 'days' => 7],
    '30' => ['label' => '30 dni', 'days' => 30],
    '90' => ['label' => '90 dni', 'days' => 90],
    '365' => ['label' => '12 miesięcy', 'days' => 365],
];

$range = ana_get_string('range');
if ($range === '') {
    $range = (ana_get_string('from') !== '' || ana_get_string('to') !== '') ? 'custom' : '30';
}
if ($range !== 'custom' && !isset($presets[$range])) {
    $range = '30';
}

$tz = new DateTimeZone('Europe/Warsaw');
$today = new DateTimeImmutable('now', $tz);
$defaults = daterange_input();
if ($range === 'custom') {
    $from = $defaults['from'];
    $to = $defaults['to'];
} elseif ($range === 'yesterday') {
    $from = $to = $today->modify('-1 day')->format('Y-m-d');
} else {
    $days = (int) $presets[$range]['days'];
    $to = $today->format('Y-m-d');
    $from = $days === 1 ? $to : $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
}

[$fromSql, $toSql] = analytics_bounds($from, $to);

$fromDate = new DateTimeImmutable($from);
$toDate = new DateTimeImmutable($to);
$periodDays = (int) $fromDate->diff($toDate)->days + 1;
$prevToDate = $fromDate->modify('-1 day');
$prevFromDate = $prevToDate->modify('-' . max(0, $periodDays - 1) . ' days');
$prevFrom = $prevFromDate->format('Y-m-d');
$prevTo = $prevToDate->format('Y-m-d');
[$prevFromSql, $prevToSql] = analytics_bounds($prevFrom, $prevTo);

$tabs = [
    'overview' => 'Przegląd',
    'pages' => 'Podstrony',
    'sources' => 'Źródła',
    'events' => 'Zdarzenia',
    'devices' => 'Urządzenia',
];
$tab = ana_get_string('tab', 'overview');
if (!isset($tabs[$tab]) && $tab !== 'page') {
    $tab = 'overview';
}
$pagePath = trim(ana_get_string('page'));
if ($pagePath !== '' && $pagePath[0] !== '/') {
    $pagePath = '/' . $pagePath;
}
$pagePath = str_limit($pagePath, 300);
if ($tab === 'page' && $pagePath === '') {
    $tab = 'pages';
}
$compare = ana_get_string('compare', '0') === '1';

$state = [
    'range' => $range,
    'from' => $from,
    'to' => $to,
    'tab' => $tab,
    'compare' => $compare ? '1' : '0',
    'page' => $tab === 'page' ? $pagePath : '',
];

$summary = analytics_summary($fromSql, $toSql);
$changes = null;
if ($compare) {
    $comparison = analytics_compare($fromSql, $toSql, $prevFromSql, $prevToSql);
    $changes = $comparison['change'];
    $changes['sessions'] = ana_pct_change($summary['sessions'], $comparison['previous']['sessions']);
    $changes['new_visitors'] = ana_pct_change($summary['new_visitors'], $comparison['previous']['new_visitors']);
}

$phoneClicks = (int) ($summary['events']['phone_click'] ?? 0);
$directionClicks = (int) ($summary['events']['directions_click'] ?? 0);
$formSubmits = (int) ($summary['events']['contact_form_submit'] ?? 0);

$kpiCards = [
    [
        'label' => 'Odsłony',
        'value' => $summary['pageviews'],
        'hint' => 'wyświetlenia podstron',
        'definition' => 'Liczba wyświetleń podstron w wybranym okresie. Boty i ruch bez zgody analitycznej są odfiltrowane.',
        'change' => $changes['pageviews'] ?? null,
    ],
    [
        'label' => 'Użytkownicy',
        'value' => $summary['visitors'],
        'hint' => 'unikalne osoby w okresie',
        'definition' => 'Unikalne osoby (identyfikator w ciasteczku), które odwiedziły stronę w wybranym okresie.',
        'change' => $changes['visitors'] ?? null,
    ],
    [
        'label' => 'Sesje',
        'value' => $summary['sessions'],
        'hint' => 'osobne wizyty',
        'definition' => 'Osobne wizyty. Nowa sesja zaczyna się po zamknięciu przeglądarki lub po dłuższej przerwie.',
        'change' => $changes['sessions'] ?? null,
    ],
    [
        'label' => 'Nowi użytkownicy',
        'value' => $summary['new_visitors'],
        'hint' => 'pierwsza wizyta',
        'definition' => 'Osoby, które pojawiły się na stronie po raz pierwszy w całym oknie pomiaru.',
        'change' => $changes['new_visitors'] ?? null,
    ],
    [
        'label' => 'Kliknięcia telefonu',
        'value' => $phoneClicks,
        'hint' => 'kliknięcie w numer, nie rozmowa',
        'definition' => 'Kliknięcie w numer telefonu. To nie jest zakończona rozmowa - mierzymy intencję kontaktu.',
        'change' => $changes['phone'] ?? null,
    ],
    [
        'label' => 'Kliknięcia dojazdu',
        'value' => $directionClicks,
        'hint' => 'otwarcie nawigacji / mapy',
        'definition' => 'Otwarcie nawigacji lub mapy z adresem serwisu. Nie oznacza przyjazdu klienta.',
        'change' => $changes['directions'] ?? null,
    ],
    [
        'label' => 'Wysłane formularze',
        'value' => $formSubmits,
        'hint' => 'potwierdzone wysłanie',
        'definition' => 'Potwierdzone wysłanie formularza kontaktowego - nie samo rozpoczęcie wypełniania.',
        'change' => $changes['forms'] ?? null,
    ],
    [
        'label' => 'Konwersje',
        'value' => $summary['conversions'],
        'hint' => 'formularz + potwierdzony lead',
        'definition' => 'Wysłane formularze kontaktowe + potwierdzone leady (zdarzenie generate_lead).',
        'change' => $changes['conversions'] ?? null,
    ],
];

$series = [];
$devices = [];
$topPages = [];
$pagesTable = [];
$rankings = [];
$sources = [];
$utmRows = [];
$eventsTable = [];
$mobilePages = [];
$pageReport = null;
$pageConversions = 0;

if ($tab === 'overview') {
    $series = analytics_series($fromSql, $toSql);
    $devices = analytics_devices($fromSql, $toSql);
    $topPages = analytics_top_pages($fromSql, $toSql, 10);
} elseif ($tab === 'pages') {
    $pagesTable = analytics_top_pages($fromSql, $toSql, 25);
    $positiveRows = static function (array $rows, string $metric): array {
        return array_values(array_filter($rows, static function (array $row) use ($metric): bool {
            return (int) ($row[$metric] ?? 0) > 0;
        }));
    };
    $rankings = [
        ['title' => 'Najwięcej kliknięć telefonu', 'rows' => $positiveRows(array_slice(analytics_top_pages($fromSql, $toSql, 20, 'phone'), 0, 8), 'phone'), 'metric' => 'phone', 'unit' => ['telefon', 'telefony', 'telefonów']],
        ['title' => 'Najwięcej konwersji', 'rows' => $positiveRows(array_slice(analytics_top_pages($fromSql, $toSql, 20, 'conversions'), 0, 8), 'conversions'), 'metric' => 'conversions', 'unit' => ['konwersja', 'konwersje', 'konwersji']],
        ['title' => 'Największy ruch', 'rows' => $positiveRows(array_slice(analytics_top_pages($fromSql, $toSql, 20, 'pageviews'), 0, 8), 'pageviews'), 'metric' => 'pageviews', 'unit' => ['odsłona', 'odsłony', 'odsłon']],
    ];
} elseif ($tab === 'sources') {
    $sources = analytics_sources($fromSql, $toSql);
    $utmRows = array_values(array_filter($sources, static function (array $row): bool {
        return trim((string) ($row['medium'] ?? '')) !== '' || trim((string) ($row['campaign'] ?? '')) !== '';
    }));
} elseif ($tab === 'events') {
    $eventsTable = analytics_events_table($fromSql, $toSql);
} elseif ($tab === 'devices') {
    $devices = analytics_devices($fromSql, $toSql);
    $mobilePages = ana_mobile_pages($fromSql, $toSql, 10);
} elseif ($tab === 'page') {
    $pageReport = ana_page_report($pagePath, $fromSql, $toSql);
    foreach ($pageReport['events'] as $pageEventRow) {
        if (in_array((string) $pageEventRow['name'], ['generate_lead', 'contact_form_submit'], true)) {
            $pageConversions += (int) $pageEventRow['c'];
        }
    }
}

$devicesTotal = 0;
foreach ($devices as $deviceRow) {
    $devicesTotal += (int) ($deviceRow['pageviews'] ?? 0);
}

$eventDefs = ana_event_definitions();

$siteData = [];
try {
    $siteData = data_read('site');
} catch (Throwable $e) {
    $siteData = [];
}
$analyticsConfig = is_array($siteData['analytics'] ?? null) ? $siteData['analytics'] : [];
$gtm = trim((string) ($analyticsConfig['gtm'] ?? ''));
$ga4 = trim((string) ($analyticsConfig['ga4'] ?? ''));
$ads = trim((string) ($analyticsConfig['ads'] ?? $analyticsConfig['adsConversionSendTo'] ?? ''));
$clarity = trim((string) ($analyticsConfig['clarity'] ?? ''));
$integrations = [
    ['label' => 'Google Tag Manager', 'value' => $gtm],
    ['label' => 'Google Analytics 4', 'value' => $ga4],
    ['label' => 'Google Ads (konwersje)', 'value' => $ads],
    ['label' => 'Microsoft Clarity', 'value' => $clarity],
];
$mainIntegrationsOn = $gtm !== '' || $ga4 !== '' || $ads !== '';

$siteUrl = rtrim((string) cfg('site_url'), '/');
$retentionDays = (int) cfg('analytics_retention_days', 400);

layout_head('Analityka', 'analytics');
?>

<section class="card">
  <header class="card__head"><h2>Zakres analizy</h2></header>
  <div class="card__body">
    <div class="toolbar" style="margin-bottom:0">
      <span class="seg">
        <?php foreach ($presets as $presetKey => $preset): ?>
          <a class="<?= $range === $presetKey ? 'is-active' : '' ?>" href="<?= e(ana_url($state, ['range' => $presetKey, 'from' => null, 'to' => null])) ?>"><?= e($preset['label']) ?></a>
        <?php endforeach; ?>
      </span>
      <form method="get" action="<?= e(panel_url('analytics')) ?>" class="toolbar" style="margin:0;gap:6px">
        <input type="hidden" name="range" value="custom">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <input type="hidden" name="compare" value="<?= $compare ? '1' : '0' ?>">
        <?php if ($tab === 'page'): ?>
          <input type="hidden" name="page" value="<?= e($pagePath) ?>">
        <?php endif; ?>
        <label class="small muted" for="ana-from">od</label>
        <input id="ana-from" type="date" name="from" value="<?= e($from) ?>" style="min-height:38px;padding:7px 10px;border:1px solid var(--a-line-strong);border-radius:2px;background:#fff">
        <label class="small muted" for="ana-to">do</label>
        <input id="ana-to" type="date" name="to" value="<?= e($to) ?>" style="min-height:38px;padding:7px 10px;border:1px solid var(--a-line-strong);border-radius:2px;background:#fff">
        <button class="btn btn--sm<?= $range === 'custom' ? ' btn--primary' : '' ?>" type="submit">Zakres własny</button>
      </form>
      <span class="spacer"></span>
      <a class="btn btn--sm btn--ghost" href="<?= e(ana_url($state, ['compare' => $compare ? '0' : '1'])) ?>"><?= $compare ? 'Ukryj zmianę %' : 'Porównaj z poprzednim okresem' ?></a>
    </div>
    <p class="small muted" style="margin-top:12px">
      Zakres: <strong><?= e(format_date($from, false)) ?> - <?= e(format_date($to, false)) ?></strong> (<?= e(ana_days_label($periodDays)) ?>)
      <?php if ($compare): ?>
        · porównanie z okresem <strong><?= e(format_date($prevFrom, false)) ?> - <?= e(format_date($prevTo, false)) ?></strong>
      <?php endif; ?>
    </p>
  </div>
</section>

<?php if ($summary['pageviews'] === 0): ?>

  <section class="card">
    <header class="card__head"><h2>Brak danych w wybranym okresie</h2></header>
    <div class="card__body">
      <div class="help-box">
        <strong>Jak zacząć zbierać statystyki?</strong>
        <ol>
          <li>Otwórz stronę po wdrożeniu: <a href="<?= e($siteUrl) ?>" target="_blank" rel="noopener"><?= e($siteUrl) ?></a>.</li>
          <li>Zaakceptuj statystyki w banerze cookies - pomiar działa tylko dla użytkowników z zgodą analityczną.</li>
          <li>Odwiedź kilka podstron (np. cennik i kontakt) na telefonie i na komputerze.</li>
          <li>Wróć do panelu i odśwież stronę - pierwsze odsłony pojawiają się zwykle w ciągu kilku minut.</li>
        </ol>
      </div>
      <p class="small muted" style="margin-top:12px">
        Sprawdź też, czy strona wysyła zdarzenia do <span class="code-inline">/api/e</span>
        (endpoint pierwszego pomiaru) i czy analityka nie została wyłączona w Ustawieniach.
        Panel nie pokazuje danych z GA4/Ads - te integracje mają własne statystyki.
      </p>
      <div class="form-actions" style="margin-top:12px">
        <a class="btn btn--sm" href="<?= e(ana_url($state, ['range' => '7', 'from' => null, 'to' => null, 'tab' => 'overview'])) ?>">Pokaż ostatnie 7 dni</a>
        <a class="btn btn--sm btn--ghost" href="<?= e(ana_url($state, ['range' => '365', 'from' => null, 'to' => null, 'tab' => 'overview'])) ?>">Pokaż 12 miesięcy</a>
        <a class="btn btn--sm btn--ghost" href="<?= e(panel_url('settings', ['tab' => 'analytics'])) ?>">Ustawienia analityki</a>
      </div>
    </div>
  </section>

<?php else: ?>

  <div class="kpis">
    <?php foreach ($kpiCards as $kpi): ?>
      <div class="kpi" title="<?= e($kpi['definition']) ?>">
        <span class="kpi__label"><?= e($kpi['label']) ?></span>
        <span class="kpi__value"><?= e(ana_num($kpi['value'])) ?></span>
        <?= ana_change_html($kpi['change']) ?>
        <span class="kpi__hint"><?= e($kpi['hint']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <nav class="tabs" aria-label="Sekcje analityki">
    <?php foreach ($tabs as $tabKey => $tabLabel): ?>
      <?php $tabActive = $tab === $tabKey || ($tab === 'page' && $tabKey === 'pages'); ?>
      <a class="<?= $tabActive ? 'is-active' : '' ?>" href="<?= e(ana_url($state, ['tab' => $tabKey])) ?>"<?= $tabActive ? ' aria-current="page"' : '' ?>><?= e($tabLabel) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($tab === 'overview'): ?>

    <div class="grid grid--2">
      <section class="card">
        <header class="card__head"><h2>Odsłony i użytkownicy w czasie</h2></header>
        <div class="card__body">
          <?= ana_line_chart($series, ['pageviews', 'visitors'], ['pageviews' => '#2d343a', 'visitors' => '#ffd400'], 'Odsłony i użytkownicy w czasie') ?>
          <p class="small muted" style="margin-top:8px">Odsłony to wyświetlenia podstron, użytkownicy to unikalne osoby w danym dniu. Oś Y: liczba.</p>
        </div>
      </section>
      <section class="card">
        <header class="card__head"><h2>Kontakt i konwersje w czasie</h2></header>
        <div class="card__body">
          <?= ana_line_chart($series, ['phone', 'directions', 'forms', 'conversions'], ['phone' => '#2d343a', 'directions' => '#1f5f99', 'forms' => '#1f7a3f', 'conversions' => '#a15c00'], 'Telefon, dojazd, formularze i konwersje w czasie') ?>
          <p class="small muted" style="margin-top:8px">Kliknięcia telefonu i dojazdu to intencje kontaktu, formularze i konwersje - potwierdzone zgłoszenia.</p>
        </div>
      </section>
    </div>

    <div class="grid grid--2">
      <section class="card">
        <header class="card__head"><h2>Telefon vs komputer</h2></header>
        <div class="card__body">
          <?= ana_bar_chart($series, ['mobile', 'desktop'], ['mobile' => '#2d343a', 'desktop' => '#ffd400'], 'Odsłony na telefonie i komputerze') ?>
          <p class="small muted" style="margin-top:8px">Odsłony w podziale na typ urządzenia; przy długim zakresie słupki sumują kilka dni.</p>
        </div>
      </section>
      <section class="card">
        <header class="card__head"><h2>Ruch wg urządzeń</h2></header>
        <div class="card__body">
          <?= ana_device_bars($devices, $devicesTotal) ?>
          <p class="small muted" style="margin-top:12px">Udział procentowy odsłon. Tablet i inne urządzenia zwykle stanowią margines ruchu.</p>
        </div>
      </section>
    </div>

    <section class="card">
      <header class="card__head">
        <h2>Najczęściej odwiedzane podstrony</h2>
        <div class="card__actions"><a class="btn btn--sm btn--ghost" href="<?= e(ana_url($state, ['tab' => 'pages'])) ?>">Wszystkie podstrony →</a></div>
      </header>
      <div class="card__body card__body--flush">
        <?= ana_pages_table($topPages, $state) ?>
      </div>
    </section>

  <?php elseif ($tab === 'pages'): ?>

    <section class="card">
      <header class="card__head">
        <h2>Podstrony z ruchem</h2>
        <div class="card__actions"><span class="muted small">Top 25 wg odsłon</span></div>
      </header>
      <div class="card__body card__body--flush">
        <?= ana_pages_table($pagesTable, $state) ?>
      </div>
    </section>

    <div class="grid grid--3">
      <?php foreach ($rankings as $ranking): ?>
        <section class="card">
          <header class="card__head"><h2><?= e($ranking['title']) ?></h2></header>
          <div class="card__body">
            <?php if (!$ranking['rows']): ?>
              <p class="muted small">Brak danych w tym okresie.</p>
            <?php else: ?>
              <ol class="list-plain">
                <?php foreach ($ranking['rows'] as $position => $rankingRow): ?>
                  <?php $metricValue = (int) ($rankingRow[$ranking['metric']] ?? 0); ?>
                  <li>
                    <span class="mono muted"><?= (int) $position + 1 ?>.</span>
                    <span style="flex:1"><a href="<?= e(ana_url($state, ['tab' => 'page', 'page' => (string) $rankingRow['page']])) ?>"><?= e((string) $rankingRow['label']) ?></a></span>
                    <span class="muted small nowrap"><?= e(ana_num($metricValue)) ?> <?= e(ana_plural($metricValue, $ranking['unit'][0], $ranking['unit'][1], $ranking['unit'][2])) ?></span>
                  </li>
                <?php endforeach; ?>
              </ol>
            <?php endif; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
    <p class="small muted">Rankingi liczone są wśród 20 podstron z największą liczbą odsłon w wybranym okresie.</p>

  <?php elseif ($tab === 'sources'): ?>

    <section class="card">
      <header class="card__head">
        <h2>Źródła i kampanie</h2>
        <div class="card__actions"><span class="muted small">Top 30 kombinacji źródło / medium / kampania</span></div>
      </header>
      <div class="card__body card__body--flush">
        <?php if (!$sources): ?>
          <div class="empty">Brak danych o źródłach w wybranym okresie.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead>
                <tr>
                  <th>Źródło</th>
                  <th>Medium</th>
                  <th>Kampania</th>
                  <th class="num">Odsłony</th>
                  <th class="num">Użytkownicy</th>
                  <th class="num">Telefon</th>
                  <th class="num">Dojazd</th>
                  <th class="num">Konwersje</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($sources as $sourceRow): ?>
                <tr>
                  <td><div class="row-title"><strong><?= e(ana_source_label((string) $sourceRow['source'])) ?></strong><small class="mono"><?= e($sourceRow['source']) ?></small></div></td>
                  <td>
                    <?php if ((string) ($sourceRow['medium'] ?? '') !== ''): ?>
                      <span class="mono"><?= e($sourceRow['medium']) ?></span>
                    <?php else: ?>
                      <span class="muted">-</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ((string) ($sourceRow['campaign'] ?? '') !== ''): ?>
                      <span class="mono"><?= e($sourceRow['campaign']) ?></span>
                    <?php else: ?>
                      <span class="muted">-</span>
                    <?php endif; ?>
                  </td>
                  <td class="num"><?= e(ana_num($sourceRow['pageviews'] ?? 0)) ?></td>
                  <td class="num"><?= e(ana_num($sourceRow['visitors'] ?? 0)) ?></td>
                  <td class="num"><?= e(ana_num($sourceRow['phone'] ?? 0)) ?></td>
                  <td class="num"><?= e(ana_num($sourceRow['directions'] ?? 0)) ?></td>
                  <td class="num"><?= e(ana_num($sourceRow['conversions'] ?? 0)) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($sources): ?>
        <p class="small muted" style="padding:12px 18px 18px">
          W źródłach „konwersje” oznaczają potwierdzone leady (generate_lead). Wysłane formularze
          znajdziesz w KPI oraz w zakładce Zdarzenia.
        </p>
      <?php endif; ?>
    </section>

    <section class="card">
      <header class="card__head"><h2>Kampanie UTM</h2></header>
      <div class="card__body">
        <?php if (!$utmRows): ?>
          <p class="muted small">Brak ruchu z parametrami UTM w wybranym okresie.</p>
          <div class="help-box" style="margin-top:10px">
            Oznaczaj linki w kampaniach parametrami <span class="code-inline">utm_source</span>,
            <span class="code-inline">utm_medium</span> i <span class="code-inline">utm_campaign</span> -
            wtedy ruch z kampanii pojawi się w tej sekcji i w tabeli źródeł.
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead>
                <tr>
                  <th>Kampania</th>
                  <th>Medium</th>
                  <th>Źródło</th>
                  <th class="num">Odsłony</th>
                  <th class="num">Użytkownicy</th>
                  <th class="num">Konwersje</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($utmRows as $utmRow): ?>
                <tr>
                  <td>
                    <?php if ((string) ($utmRow['campaign'] ?? '') !== ''): ?>
                      <strong><?= e($utmRow['campaign']) ?></strong>
                    <?php else: ?>
                      <span class="muted">- (brak campaign)</span>
                    <?php endif; ?>
                  </td>
                  <td><span class="mono"><?= e($utmRow['medium'] ?? '') ?></span></td>
                  <td><?= e(ana_source_label((string) $utmRow['source'])) ?></td>
                  <td class="num"><?= e(ana_num($utmRow['pageviews'] ?? 0)) ?></td>
                  <td class="num"><?= e(ana_num($utmRow['visitors'] ?? 0)) ?></td>
                  <td class="num"><?= e(ana_num($utmRow['conversions'] ?? 0)) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>

  <?php elseif ($tab === 'events'): ?>

    <section class="card">
      <header class="card__head">
        <h2>Zdarzenia w okresie</h2>
        <div class="card__actions"><span class="muted small">Wszystkie zmierzone zdarzenia</span></div>
      </header>
      <div class="card__body card__body--flush">
        <?php if (!$eventsTable): ?>
          <div class="empty">Brak zdarzeń w wybranym okresie. Pojawią się po kliknięciach telefonu, dojazdu lub wysłaniu formularza.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead>
                <tr>
                  <th>Zdarzenie</th>
                  <th class="num">Liczba</th>
                  <th class="num">Użytkownicy</th>
                  <th class="num">Strony</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($eventsTable as $eventRow): ?>
                <?php $eventDef = $eventDefs[(string) $eventRow['name']] ?? null; ?>
                <tr>
                  <td><div class="row-title"><strong><?= e($eventDef[0] ?? (string) $eventRow['name']) ?></strong><small class="mono"><?= e($eventRow['name']) ?></small></div></td>
                  <td class="num"><?= e(ana_num($eventRow['c'] ?? 0)) ?></td>
                  <td class="num"><?= e(ana_num($eventRow['visitors'] ?? 0)) ?></td>
                  <td class="num"><?= e(ana_num($eventRow['pages'] ?? 0)) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <header class="card__head"><h2>Definicje zdarzeń</h2></header>
      <div class="card__body">
        <p class="small muted">Zdarzenia mierzą intencje użytkownika, a nie efekt końcowy. Dlatego kliknięcie telefonu nie jest rozmową, a otwarcie mapy - przyjazdem.</p>
        <div class="table-wrap" style="margin-top:10px">
          <table class="table table--compact">
            <thead><tr><th>Zdarzenie</th><th>Nazwa techniczna</th><th>Znaczenie</th></tr></thead>
            <tbody>
            <?php foreach ($eventDefs as $eventName => $eventDef): ?>
              <tr>
                <td><strong><?= e($eventDef[0]) ?></strong></td>
                <td class="mono"><?= e($eventName) ?></td>
                <td class="small"><?= e($eventDef[1]) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

  <?php elseif ($tab === 'devices'): ?>

    <div class="grid grid--sidebar">
      <section class="card">
        <header class="card__head"><h2>Urządzenia</h2></header>
        <div class="card__body card__body--flush">
          <div class="table-wrap">
            <table class="table table--compact">
              <thead>
                <tr>
                  <th>Urządzenie</th>
                  <th class="num">Użytkownicy</th>
                  <th class="num">Sesje</th>
                  <th class="num">Odsłony</th>
                  <th class="num">Telefon</th>
                  <th class="num">Dojazd</th>
                  <th class="num">Konwersje</th>
                  <th class="num">Wsp. konwersji</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach (['mobile', 'desktop', 'tablet', 'other'] as $deviceKey): ?>
                <?php
                $deviceRow = $devices[$deviceKey] ?? ['pageviews' => 0, 'visitors' => 0, 'sessions' => 0, 'events' => [], 'conversions' => 0];
                $deviceSessions = (int) ($deviceRow['sessions'] ?? 0);
                $deviceConversions = (int) ($deviceRow['conversions'] ?? 0);
                $deviceRate = $deviceSessions > 0 ? round($deviceConversions / $deviceSessions * 100, 1) : 0.0;
                ?>
                <tr>
                  <td><strong><?= e(ana_device_label($deviceKey)) ?></strong> <small class="muted mono"><?= e($deviceKey) ?></small></td>
                  <td class="num"><?= e(ana_num($deviceRow['visitors'] ?? 0)) ?></td>
                  <td class="num"><?= e(ana_num($deviceSessions)) ?></td>
                  <td class="num"><?= e(ana_num($deviceRow['pageviews'] ?? 0)) ?></td>
                  <td class="num"><?= e(ana_num($deviceRow['events']['phone_click'] ?? 0)) ?></td>
                  <td class="num"><?= e(ana_num($deviceRow['events']['directions_click'] ?? 0)) ?></td>
                  <td class="num"><?= e(ana_num($deviceConversions)) ?></td>
                  <td class="num"><?= e(number_format($deviceRate, 1, ',', ' ')) ?>%</td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <p class="small muted" style="padding:12px 18px 18px">
          Współczynnik konwersji = konwersje / sesje. Konwersja to wysłany formularz lub potwierdzony lead.
        </p>
      </section>

      <section class="card">
        <header class="card__head"><h2>Udział urządzeń</h2></header>
        <div class="card__body">
          <?= ana_device_bars($devices, $devicesTotal) ?>
        </div>
      </section>
    </div>

    <section class="card">
      <header class="card__head">
        <h2>Najpopularniejsze podstrony na telefonie</h2>
        <div class="card__actions"><span class="muted small">Top 10 mobile</span></div>
      </header>
      <div class="card__body card__body--flush">
        <?php if (!$mobilePages): ?>
          <div class="empty">Brak odsłon z telefonów w wybranym okresie.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table table--compact">
              <thead><tr><th>Podstrona</th><th class="num">Odsłony</th><th class="num">Użytkownicy</th></tr></thead>
              <tbody>
              <?php foreach ($mobilePages as $mobileRow): ?>
                <?php $mobilePath = (string) ($mobileRow['page'] ?? ''); ?>
                <tr>
                  <td>
                    <div class="row-title">
                      <a href="<?= e(ana_url($state, ['tab' => 'page', 'page' => $mobilePath])) ?>"><strong><?= e(analytics_page_label($mobilePath)) ?></strong></a>
                      <small class="mono"><?= e($mobilePath) ?></small>
                    </div>
                  </td>
                  <td class="num"><?= e(ana_num($mobileRow['pageviews'] ?? 0)) ?></td>
                  <td class="num"><?= e(ana_num($mobileRow['visitors'] ?? 0)) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>

  <?php elseif ($tab === 'page' && $pageReport !== null): ?>

    <nav class="breadcrumbs" aria-label="Ścieżka">
      <a href="<?= e(ana_url($state, ['tab' => 'pages', 'page' => null])) ?>">Podstrony</a>
      <span>/</span>
      <span><?= e($pageReport['label']) ?></span>
    </nav>

    <section class="card">
      <header class="card__head">
        <h2><?= e($pageReport['label']) ?></h2>
        <div class="card__actions">
          <a class="btn btn--sm btn--ghost" href="<?= e($siteUrl . $pagePath) ?>" target="_blank" rel="noopener">Otwórz podstronę ↗</a>
          <a class="btn btn--sm" href="<?= e(ana_url($state, ['tab' => 'pages', 'page' => null])) ?>">Wróć do listy</a>
        </div>
      </header>
      <div class="card__body">
        <p class="small muted mono"><?= e($pagePath) ?></p>
        <?php if ((int) $pageReport['summary']['pageviews'] === 0): ?>
          <div class="empty" style="margin-top:12px">Brak odsłon tej podstrony w wybranym okresie. Zmień zakres dat albo wróć do listy podstron.</div>
        <?php else: ?>
          <div class="kpis" style="margin-top:12px">
            <div class="kpi">
              <span class="kpi__label">Odsłony</span>
              <span class="kpi__value"><?= e(ana_num($pageReport['summary']['pageviews'])) ?></span>
              <span class="kpi__hint">wyświetlenia tej podstrony</span>
            </div>
            <div class="kpi">
              <span class="kpi__label">Użytkownicy</span>
              <span class="kpi__value"><?= e(ana_num($pageReport['summary']['visitors'])) ?></span>
              <span class="kpi__hint">unikalne osoby</span>
            </div>
            <div class="kpi">
              <span class="kpi__label">Sesje</span>
              <span class="kpi__value"><?= e(ana_num($pageReport['summary']['sessions'])) ?></span>
              <span class="kpi__hint">osobne wizyty</span>
            </div>
            <div class="kpi">
              <span class="kpi__label">Konwersje</span>
              <span class="kpi__value"><?= e(ana_num($pageConversions)) ?></span>
              <span class="kpi__hint">formularz + potwierdzony lead</span>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <?php if ((int) $pageReport['summary']['pageviews'] > 0): ?>

      <section class="card">
        <header class="card__head"><h2>Ruch podstrony w czasie</h2></header>
        <div class="card__body">
          <?= ana_line_chart($pageReport['series'], ['pageviews', 'visitors'], ['pageviews' => '#2d343a', 'visitors' => '#ffd400'], 'Ruch podstrony w czasie') ?>
        </div>
      </section>

      <div class="grid grid--2">
        <section class="card">
          <header class="card__head"><h2>Zdarzenia na podstronie</h2></header>
          <div class="card__body card__body--flush">
            <?php if (!$pageReport['events']): ?>
              <div class="empty">Brak zdarzeń na tej podstronie w wybranym okresie.</div>
            <?php else: ?>
              <div class="table-wrap">
                <table class="table table--compact">
                  <thead><tr><th>Zdarzenie</th><th class="num">Liczba</th><th class="num">Użytkownicy</th></tr></thead>
                  <tbody>
                  <?php foreach ($pageReport['events'] as $pageEventRow): ?>
                    <?php $pageEventDef = $eventDefs[(string) $pageEventRow['name']] ?? null; ?>
                    <tr>
                      <td><div class="row-title"><strong><?= e($pageEventDef[0] ?? (string) $pageEventRow['name']) ?></strong><small class="mono"><?= e($pageEventRow['name']) ?></small></div></td>
                      <td class="num"><?= e(ana_num($pageEventRow['c'] ?? 0)) ?></td>
                      <td class="num"><?= e(ana_num($pageEventRow['visitors'] ?? 0)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <section class="card">
          <header class="card__head"><h2>Źródła ruchu podstrony</h2></header>
          <div class="card__body card__body--flush">
            <?php if (!$pageReport['sources']): ?>
              <div class="empty">Brak danych o źródłach dla tej podstrony.</div>
            <?php else: ?>
              <div class="table-wrap">
                <table class="table table--compact">
                  <thead><tr><th>Źródło</th><th>Medium / kampania</th><th class="num">Odsłony</th><th class="num">Użytkownicy</th></tr></thead>
                  <tbody>
                  <?php foreach ($pageReport['sources'] as $pageSourceRow): ?>
                    <tr>
                      <td><?= e(ana_source_label((string) $pageSourceRow['source'])) ?></td>
                      <td>
                        <?php if ((string) ($pageSourceRow['medium'] ?? '') !== ''): ?>
                          <span class="mono"><?= e($pageSourceRow['medium']) ?></span>
                        <?php elseif ((string) ($pageSourceRow['campaign'] ?? '') !== ''): ?>
                          <span class="mono"><?= e($pageSourceRow['campaign']) ?></span>
                        <?php else: ?>
                          <span class="muted">-</span>
                        <?php endif; ?>
                      </td>
                      <td class="num"><?= e(ana_num($pageSourceRow['pageviews'] ?? 0)) ?></td>
                      <td class="num"><?= e(ana_num($pageSourceRow['visitors'] ?? 0)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </section>
      </div>

    <?php endif; ?>

  <?php endif; ?>

<?php endif; ?>

<section class="card">
  <header class="card__head"><h2>Źródła danych i integracje</h2></header>
  <div class="card__body">
    <p class="small">
      <strong>Własny pomiar pierwszo-stronny.</strong> Wszystkie liczby w panelu pochodzą z endpointu
      <span class="code-inline">/api/e</span> na Twojej stronie. Mierzymy tylko użytkowników, którzy
      zaakceptowali statystyki w banerze cookies. Nie zapisujemy pełnych adresów IP (tylko hash),
      a dane są przechowywane przez <?= (int) $retentionDays ?> dni.
    </p>
    <ul class="list-plain" style="margin-top:12px">
      <?php foreach ($integrations as $integration): ?>
        <li>
          <?php if ($integration['value'] !== ''): ?>
            <?= status_badge('ok') ?>
          <?php else: ?>
            <?= status_badge('disabled') ?>
          <?php endif; ?>
          <span>
            <strong><?= e($integration['label']) ?></strong> -
            <?php if ($integration['value'] !== ''): ?>
              <span class="mono"><?= e($integration['value']) ?></span>
            <?php else: ?>
              <span class="muted">nieaktywne (pusty identyfikator)</span>
            <?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$mainIntegrationsOn): ?>
      <div class="alert alert--warn" style="margin-top:12px">
        <strong>Integracje zewnętrzne nie są podłączone.</strong>
        W konfiguracji <span class="code-inline">site.analytics</span> pola
        <span class="code-inline">gtm</span>, <span class="code-inline">ga4</span> i
        <span class="code-inline">ads</span> są puste, więc Google Tag Manager, GA4 i Google Ads
        nie działają na stronie. Panel nie pokazuje żadnych danych z GA4/Ads - nie ma tu fikcyjnych
        liczb; wszystkie statystyki powyżej to wyłącznie własny pomiar.
        Podłącz integracje w <a href="<?= e(panel_url('settings', ['tab' => 'analytics'])) ?>">Ustawieniach</a>.
      </div>
    <?php else: ?>
      <p class="small muted" style="margin-top:8px">
        Integracje działają na stronie po zgodzie użytkownika. Panel nie importuje danych z GA4/Ads -
        pokazuje wyłącznie własny pomiar.
      </p>
    <?php endif; ?>
    <p class="small muted" style="margin-top:10px">
      Konfiguracja: <a href="<?= e(panel_url('settings', ['tab' => 'analytics'])) ?>">Ustawienia</a> →
      Analityka i zgody (<span class="code-inline">site.analytics</span>).
    </p>
  </div>
</section>

<?php layout_foot(); ?>
