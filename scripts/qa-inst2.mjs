// Testy instrukcji nr 2: logo, favicony, kafelki, ceny, myślniki, realizacje, stopka.
// Uruchomienie: node scripts/qa-inst2.mjs [baseUrl]
import { chromium } from 'playwright-core';
import fs from 'node:fs';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:4321';
const results = [];
let failed = 0;

function check(name, ok, detail = '') {
  results.push({ name, ok: Boolean(ok), detail: String(detail) });
  if (!ok) failed++;
}

const realizacje = JSON.parse(fs.readFileSync('src/data/realizacje.json', 'utf8'));
const visible = realizacje.items.filter((i) => !i.draft);
const categoryCounts = {};
for (const item of visible) categoryCounts[item.category] = (categoryCounts[item.category] || 0) + 1;

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const errors = [];

async function newPage(opts = {}) {
  const context = await browser.newContext({
    viewport: opts.viewport || { width: 1440, height: 900 },
    isMobile: Boolean(opts.mobile),
    hasTouch: Boolean(opts.mobile),
    deviceScaleFactor: opts.mobile ? 2 : 1,
    locale: 'pl-PL',
  });
  await context.addInitScript(() => {
    try {
      localStorage.setItem(
        'sk_consent_v2',
        JSON.stringify({ v: 2, necessary: true, analytics: true, marketing: false, functional: true, ts: new Date().toISOString() })
      );
    } catch {}
  });
  const page = await context.newPage();
  page.on('pageerror', (e) => errors.push('pageerror: ' + String(e)));
  page.on('console', (m) => {
    if (m.type() === 'error' && !m.text().includes('favicon')) errors.push('console: ' + m.text());
  });
  page.on('response', (r) => {
    if (r.status() >= 400 && !r.url().includes('google') && !r.url().endsWith('/api/e') && !r.url().endsWith('/api/form-token')) {
      errors.push(`HTTP ${r.status()} ${r.url()}`);
    }
  });
  return page;
}

const DASHES = /[\u2013\u2014]/;

/* ---------------- HOME: desktop ---------------- */
{
  const page = await newPage();
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const data = await page.evaluate(() => {
    const logo = document.querySelector('.site-header .logo__img');
    const lr = logo.getBoundingClientRect();
    const header = document.querySelector('.site-header').getBoundingClientRect();
    const tiles = [...document.querySelectorAll('[data-service-tile]')];
    const rows = tiles.map((t) => Math.round(t.getBoundingClientRect().left));
    const firstPanel = document.querySelector('[data-service-tile] .service-tile__panel');
    const panelVisible = firstPanel
      ? getComputedStyle(firstPanel).visibility !== 'hidden' && Number(getComputedStyle(firstPanel).opacity) > 0.1
      : false;
    return {
      logo: [Math.round(lr.width), Math.round(lr.height)],
      headerH: Math.round(header.height),
      scrollW: document.documentElement.scrollWidth,
      innerW: window.innerWidth,
      tiles: tiles.length,
      rows,
      panelVisible,
      dashCount: (document.body.innerText.match(/[\u2013\u2014]/g) || []).length,
      text: document.body.innerText,
      links: [...document.querySelectorAll('[data-service-tile] a[href]')].map((a) => a.getAttribute('href')),
    };
  });
  check('logo desktop >= 36px wysokości', data.logo[1] >= 36, JSON.stringify(data.logo));
  check('logo zachowuje proporcje (szer/wys ~5.6)', Math.abs(data.logo[0] / data.logo[1] - 1386 / 246) < 0.15, JSON.stringify(data.logo));
  check('brak poziomego przewijania (desktop)', data.scrollW === data.innerW, `${data.scrollW}/${data.innerW}`);
  check('kafelków usług = 9', data.tiles === 9, data.tiles);
  const rowCount = new Set(data.rows).size;
  check('kafelki: 3 kolumny na desktopie', data.rows.length === 9 && data.rows.filter((x) => x === data.rows[0]).length === 3, JSON.stringify(data.rows));
  check('kafelek domyślnie bez opisu', !data.panelVisible, String(data.panelVisible));
  check('kafelki linkują do usług', data.links.length >= 9 && data.links.every((h) => h && h.startsWith('/')), data.links.slice(0, 3).join(','));
  check('brak długich myślników na stronie głównej', data.dashCount === 0, data.dashCount);
  check('cena czyszczenia 200-250 zł', data.text.includes('200-250 zł'));
  check('cena instalacji 250 zł', data.text.includes('Instalacja systemu') || data.text.includes('Instalacja i konfiguracja systemu'));
  check('brak starej ceny "od 200 zł"', !data.text.includes('od 200 zł'));
  check('brak słowa "kierownik"', !/kierownik/i.test(data.text));
  check('cena matrycy 600-700 zł', data.text.includes('600-700 zł'));

  // hover na kafelku: opis widoczny
  const tile = page.locator('[data-service-tile]').nth(1);
  await tile.hover();
  await page.waitForTimeout(350);
  const hoverState = await page.evaluate(() => {
    const t = document.querySelectorAll('[data-service-tile]')[1];
    const panel = t.querySelector('.service-tile__panel');
    const img = t.querySelector('img');
    const r = t.getBoundingClientRect();
    const overflow = document.documentElement.scrollWidth > window.innerWidth;
    return {
      panelVisible: panel ? getComputedStyle(panel).opacity !== '0' && panel.getBoundingClientRect().height > 4 : false,
      brightness: img ? getComputedStyle(img).filter : '',
      z: getComputedStyle(t).zIndex,
      rect: [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)],
      overflow,
    };
  });
  check('hover pokazuje opis procesu', hoverState.panelVisible, JSON.stringify(hoverState));
  check('hover przyciemnia zdjęcie', /brightness\(/.test(hoverState.brightness), hoverState.brightness);
  check('hover nie powoduje poziomego przewijania', !hoverState.overflow, JSON.stringify(hoverState.rect));

  // klawiatura: Tab do przycisku kafelka i Enter rozwija
  const toggle = page.locator('[data-service-tile] .service-tile__toggle').nth(2);
  await toggle.focus();
  await page.keyboard.press('Enter');
  await page.waitForTimeout(200);
  const kb = await page.evaluate(() => {
    const t = document.querySelectorAll('[data-service-tile]')[2];
    const b = t.querySelector('.service-tile__toggle');
    const panel = t.querySelector('.service-tile__panel');
    return { expanded: b.getAttribute('aria-expanded'), visible: panel.getBoundingClientRect().height > 4 };
  });
  check('kafelek działa z klawiatury', kb.expanded === 'true' && kb.visible, JSON.stringify(kb));

  // stopka desktop: 9 marek, brak przewijania
  const footer = await page.evaluate(() => {
    const marks = document.querySelectorAll('.site-footer__marki a').length;
    const overflow = document.documentElement.scrollWidth > window.innerWidth;
    return { marks, overflow, h: Math.round(document.querySelector('.site-footer').getBoundingClientRect().height) };
  });
  check('stopka: 9 marek w dwóch kolumnach', footer.marks === 9, footer.marks);
  check('stopka: brak poziomego przewijania', !footer.overflow);
  await page.context().close();
}

/* ---------------- HOME: mobile ---------------- */
{
  const page = await newPage({ viewport: { width: 390, height: 844 }, mobile: true });
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  const data = await page.evaluate(() => {
    const tiles = [...document.querySelectorAll('[data-service-tile]')];
    const visibleTiles = tiles.filter((t) => t.getBoundingClientRect().height > 0);
    const logo = document.querySelector('.site-header .logo__img').getBoundingClientRect();
    const footerBtns = [...document.querySelectorAll('[data-accordion-toggle]')];
    const contact = document.querySelector('.site-footer__col--contact');
    return {
      screens: +(document.documentElement.scrollHeight / window.innerHeight).toFixed(2),
      scrollW: document.documentElement.scrollWidth,
      innerW: window.innerWidth,
      visibleTiles: visibleTiles.length,
      logo: [Math.round(logo.width), Math.round(logo.height)],
      footerClosed: footerBtns.map((b) => b.getAttribute('aria-expanded')),
      contactVisible: contact ? contact.getBoundingClientRect().height > 30 : false,
      dashCount: (document.body.innerText.match(/[\u2013\u2014]/g) || []).length,
    };
  });
  check('mobile: <= 5 ekranów', data.screens <= 5.0, data.screens);
  check('mobile: brak poziomego przewijania', data.scrollW === data.innerW, `${data.scrollW}/${data.innerW}`);
  check('mobile: 6 kafelków usług', data.visibleTiles === 6, data.visibleTiles);
  check('mobile: logo >= 28px', data.logo[1] >= 28, JSON.stringify(data.logo));
  check('stopka mobile: akordeon domyślnie zwinięty', data.footerClosed.length >= 3 && data.footerClosed.every((v) => v === 'false'), JSON.stringify(data.footerClosed));
  check('stopka mobile: kontakt widoczny', data.contactVisible);
  check('mobile: brak długich myślników', data.dashCount === 0, data.dashCount);

  // tap na kafelku rozwija i zwija opis
  const tile = page.locator('[data-service-tile]').first();
  await tile.locator('.service-tile__toggle').tap();
  await page.waitForTimeout(250);
  const opened = await page.evaluate(() => {
    const t = document.querySelector('[data-service-tile]');
    const panel = t.querySelector('.service-tile__panel');
    const cta = t.querySelector('.service-tile__cta');
    return { expanded: t.querySelector('.service-tile__toggle').getAttribute('aria-expanded'), panelH: Math.round(panel.getBoundingClientRect().height), ctaVisible: Boolean(cta) };
  });
  check('mobile: klik rozwija opis', opened.expanded === 'true' && opened.panelH > 10, JSON.stringify(opened));
  await tile.locator('.service-tile__toggle').tap();
  await page.waitForTimeout(250);
  const closed = await page.evaluate(() => document.querySelector('[data-service-tile] .service-tile__toggle').getAttribute('aria-expanded'));
  check('mobile: drugi klik zwija opis', closed === 'false', String(closed));

  // akordeon stopki
  const markiBtn = page.locator('[data-accordion-toggle]', { hasText: 'Marki' });
  await markiBtn.scrollIntoViewIfNeeded();
  await markiBtn.tap();
  await page.waitForTimeout(250);
  const acc = await page.evaluate(() => ({
    expanded: document.querySelectorAll('[data-accordion-toggle]')[2].getAttribute('aria-expanded'),
    marks: [...document.querySelectorAll('.site-footer__marki a')].filter((a) => a.getBoundingClientRect().height > 0).length,
    overflow: document.documentElement.scrollWidth > window.innerWidth,
  }));
  check('stopka mobile: rozwijanie listy marek', acc.expanded === 'true' && acc.marks === 9, JSON.stringify(acc));
  check('stopka mobile: brak przewijania po rozwinięciu', !acc.overflow);
  await page.context().close();
}

/* ---------------- REALIZACJE: filtry i siatka ---------------- */
for (const vp of [
  { name: 'desktop', width: 1440, cols: 3 },
  { name: 'tablet', width: 768, cols: 2 },
  { name: 'mobile', width: 390, cols: 1 },
]) {
  const page = await newPage({ viewport: { width: vp.width, height: 900 }, mobile: vp.name === 'mobile' });
  await page.goto(BASE + '/realizacje/', { waitUntil: 'networkidle' });
  const base = await page.evaluate(() => ({
    cases: document.querySelectorAll('.case').length,
    filterHidden: document.querySelector('[data-cases-filter]')?.hidden ?? null,
    buttons: document.querySelectorAll('[data-filter]').length,
    dash: (document.body.innerText.match(/[\u2013\u2014]/g) || []).length,
    links: [...document.querySelectorAll('.case a[href]')].map((a) => a.getAttribute('href')),
  }));
  check(`realizacje ${vp.name}: 7 kafelków`, base.cases === 7, base.cases);
  check(`realizacje ${vp.name}: filtry widoczne (JS)`, base.filterHidden === false, String(base.filterHidden));
  check(`realizacje ${vp.name}: 9 przycisków filtrowania`, base.buttons === 9, base.buttons);
  check(`realizacje ${vp.name}: wszystkie kafelki linkują`, base.links.length === 7, base.links.length);
  check(`realizacje ${vp.name}: brak długich myślników`, base.dash === 0, base.dash);

  // kolumny
  const cols = await page.evaluate(() => {
    const visible = [...document.querySelectorAll('.case')].filter((c) => c.getBoundingClientRect().height > 0);
    const lefts = visible.map((c) => Math.round(c.getBoundingClientRect().left));
    const unique = [...new Set(lefts)];
    return { row: unique.length, lefts };
  });
  check(`realizacje ${vp.name}: ${vp.cols} kolumny`, cols.row === vp.cols, JSON.stringify(cols.lefts));

  // każdy filtr
  const slugs = ['all', ...Object.keys(categoryCounts)];
  for (const slug of slugs) {
    await page.locator(`[data-filter="${slug}"]`).click();
    await page.waitForTimeout(120);
    const state = await page.evaluate(() => {
      const visible = [...document.querySelectorAll('.case')].filter((c) => !c.hidden && c.getBoundingClientRect().height > 0);
      return {
        count: visible.length,
        categories: [...new Set(visible.map((c) => c.dataset.category))],
        active: [...document.querySelectorAll('[data-filter]')].filter((b) => b.classList.contains('is-active')).length,
        pressed: document.querySelectorAll('[data-filter][aria-pressed="true"]').length,
      };
    });
    const expected = slug === 'all' ? 7 : (categoryCounts[slug] || 0);
    const catsOk = slug === 'all' || state.categories.every((c) => c === slug);
    check(`realizacje ${vp.name}: filtr ${slug} = ${expected}`, state.count === expected && catsOk && state.active === 1 && state.pressed === 1, JSON.stringify(state));
  }
  await page.context().close();
}

/* ---------------- SZCZEGÓŁY REALIZACJI ---------------- */
{
  const slug = visible[0].slug;
  const page = await newPage();
  await page.goto(`${BASE}/realizacje/${slug}/`, { waitUntil: 'networkidle' });
  const d = await page.evaluate(() => {
    const c = document.querySelector('main .container');
    const p = document.querySelector('.case-block p');
    const grid = document.querySelector('.case-detail__grid');
    return {
      containerW: Math.round(c.getBoundingClientRect().width),
      pW: p ? Math.round(p.getBoundingClientRect().width) : 0,
      cols: grid ? getComputedStyle(grid).gridTemplateColumns.split(' ').length : 0,
      overflow: document.documentElement.scrollWidth > window.innerWidth,
      h1: document.querySelector('h1')?.textContent.trim().slice(0, 40),
    };
  });
  check('szczegóły desktop: kontener > 1100px', d.containerW > 1100, d.containerW);
  check('szczegóły desktop: układ 2 kolumn', d.cols === 2, d.cols);
  check('szczegóły desktop: akapit <= 75ch', d.pW <= 900, d.pW);
  check('szczegóły desktop: brak przewijania', !d.overflow);
  check('szczegóły: H1 istnieje', Boolean(d.h1), d.h1);
  await page.context().close();

  const pageM = await newPage({ viewport: { width: 390, height: 844 }, mobile: true });
  await pageM.goto(`${BASE}/realizacje/${slug}/`, { waitUntil: 'networkidle' });
  const m = await pageM.evaluate(() => {
    const grid = document.querySelector('.case-detail__grid');
    const c = document.querySelector('main .container');
    return {
      cols: grid ? getComputedStyle(grid).gridTemplateColumns.split(' ').length : 0,
      containerW: Math.round(c.getBoundingClientRect().width),
      overflow: document.documentElement.scrollWidth > window.innerWidth,
    };
  });
  check('szczegóły mobile: 1 kolumna', m.cols === 1, m.cols);
  check('szczegóły mobile: kontener <= 390', m.containerW <= 390, m.containerW);
  check('szczegóły mobile: brak przewijania', !m.overflow);
  await pageM.context().close();
}

/* ---------------- CENNIK, PORADNIK, IKONY ---------------- */
{
  const page = await newPage();
  await page.goto(BASE + '/cennik/', { waitUntil: 'networkidle' });
  const c = await page.evaluate(() => ({
    text: document.body.innerText,
    dash: (document.body.innerText.match(/[\u2013\u2014]/g) || []).length,
    meta: document.querySelector('meta[name="description"]')?.getAttribute('content') || '',
  }));
  check('cennik: czyszczenie 200-250 zł', c.text.includes('200-250 zł'));
  check('cennik: czyszczenie stacjonarnego 200-250 zł', (c.text.match(/200-250 zł/g) || []).length >= 2, (c.text.match(/200-250 zł/g) || []).length);
  check('cennik: instalacja 250 zł', /Instalacja i konfiguracja systemu[^]*?250 zł/.test(c.text));
  check('cennik: matryca 600-700 zł', c.text.includes('600-700 zł'));
  check('cennik: brak "kierownik"', !/kierownik/i.test(c.text));
  check('cennik: brak długich myślników', c.dash === 0, c.dash);
  check('cennik: meta description bez długich myślników', !DASHES.test(c.meta), c.meta);

  await page.goto(BASE + '/poradnik/', { waitUntil: 'networkidle' });
  const p = await page.evaluate(() => ({
    cards: document.querySelectorAll('article, .post-card, .poradnik-card, [data-post]').length,
    links: document.querySelectorAll('a[href^="/poradnik/"]').length,
    dash: (document.body.innerText.match(/[\u2013\u2014]/g) || []).length,
  }));
  check('poradnik: lista linków > 15', p.links >= 15, p.links);
  check('poradnik: brak długich myślników', p.dash === 0, p.dash);

  // ikony
  const iconPaths = [
    '/favicon.ico?v=2',
    '/favicon-32.png?v=2',
    '/favicon-16.png?v=2',
    '/favicon.svg?v=2',
    '/apple-touch-icon.png?v=2',
    '/icon-192.png',
    '/icon-512.png',
    '/site.webmanifest',
  ];
  for (const path of iconPaths) {
    const res = await page.request.get(BASE + path);
    check(`ikona ${path}`, res.status() === 200, res.status());
  }
  const manifest = await (await page.request.get(BASE + '/site.webmanifest')).json().catch(() => null);
  check('manifest: ikony 192/512', Boolean(manifest && manifest.icons && manifest.icons.length >= 2), JSON.stringify(manifest?.icons?.map((i) => i.sizes)));
  await page.context().close();
}

await browser.close();

const report = {
  total: results.length,
  failed,
  errors: [...new Set(errors)].slice(0, 20),
  results,
};
fs.writeFileSync('shots/inst2/qa-inst2.json', JSON.stringify(report, null, 2));
for (const r of results) {
  if (!r.ok) console.log('FAIL', r.name, r.detail);
}
console.log(`\n${results.length - failed}/${results.length} testów OK, błędów JS/HTTP: ${report.errors.length}`);
if (report.errors.length) console.log('errors:', report.errors);
process.exit(failed ? 1 : 0);
