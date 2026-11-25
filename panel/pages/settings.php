<?php
/**
 * Ustawienia strony: kontakt, strona główna, menu, opinie, analityka,
 * formularz, SEO, cennik, FAQ. Zapis do plików JSON w repozytorium.
 */

$tab = (string) get('tab', 'contact');
$site = data_read('site');
$cennik = data_read('cennik');
$faq = data_read('faq');

if (is_post()) {
    csrf_check();
    $action = (string) post('action');

    if ($action === 'save_contact') {
        $site['phoneDisplay'] = trim((string) post('phoneDisplay'));
        $site['phoneTel'] = trim((string) post('phoneTel'));
        $site['email'] = trim((string) post('email'));
        foreach (['street', 'postalCode', 'city', 'district', 'label'] as $field) {
            $site['address'][$field] = trim((string) post($field));
        }
        $site['address']['lat'] = (float) post('lat');
        $site['address']['lng'] = (float) post('lng');
        $site['mapsUrl'] = trim((string) post('mapsUrl'));
        $site['directionsUrl'] = trim((string) post('directionsUrl'));
        $site['mapEmbedUrl'] = trim((string) post('mapEmbedUrl'));
        $site['founded'] = (int) post('founded');
        $site['express']['surcharge'] = (int) post('expressSurcharge');

        $hours = [];
        foreach ((array) post('hours', []) as $row) {
            if (trim((string) ($row['label'] ?? '')) === '') {
                continue;
            }
            $hours[] = [
                'label' => trim((string) $row['label']),
                'value' => trim((string) ($row['value'] ?? '')),
                'open' => !empty($row['open']),
            ];
        }
        if ($hours) {
            $site['hours'] = $hours;
        }
        data_write('site', $site, 'Panel: ustawienia kontaktu');
        flash('ok', 'Zapisano dane kontaktowe.');
        redirect_to(panel_url('settings', ['tab' => 'contact']));
    }

    if ($action === 'save_home') {
        $lines = preg_split('/\r?\n/', (string) post('titleLines'));
        $site['home']['hero']['titleLines'] = array_values(array_filter(array_map('trim', $lines)));
        $site['home']['hero']['lead'] = trim((string) post('lead'));
        $site['home']['hero']['ctaPrimary'] = trim((string) post('ctaPrimary'));
        $site['home']['hero']['ctaSecondary'] = trim((string) post('ctaSecondary'));

        $stats = [];
        foreach ((array) post('stats', []) as $row) {
            if (trim((string) ($row['value'] ?? '')) === '' && trim((string) ($row['label'] ?? '')) === '') {
                continue;
            }
            $stats[] = ['value' => trim((string) $row['value']), 'label' => trim((string) $row['label'])];
        }
        if ($stats) {
            $site['home']['stats'] = $stats;
        }
        $site['diagnosis']['title'] = trim((string) post('diagnosisTitle'));
        $site['diagnosis']['stat'] = trim((string) post('diagnosisStat'));
        $site['diagnosis']['text'] = trim((string) post('diagnosisText'));

        foreach (array_keys($site['home']['sections']) as $section) {
            $site['home']['sections'][$section] = !empty(post('sections')[$section]);
        }

        data_write('site', $site, 'Panel: strona główna');
        flash('ok', 'Zapisano ustawienia strony głównej.');
        redirect_to(panel_url('settings', ['tab' => 'home']));
    }

    if ($action === 'save_menu') {
        $main = [];
        foreach ((array) post('main', []) as $row) {
            if (trim((string) ($row['label'] ?? '')) === '') {
                continue;
            }
            $main[] = ['href' => trim((string) $row['href']), 'label' => trim((string) $row['label'])];
        }
        if ($main) {
            $site['nav']['main'] = $main;
        }
        $secondary = [];
        foreach ((array) post('secondary', []) as $row) {
            if (trim((string) ($row['label'] ?? '')) === '') {
                continue;
            }
            $secondary[] = ['href' => trim((string) $row['href']), 'label' => trim((string) $row['label'])];
        }
        if ($secondary) {
            $site['nav']['secondary'] = $secondary;
        }
        $site['footer']['description'] = trim((string) post('footerDescription'));
        $info = [];
        foreach ((array) post('footerLinks', []) as $row) {
            if (trim((string) ($row['label'] ?? '')) === '') {
                continue;
            }
            $info[] = ['href' => trim((string) $row['href']), 'label' => trim((string) $row['label'])];
        }
        if ($info) {
            $site['footer']['infoLinks'] = $info;
        }
        data_write('site', $site, 'Panel: menu i stopka');
        flash('ok', 'Zapisano menu i stopkę.');
        redirect_to(panel_url('settings', ['tab' => 'menu']));
    }

    if ($action === 'save_reviews') {
        $site['reviews']['rating'] = (float) post('rating');
        $site['reviews']['count'] = (int) post('count');
        $site['reviews']['countLabel'] = trim((string) post('countLabel'));
        $site['reviews']['placeId'] = trim((string) post('placeId'));
        $site['reviews']['url'] = trim((string) post('url'));
        $site['reviews']['placeUrl'] = trim((string) post('placeUrl'));
        $site['reviews']['writeUrl'] = trim((string) post('writeUrl'));
        data_write('site', $site, 'Panel: opinie Google');
        flash('ok', 'Zapisano dane opinii.');
        redirect_to(panel_url('settings', ['tab' => 'reviews']));
    }

    if ($action === 'save_analytics') {
        $site['analytics']['gtm'] = trim((string) post('gtm'));
        $site['analytics']['ga4'] = trim((string) post('ga4'));
        $site['analytics']['adsConversionSendTo'] = trim((string) post('ads'));
        $site['analytics']['clarity'] = trim((string) post('clarity'));
        $site['analytics']['firstParty'] = true;
        data_write('site', $site, 'Panel: identyfikatory analityki');
        flash('ok', 'Zapisano identyfikatory analityki. Zmiany zadziałają po publikacji.');
        redirect_to(panel_url('settings', ['tab' => 'analytics']));
    }

    if ($action === 'save_form') {
        $site['form']['provider'] = trim((string) post('provider'));
        $site['form']['endpoint'] = trim((string) post('endpoint'));
        $site['form']['accessKey'] = trim((string) post('accessKey'));
        data_write('site', $site, 'Panel: formularz kontaktowy');
        flash('ok', 'Zapisano ustawienia formularza.');
        redirect_to(panel_url('settings', ['tab' => 'form']));
    }

    if ($action === 'save_seo') {
        $site['seo']['defaultOgImage'] = trim((string) post('ogImage'));
        $site['seo']['titleTemplate'] = trim((string) post('titleTemplate'));
        data_write('site', $site, 'Panel: ustawienia SEO');
        flash('ok', 'Zapisano ustawienia SEO.');
        redirect_to(panel_url('settings', ['tab' => 'seo']));
    }

    if ($action === 'save_cennik') {
        $groups = [];
        foreach ((array) post('groups', []) as $group) {
            $title = trim((string) ($group['title'] ?? ''));
            $items = [];
            foreach ((array) ($group['items'] ?? []) as $item) {
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $items[] = [
                    'name' => $name,
                    'price' => trim((string) ($item['price'] ?? '')),
                    'note' => trim((string) ($item['note'] ?? '')),
                ];
            }
            if ($title !== '' && $items) {
                $groups[] = ['title' => $title, 'items' => $items];
            }
        }
        if ($groups) {
            $cennik['groups'] = $groups;
            data_write('cennik', $cennik, 'Panel: cennik');
            flash('ok', 'Zapisano cennik.');
        } else {
            flash('err', 'Cennik musi mieć co najmniej jedną grupę z pozycją.');
        }
        redirect_to(panel_url('settings', ['tab' => 'pricing']));
    }

    if ($action === 'save_faq') {
        $items = [];
        foreach ((array) post('faq', []) as $row) {
            $q = trim((string) ($row['q'] ?? ''));
            if ($q === '') {
                continue;
            }
            $items[] = ['q' => $q, 'a' => trim((string) ($row['a'] ?? ''))];
        }
        if ($items) {
            $faq['items'] = $items;
            data_write('faq', $faq, 'Panel: FAQ');
            flash('ok', 'Zapisano FAQ.');
        } else {
            flash('err', 'Dodaj co najmniej jedno pytanie.');
        }
        redirect_to(panel_url('settings', ['tab' => 'faq']));
    }
}

$tabs = [
    'contact' => 'Kontakt',
    'home' => 'Strona główna',
    'menu' => 'Menu i stopka',
    'reviews' => 'Opinie',
    'pricing' => 'Cennik',
    'faq' => 'FAQ',
    'analytics' => 'Analityka',
    'form' => 'Formularz',
    'seo' => 'SEO',
];

layout_head('Ustawienia', 'settings');
?>

<div class="tabs">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="<?= $tab === $key ? 'is-active' : '' ?>" href="<?= e(panel_url('settings', ['tab' => $key])) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'contact'): ?>
  <?php $address = $site['address'] ?? []; ?>
  <div class="grid grid--sidebar">
    <section class="card">
      <header class="card__head"><h2>Dane kontaktowe</h2></header>
      <div class="card__body">
        <form method="post" class="form-grid">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_contact">
          <div class="form-row">
            <label class="field"><span>Telefon (wyświetlany)</span><input type="text" name="phoneDisplay" value="<?= e($site['phoneDisplay'] ?? '') ?>" required></label>
            <label class="field"><span>Telefon (link tel:)</span><input type="text" name="phoneTel" value="<?= e($site['phoneTel'] ?? '') ?>" required></label>
          </div>
          <div class="form-row">
            <label class="field"><span>E-mail (opcjonalnie)</span><input type="text" name="email" value="<?= e($site['email'] ?? '') ?>"></label>
            <label class="field"><span>Rok założenia</span><input type="number" name="founded" value="<?= (int) ($site['founded'] ?? 2013) ?>"></label>
          </div>

          <p class="section-title">Adres</p>
          <div class="form-row">
            <label class="field"><span>Ulica i numer</span><input type="text" name="street" value="<?= e($address['street'] ?? '') ?>" required></label>
            <label class="field"><span>Kod pocztowy</span><input type="text" name="postalCode" value="<?= e($address['postalCode'] ?? '') ?>" required></label>
          </div>
          <div class="form-row form-row--3">
            <label class="field"><span>Miasto</span><input type="text" name="city" value="<?= e($address['city'] ?? '') ?>" required></label>
            <label class="field"><span>Dzielnica</span><input type="text" name="district" value="<?= e($address['district'] ?? '') ?>"></label>
            <label class="field"><span>Etykieta mapy</span><input type="text" name="label" value="<?= e($address['label'] ?? '') ?>"></label>
          </div>
          <div class="form-row">
            <label class="field"><span>Szerokość geogr.</span><input type="text" name="lat" value="<?= e((string) ($address['lat'] ?? '')) ?>"></label>
            <label class="field"><span>Długość geogr.</span><input type="text" name="lng" value="<?= e((string) ($address['lng'] ?? '')) ?>"></label>
          </div>

          <p class="section-title">Mapa i dojazd</p>
          <label class="field"><span>Link do wizytówki Google Maps</span><input type="text" name="mapsUrl" value="<?= e($site['mapsUrl'] ?? '') ?>"></label>
          <label class="field"><span>Link „Wyznacz trasę”</span><input type="text" name="directionsUrl" value="<?= e($site['directionsUrl'] ?? '') ?>"></label>
          <label class="field"><span>Adres osadzenia mapy (iframe)</span><input type="text" name="mapEmbedUrl" value="<?= e($site['mapEmbedUrl'] ?? '') ?>">
            <span class="field__hint">Google Maps embed nie wymaga klucza API w tym trybie (output=embed).</span>
          </label>

          <p class="section-title">Godziny otwarcia</p>
          <?php foreach ((array) ($site['hours'] ?? []) as $i => $hour): ?>
            <div class="form-row form-row--3">
              <label class="field"><span>Dni</span><input type="text" name="hours[<?= $i ?>][label]" value="<?= e($hour['label'] ?? '') ?>"></label>
              <label class="field"><span>Godziny</span><input type="text" name="hours[<?= $i ?>][value]" value="<?= e($hour['value'] ?? '') ?>"></label>
              <label class="field field--check" style="align-self:end"><input type="checkbox" name="hours[<?= $i ?>][open]" value="1" <?= !empty($hour['open']) ? 'checked' : '' ?>><span>Otwarte</span></label>
            </div>
          <?php endforeach; ?>

          <label class="field" style="max-width:280px"><span>Dopłata za ekspres (zł)</span><input type="number" name="expressSurcharge" value="<?= (int) ($site['express']['surcharge'] ?? 150) ?>"></label>

          <div class="form-actions">
            <button class="btn btn--primary" type="submit">Zapisz dane kontaktowe</button>
          </div>
        </form>
      </div>
    </section>
    <section class="card">
      <header class="card__head"><h2>Podpowiedź</h2></header>
      <div class="card__body">
        <p class="small muted">
          Telefon i adres pojawiają się w nagłówku, stopce, na stronie kontaktu, w mapie i w danych
          strukturalnych Google (LocalBusiness). Zmień je tutaj raz — zaktualizują się wszędzie
          po publikacji.
        </p>
      </div>
    </section>
  </div>
<?php endif; ?>

<?php if ($tab === 'home'): ?>
  <?php $hero = $site['home']['hero'] ?? []; ?>
  <div class="grid grid--sidebar">
    <section class="card">
      <header class="card__head"><h2>Hero strony głównej</h2></header>
      <div class="card__body">
        <form method="post" class="form-grid">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_home">
          <label class="field"><span>Nagłówek H1 (każda linia osobno)</span>
            <textarea name="titleLines" rows="3"><?= e(implode("\n", $hero['titleLines'] ?? [])) ?></textarea>
          </label>
          <label class="field"><span>Opis pod nagłówkiem</span>
            <textarea name="lead" rows="2"><?= e($hero['lead'] ?? '') ?></textarea>
          </label>
          <div class="form-row">
            <label class="field"><span>Przycisk główny</span><input type="text" name="ctaPrimary" value="<?= e($hero['ctaPrimary'] ?? '') ?>"></label>
            <label class="field"><span>Przycisk drugi</span><input type="text" name="ctaSecondary" value="<?= e($hero['ctaSecondary'] ?? '') ?>"></label>
          </div>

          <p class="section-title">Statystyki (4 kafle)</p>
          <?php foreach ((array) ($site['home']['stats'] ?? []) as $i => $stat): ?>
            <div class="form-row">
              <label class="field"><span>Wartość <?= $i + 1 ?></span><input type="text" name="stats[<?= $i ?>][value]" value="<?= e($stat['value'] ?? '') ?>"></label>
              <label class="field"><span>Etykieta</span><input type="text" name="stats[<?= $i ?>][label]" value="<?= e($stat['label'] ?? '') ?>"></label>
            </div>
          <?php endforeach; ?>

          <p class="section-title">Komunikat o diagnozie</p>
          <label class="field"><span>Tytuł</span><input type="text" name="diagnosisTitle" value="<?= e($site['diagnosis']['title'] ?? '') ?>"></label>
          <div class="form-row">
            <label class="field"><span>Wartość w statystykach</span><input type="text" name="diagnosisStat" value="<?= e($site['diagnosis']['stat'] ?? '') ?>"></label>
          </div>
          <label class="field"><span>Pełne wyjaśnienie</span>
            <textarea name="diagnosisText" rows="4"><?= e($site['diagnosis']['text'] ?? '') ?></textarea>
          </label>

          <p class="section-title">Widoczność sekcji</p>
          <div class="grid grid--3" style="gap:8px">
            <?php foreach (($site['home']['sections'] ?? []) as $section => $visible): ?>
              <label class="field field--check"><input type="checkbox" name="sections[<?= e($section) ?>]" value="1" <?= $visible ? 'checked' : '' ?>><span><?= e($section) ?></span></label>
            <?php endforeach; ?>
          </div>

          <div class="form-actions"><button class="btn btn--primary" type="submit">Zapisz stronę główną</button></div>
        </form>
      </div>
    </section>
    <section class="card">
      <header class="card__head"><h2>Jak wygląda teraz</h2></header>
      <div class="card__body">
        <p class="small"><strong>H1:</strong> <?= e(implode(' ', $hero['titleLines'] ?? [])) ?></p>
        <p class="small muted" style="margin-top:6px"><?= e($hero['lead'] ?? '') ?></p>
        <p class="small" style="margin-top:10px">Telefon: <?= e($site['phoneDisplay'] ?? '') ?></p>
        <p class="small muted">Diagnoza: <?= e($site['diagnosis']['title'] ?? '') ?></p>
      </div>
    </section>
  </div>
<?php endif; ?>

<?php if ($tab === 'menu'): ?>
  <section class="card">
    <header class="card__head"><h2>Menu główne</h2></header>
    <div class="card__body">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_menu">
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Etykieta</th><th>Adres</th></tr></thead>
            <tbody>
            <?php foreach ((array) ($site['nav']['main'] ?? []) as $i => $item): ?>
              <tr>
                <td><input type="text" name="main[<?= $i ?>][label]" value="<?= e($item['label'] ?? '') ?>" style="width:100%"></td>
                <td><input type="text" name="main[<?= $i ?>][href]" value="<?= e($item['href'] ?? '') ?>" style="width:100%"></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="section-title">Menu dodatkowe (wysuwane)</p>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Etykieta</th><th>Adres</th></tr></thead>
            <tbody>
            <?php foreach ((array) ($site['nav']['secondary'] ?? []) as $i => $item): ?>
              <tr>
                <td><input type="text" name="secondary[<?= $i ?>][label]" value="<?= e($item['label'] ?? '') ?>" style="width:100%"></td>
                <td><input type="text" name="secondary[<?= $i ?>][href]" value="<?= e($item['href'] ?? '') ?>" style="width:100%"></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <p class="section-title">Stopka</p>
        <label class="field"><span>Opis w stopce</span><textarea name="footerDescription" rows="2"><?= e($site['footer']['description'] ?? '') ?></textarea></label>
        <div class="table-wrap">
          <table class="table table--compact">
            <thead><tr><th>Etykieta</th><th>Adres</th></tr></thead>
            <tbody>
            <?php foreach ((array) ($site['footer']['infoLinks'] ?? []) as $i => $item): ?>
              <tr>
                <td><input type="text" name="footerLinks[<?= $i ?>][label]" value="<?= e($item['label'] ?? '') ?>" style="width:100%"></td>
                <td><input type="text" name="footerLinks[<?= $i ?>][href]" value="<?= e($item['href'] ?? '') ?>" style="width:100%"></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="form-actions"><button class="btn btn--primary" type="submit">Zapisz menu i stopkę</button></div>
      </form>
    </div>
  </section>
<?php endif; ?>

<?php if ($tab === 'reviews'): ?>
  <section class="card">
    <header class="card__head"><h2>Opinie Google</h2></header>
    <div class="card__body">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_reviews">
        <div class="form-row">
          <label class="field"><span>Ocena (np. 4.9)</span><input type="text" name="rating" value="<?= e((string) ($site['reviews']['rating'] ?? '')) ?>"></label>
          <label class="field"><span>Liczba opinii</span><input type="number" name="count" value="<?= (int) ($site['reviews']['count'] ?? 0) ?>"></label>
        </div>
        <label class="field"><span>Etykieta liczby (np. 550+)</span><input type="text" name="countLabel" value="<?= e($site['reviews']['countLabel'] ?? '') ?>"></label>
        <label class="field"><span>Place ID</span><input type="text" name="placeId" value="<?= e($site['reviews']['placeId'] ?? '') ?>"></label>
        <label class="field"><span>Link „Opinie Google” (publiczny)</span><input type="text" name="url" value="<?= e($site['reviews']['url'] ?? '') ?>">
          <span class="field__hint">Wszystkie przyciski opinii prowadzą do tego adresu. Nie zmieniaj go na formularz wystawiania.</span>
        </label>
        <label class="field"><span>Link do wizytówki</span><input type="text" name="placeUrl" value="<?= e($site['reviews']['placeUrl'] ?? '') ?>"></label>
        <label class="field"><span>Link „Dodaj opinię”</span><input type="text" name="writeUrl" value="<?= e($site['reviews']['writeUrl'] ?? '') ?>"></label>
        <div class="form-actions"><button class="btn btn--primary" type="submit">Zapisz opinie</button></div>
      </form>
    </div>
  </section>
<?php endif; ?>

<?php if ($tab === 'pricing'): ?>
  <section class="card">
    <header class="card__head"><h2>Cennik</h2></header>
    <div class="card__body">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_cennik">
        <?php foreach ((array) ($cennik['groups'] ?? []) as $gi => $group): ?>
          <div class="card" style="border-color:var(--a-line)">
            <div class="card__body">
              <label class="field"><span>Nazwa grupy</span><input type="text" name="groups[<?= $gi ?>][title]" value="<?= e($group['title'] ?? '') ?>"></label>
              <div class="table-wrap" style="margin-top:10px">
                <table class="table table--compact">
                  <thead><tr><th>Nazwa</th><th>Cena</th><th>Uwaga</th></tr></thead>
                  <tbody>
                  <?php foreach ((array) ($group['items'] ?? []) as $ii => $item): ?>
                    <tr>
                      <td><input type="text" name="groups[<?= $gi ?>][items][<?= $ii ?>][name]" value="<?= e($item['name'] ?? '') ?>" style="width:100%"></td>
                      <td><input type="text" name="groups[<?= $gi ?>][items][<?= $ii ?>][price]" value="<?= e($item['price'] ?? '') ?>" style="width:110px"></td>
                      <td><input type="text" name="groups[<?= $gi ?>][items][<?= $ii ?>][note]" value="<?= e($item['note'] ?? '') ?>" style="width:100%"></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <div class="help-box">
          Aby dodać nową pozycję, skopiuj w pliku <span class="code-inline">src/data/cennik.json</span> istniejący wpis
          (panel obsługuje edycję istniejących pozycji; nowe grupy dodasz przez edytor JSON lub zgłoś to
          programiście). Ceny nieznane zostaw jako „… zł” — strona nie wymyśla kwot.
        </div>
        <div class="form-actions"><button class="btn btn--primary" type="submit">Zapisz cennik</button></div>
      </form>
    </div>
  </section>
<?php endif; ?>

<?php if ($tab === 'faq'): ?>
  <section class="card">
    <header class="card__head"><h2>FAQ (strona główna)</h2></header>
    <div class="card__body">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_faq">
        <?php foreach ((array) ($faq['items'] ?? []) as $i => $item): ?>
          <div class="form-row">
            <label class="field"><span>Pytanie <?= $i + 1 ?></span><input type="text" name="faq[<?= $i ?>][q]" value="<?= e($item['q'] ?? '') ?>"></label>
            <label class="field"><span>Odpowiedź</span><textarea name="faq[<?= $i ?>][a]" rows="2"><?= e($item['a'] ?? '') ?></textarea></label>
          </div>
        <?php endforeach; ?>
        <p class="small muted">Pytania generują też dane strukturalne FAQPage w Google. Odpowiedzi muszą być zgodne z rzeczywistością.</p>
        <div class="form-actions"><button class="btn btn--primary" type="submit">Zapisz FAQ</button></div>
      </form>
    </div>
  </section>
<?php endif; ?>

<?php if ($tab === 'analytics'): ?>
  <section class="card">
    <header class="card__head"><h2>Google Analytics / Ads / Clarity</h2></header>
    <div class="card__body">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_analytics">
        <label class="field"><span>GTM ID (np. GTM-XXXXXXX)</span><input type="text" name="gtm" value="<?= e($site['analytics']['gtm'] ?? '') ?>">
          <span class="field__hint">Zalecane: wstrzykuj GA4 i Ads przez GTM. Zostaw puste, jeśli nie używasz.</span>
        </label>
        <label class="field"><span>GA4 Measurement ID (np. G-XXXXXXX)</span><input type="text" name="ga4" value="<?= e($site['analytics']['ga4'] ?? '') ?>">
          <span class="field__hint">Używane tylko, jeśli GTM jest pusty.</span>
        </label>
        <label class="field"><span>Google Ads — send_to konwersji</span><input type="text" name="ads" value="<?= e($site['analytics']['adsConversionSendTo'] ?? '') ?>">
          <span class="field__hint">Format AW-XXXXXXX/etykieta. Konwersja „generate_lead” wysyłana po potwierdzonym formularzu.</span>
        </label>
        <label class="field"><span>Microsoft Clarity ID</span><input type="text" name="clarity" value="<?= e($site['analytics']['clarity'] ?? '') ?>">
          <span class="field__hint">Ładowany dopiero po zgodzie analitycznej. Puste = nieaktywny.</span>
        </label>
        <div class="help-box">
          Własny pomiar działa zawsze (bez GA4) i zapisuje zdarzenia w bazie.
          Narzędzia zewnętrzne uruchamiają się zgodnie z Consent Mode v2 — dopiero po zgodzie użytkownika.
        </div>
        <div class="form-actions"><button class="btn btn--primary" type="submit">Zapisz analitykę</button></div>
      </form>
    </div>
  </section>
<?php endif; ?>

<?php if ($tab === 'form'): ?>
  <section class="card">
    <header class="card__head"><h2>Formularz zgłoszenia</h2></header>
    <div class="card__body">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_form">
        <label class="field"><span>Dostawca</span>
          <select name="provider">
            <?php foreach (['web3forms' => 'Web3Forms', 'netlify' => 'Netlify Forms', 'custom' => 'Własny endpoint'] as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= ($site['form']['provider'] ?? 'web3forms') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field"><span>Endpoint</span><input type="text" name="endpoint" value="<?= e($site['form']['endpoint'] ?? '') ?>"></label>
        <label class="field"><span>Klucz dostępu (sekret)</span><input type="password" name="accessKey" value="<?= e($site['form']['accessKey'] ?? '') ?>">
          <span class="field__hint">Uwaga: klucz trafia do publicznego HTML (tak działa Web3Forms). Nie jest to hasło serwerowe.</span>
        </label>
        <div class="form-actions"><button class="btn btn--primary" type="submit">Zapisz formularz</button></div>
      </form>
    </div>
  </section>
<?php endif; ?>

<?php if ($tab === 'seo'): ?>
  <section class="card">
    <header class="card__head"><h2>SEO</h2></header>
    <div class="card__body">
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_seo">
        <label class="field"><span>Domyślny obraz OG</span><input type="text" name="ogImage" value="<?= e($site['seo']['defaultOgImage'] ?? '') ?>"></label>
        <label class="field"><span>Szablon tytułu</span><input type="text" name="titleTemplate" value="<?= e($site['seo']['titleTemplate'] ?? '') ?>"></label>
        <div class="form-actions"><button class="btn btn--primary" type="submit">Zapisz SEO</button></div>
      </form>
    </div>
  </section>
<?php endif; ?>

<?php layout_foot(); ?>
