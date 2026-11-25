<?php
/**
 * Publiczna strona statusu naprawy - link wysyłany klientowi w SMS-ie.
 *
 * Szablon jest dołączany przez panel/api/status.php (require) i dostaje
 * w zasięgu dwie zmienne:
 *   $status         - tablica modelu widoku albo null (token nieprawidłowy,
 *                     wygasły lub unieważniony),
 *   $status_refresh - int, sekundy do odświeżenia (dziś zawsze 0, nieużywane).
 *
 * Zasady: czysty PHP bez frameworka i bez helperów z panelu (własne $h),
 * zero JavaScriptu, zero zewnętrznych żądań, jeden arkusz /tracking/status.css.
 */

$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$str = static function (array $source, string $key): string {
    $value = $source[$key] ?? '';
    return is_scalar($value) ? trim((string) $value) : '';
};

/* Dane serwisu - wartości domyślne zgodne z ticket_public_service()
   (panel/lib/tickets.php) i src/data/site.json; nadpisywane przez
   $status['service'], jeśli model je dostarczy. Dzięki temu strona
   "link nieaktywny" też ma telefon, adres i godziny. */
$service = [
    'name' => 'Studio-Komp',
    'phone' => '500 613 500',
    'phone_href' => 'tel:+48500613500',
    'address' => 'Kotlarska 14, Wrocław',
    'hours' => 'Poniedziałek - piątek: 7:00 - 15:00',
    'url' => 'https://sk.mati-it.pl',
];

$status = isset($status) && is_array($status) ? $status : null;

if ($status !== null && isset($status['service']) && is_array($status['service'])) {
    foreach ($service as $key => $fallback) {
        $value = $status['service'][$key] ?? null;
        if (is_scalar($value) && trim((string) $value) !== '') {
            $service[$key] = trim((string) $value);
        }
    }
}

/* Telefon i adres strony tylko w bezpiecznych postaciach. */
$phoneHref = $service['phone_href'];
if (stripos($phoneHref, 'tel:') !== 0) {
    $phoneHref = 'tel:' . preg_replace('/[^0-9+]/', '', $service['phone']);
}

$siteUrl = $service['url'];
if (!preg_match('#^https?://#i', $siteUrl)) {
    $siteUrl = 'https://sk.mati-it.pl';
}
$siteHost = (string) (parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl);

$number = '';
$device = '';
$receivedDate = '';
$receivedTime = '';
$stageLabel = '';
$stageIndex = 1;
$stageTotal = 3;
$updatedAt = '';
$publicNote = '';
$steps = [];
$rail = [];

if ($status !== null) {
    $number = $str($status, 'number');
    $device = $str($status, 'device');
    $receivedDate = $str($status, 'received_date');
    $receivedTime = $str($status, 'received_time');
    $stageLabel = $str($status, 'stage_label');
    $stageIndex = max(1, (int) ($status['stage_index'] ?? 1));
    $stageTotal = max(1, (int) ($status['stage_total'] ?? 3));
    $updatedAt = $str($status, 'updated_at');
    $publicNote = $str($status, 'public_note');

    if ($stageIndex > $stageTotal) {
        $stageTotal = $stageIndex;
    }

    /* Etapy: dopuszczamy wyłącznie stany done|current|todo. */
    $stateLabels = [
        'done' => 'Zrobione',
        'current' => 'Teraz',
        'todo' => 'Do zrobienia',
    ];

    if (isset($status['steps']) && is_array($status['steps'])) {
        foreach ($status['steps'] as $step) {
            if (!is_array($step)) {
                continue;
            }
            $state = (string) ($step['state'] ?? 'todo');
            if (!isset($stateLabels[$state])) {
                $state = 'todo';
            }
            $steps[] = [
                'state' => $state,
                'state_label' => $stateLabels[$state],
                'label' => $str($step, 'label'),
                'at' => $str($step, 'at'),
                'caption' => $str($step, 'caption'),
            ];
        }
    }

    foreach ($steps as $step) {
        $rail[] = $step['state'] === 'current' ? 'current' : ($step['state'] === 'done' ? 'done' : 'todo');
    }

    if ($rail === []) {
        for ($i = 1; $i <= $stageTotal; $i++) {
            $rail[] = $i < $stageIndex ? 'done' : ($i === $stageIndex ? 'current' : 'todo');
        }
    }
}

$currentLabel = $stageLabel !== '' ? $stageLabel : 'W serwisie';

$currentMeta = [];
if ($status !== null) {
    $currentMeta[] = 'etap ' . $stageIndex . ' z ' . $stageTotal;
    if ($updatedAt !== '') {
        $currentMeta[] = 'aktualizacja ' . $updatedAt;
    }
}

$pageTitle = 'Link nieaktywny - ' . $service['name'];
if ($status !== null) {
    $pageTitle = $number !== ''
        ? 'Status naprawy ' . $number . ' - ' . $service['name']
        : 'Status naprawy - ' . $service['name'];
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#ffd400">
<meta name="color-scheme" content="light">
<title><?= $h($pageTitle) ?></title>
<link rel="stylesheet" href="/tracking/status.css?v=1">
</head>
<body>
<header class="masthead">
  <div class="masthead__inner">
    <span class="brand"><span class="brand__mark" aria-hidden="true"></span><?= $h($service['name']) ?></span>
    <span class="masthead__meta">Serwis laptopów · Wrocław</span>
  </div>
</header>

<main class="page" id="main">
<?php if ($status === null): ?>
  <section class="empty" aria-labelledby="empty-title">
    <p class="label">Status naprawy</p>
    <h1 class="empty__title" id="empty-title">Ten link jest nieaktywny lub wygasł</h1>
    <p class="empty__text">Link do statusu działa przez ograniczony czas i zmienia się przy nowym zgłoszeniu. Jeśli chcesz sprawdzić stan naprawy, zadzwoń do serwisu - powiemy, co dzieje się ze sprzętem.</p>
    <a class="phone" href="<?= $h($phoneHref) ?>"><?= $h($service['phone']) ?></a>
    <dl class="rows">
      <div class="row">
        <dt>Adres</dt>
        <dd><?= $h($service['address']) ?></dd>
      </div>
      <div class="row">
        <dt>Godziny</dt>
        <dd><?= $h($service['hours']) ?></dd>
      </div>
      <div class="row">
        <dt>Strona</dt>
        <dd><a class="rows__link" href="<?= $h($siteUrl) ?>" target="_blank" rel="noopener"><?= $h($siteHost) ?><span class="visually-hidden"> (otwiera się w nowej karcie)</span><span aria-hidden="true"> ↗</span></a></dd>
      </div>
    </dl>
  </section>
<?php else: ?>
  <div class="layout">
    <div class="layout__aside">
      <section class="ticket" aria-label="Dane zgłoszenia">
        <div class="ticket__head">
          <span class="ticket__kicker">Zgłoszenie serwisowe</span>
<?php if ($receivedDate !== ''): ?>
          <span class="ticket__stamp mono"><?= $h($receivedDate) ?></span>
<?php endif; ?>
        </div>
<?php if ($number !== ''): ?>
        <p class="ticket__number"><?= $h($number) ?></p>
<?php endif; ?>
        <div class="ticket__perf" aria-hidden="true"></div>
        <dl class="rows">
          <div class="row">
            <dt>Sprzęt</dt>
            <dd><?= $h($device !== '' ? $device : 'Sprzęt w serwisie') ?></dd>
          </div>
<?php if ($receivedTime !== ''): ?>
          <div class="row">
            <dt>Przyjęto</dt>
            <dd><?= $h($receivedTime) ?></dd>
          </div>
<?php endif; ?>
        </dl>
        <div class="ticket__foot">
          <svg class="ticket__barcode" viewBox="0 0 168 24" aria-hidden="true" focusable="false">
            <g fill="currentColor">
              <rect x="0" y="0" width="3" height="24"/><rect x="6" y="0" width="1" height="24"/>
              <rect x="10" y="0" width="4" height="24"/><rect x="17" y="0" width="2" height="24"/>
              <rect x="22" y="0" width="1" height="24"/><rect x="26" y="0" width="5" height="24"/>
              <rect x="34" y="0" width="2" height="24"/><rect x="39" y="0" width="1" height="24"/>
              <rect x="43" y="0" width="4" height="24"/><rect x="50" y="0" width="2" height="24"/>
              <rect x="55" y="0" width="1" height="24"/><rect x="59" y="0" width="5" height="24"/>
              <rect x="67" y="0" width="2" height="24"/><rect x="72" y="0" width="1" height="24"/>
              <rect x="76" y="0" width="3" height="24"/><rect x="82" y="0" width="5" height="24"/>
              <rect x="90" y="0" width="2" height="24"/><rect x="95" y="0" width="1" height="24"/>
              <rect x="99" y="0" width="4" height="24"/><rect x="106" y="0" width="2" height="24"/>
              <rect x="111" y="0" width="1" height="24"/><rect x="115" y="0" width="5" height="24"/>
              <rect x="123" y="0" width="2" height="24"/><rect x="128" y="0" width="1" height="24"/>
              <rect x="132" y="0" width="4" height="24"/><rect x="139" y="0" width="2" height="24"/>
              <rect x="144" y="0" width="1" height="24"/><rect x="148" y="0" width="5" height="24"/>
              <rect x="156" y="0" width="2" height="24"/><rect x="161" y="0" width="3" height="24"/>
              <rect x="166" y="0" width="1.5" height="24"/>
            </g>
          </svg>
          <span class="ticket__foot-meta">Kotlarska 14 · Wrocław</span>
        </div>
      </section>

      <section class="current" aria-labelledby="current-title">
        <p class="label" id="current-title">Aktualny status</p>
        <h1 class="current__title"><?= $h($currentLabel) ?></h1>
<?php if ($currentMeta !== []): ?>
        <p class="current__meta"><?= $h(implode(' · ', $currentMeta)) ?></p>
<?php endif; ?>
<?php if ($rail !== []): ?>
        <ol class="rail" aria-hidden="true">
<?php foreach ($rail as $segment): ?>
          <li class="rail__seg is-<?= $h($segment) ?>"></li>
<?php endforeach; ?>
        </ol>
<?php endif; ?>
      </section>
    </div>

    <div class="layout__main">
<?php if ($steps !== []): ?>
      <section class="section" aria-labelledby="timeline-title">
        <div class="section__head">
          <h2 class="label" id="timeline-title">Przebieg naprawy</h2>
          <span class="section__meta">etap <?= (int) $stageIndex ?> z <?= (int) $stageTotal ?></span>
        </div>
        <ol class="steps">
<?php foreach ($steps as $step): ?>
          <li class="step step--<?= $h($step['state']) ?>"<?= $step['state'] === 'current' ? ' aria-current="step"' : '' ?>>
            <span class="step__mark" aria-hidden="true"></span>
            <div class="step__body">
<?php if ($step['label'] !== ''): ?>
              <span class="step__label"><?= $h($step['label']) ?></span>
<?php endif; ?>
              <span class="step__meta"><?= $h($step['state_label'] . ($step['at'] !== '' ? ' · ' . $step['at'] : '')) ?></span>
<?php if ($step['caption'] !== ''): ?>
              <p class="step__caption"><?= $h($step['caption']) ?></p>
<?php endif; ?>
            </div>
          </li>
<?php endforeach; ?>
        </ol>
      </section>
<?php endif; ?>
<?php if ($publicNote !== ''): ?>
      <section class="section" aria-labelledby="note-title">
        <h2 class="label" id="note-title">Notatka z serwisu</h2>
        <p class="note__text"><?= $h($publicNote) ?></p>
      </section>
<?php endif; ?>

      <section class="section contact" aria-labelledby="contact-title">
        <h2 class="label" id="contact-title">Kontakt</h2>
        <a class="phone" href="<?= $h($phoneHref) ?>"><?= $h($service['phone']) ?></a>
        <dl class="rows">
          <div class="row">
            <dt>Adres</dt>
            <dd><?= $h($service['address']) ?></dd>
          </div>
          <div class="row">
            <dt>Godziny</dt>
            <dd><?= $h($service['hours']) ?></dd>
          </div>
          <div class="row">
            <dt>Strona</dt>
            <dd><a class="rows__link" href="<?= $h($siteUrl) ?>" target="_blank" rel="noopener"><?= $h($siteHost) ?><span class="visually-hidden"> (otwiera się w nowej karcie)</span><span aria-hidden="true"> ↗</span></a></dd>
          </div>
        </dl>
      </section>
    </div>
  </div>
<?php endif; ?>
</main>

<footer class="foot">
  <div class="foot__inner">
<?php if ($status !== null): ?>
    <p class="foot__note">Dane aktualizują się na bieżąco - odśwież stronę, aby zobaczyć zmiany.</p>
<?php endif; ?>
    <p class="foot__meta">Studio-Komp · Kotlarska 14 · Wrocław</p>
  </div>
</footer>
</body>
</html>
