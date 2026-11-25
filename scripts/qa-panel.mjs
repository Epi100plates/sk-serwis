// Smoke test panelu CMS na żywej stronie (Playwright + Chrome systemowy).
// Wymaga zmiennych PANEL_USER i PANEL_PASS. Nie zapisuje hasła.
import { chromium } from 'playwright-core';
import { mkdirSync } from 'node:fs';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.SITE_URL || 'https://sk.mati-it.pl';
const OUT = process.env.SHOTS_DIR || 'shots/panel';
const USER = process.env.PANEL_USER || '';
const PASS = process.env.PANEL_PASS || '';
if (!USER || !PASS) {
  console.error('Brak PANEL_USER / PANEL_PASS');
  process.exit(1);
}
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'pl-PL' });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push('pageerror: ' + String(e)));
page.on('console', (m) => {
  if (m.type() === 'error') errors.push('console: ' + m.text());
});
page.on('response', (r) => {
  if (r.status() >= 500 && r.url().includes('/panel')) errors.push('HTTP ' + r.status() + ' ' + r.url());
});

await page.goto(BASE + '/panel/', { waitUntil: 'domcontentloaded' });
await page.fill('input[name=username]', USER);
await page.fill('input[name=password]', PASS);
await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('button[type=submit]')]);

const result = { user: USER, url: page.url(), pages: {}, errors };

const pages = [
  ['', 'dashboard'],
  ['analytics', 'analytics'],
  ['content/uslugi', 'content-uslugi'],
  ['content/blog', 'content-blog'],
  ['editor/blog', 'editor-blog'],
  ['realizacje', 'realizacje'],
  ['kategorie', 'kategorie'],
  ['media', 'media'],
  ['redirects', 'redirects'],
  ['reviews', 'reviews'],
  ['settings', 'settings'],
  ['publish', 'publish'],
  ['preview', 'preview'],
  ['users', 'users'],
  ['profile', 'profile'],
  ['audit', 'audit'],
  ['help', 'help'],
];

for (const [path, name] of pages) {
  const resp = await page.goto(BASE + '/panel/' + path, { waitUntil: 'domcontentloaded' });
  const title = await page.title();
  const bodyText = await page.evaluate(() => document.body.innerText.slice(0, 200));
  const hasError = /Fatal error|Warning:|Notice:|Uncaught|Błąd krytyczny/i.test(bodyText);
  result.pages[name] = { status: resp?.status(), title, hasError };
  if (hasError) errors.push(`PHP error on ${name}: ${bodyText.slice(0, 200)}`);
  if (path === '' || path === 'analytics' || path === 'content/blog' || path === 'realizacje' || path === 'settings' || path === 'publish') {
    await page.screenshot({ path: `${OUT}/panel-${name}.png`, fullPage: true, animations: 'disabled' });
  }
}

// Test edytora: otwórz istniejący artykuł i sprawdź, czy formularz się renderuje
await page.goto(BASE + '/panel/editor/blog/czy-warto-naprawiac-laptopa', { waitUntil: 'domcontentloaded' });
result.editor = await page.evaluate(() => ({
  hasTitleField: !!document.querySelector('input[name=title]'),
  hasBody: !!document.querySelector('textarea[name=body]'),
  hasSave: !!document.querySelector('button[type=submit]'),
}));

// Mobile: panel ma działać na telefonie
const mobile = await context.newPage();
await mobile.setViewportSize({ width: 390, height: 844 });
await mobile.goto(BASE + '/panel/', { waitUntil: 'domcontentloaded' });
result.mobile = await mobile.evaluate(() => {
  const doc = document.documentElement;
  const vw = doc.clientWidth;
  const offenders = [];
  for (const el of document.querySelectorAll('body *')) {
    const r = el.getBoundingClientRect();
    if (r.width === 0) continue;
    if (r.right > vw + 1) {
      const style = getComputedStyle(el);
      if (style.position === 'fixed') continue;
      offenders.push({
        tag: el.tagName.toLowerCase(),
        cls: (el.className || '').toString().slice(0, 60),
        right: Math.round(r.right),
        w: Math.round(r.width),
      });
    }
  }
  return {
    burgerVisible: !!document.querySelector('[data-sidebar-toggle]') && getComputedStyle(document.querySelector('[data-sidebar-toggle]')).display !== 'none',
    overflow: doc.scrollWidth - vw,
    offenders: offenders.slice(0, 10),
  };
});
await mobile.screenshot({ path: `${OUT}/panel-mobile.png`, fullPage: true, animations: 'disabled' });

console.log(JSON.stringify(result, null, 2));
await browser.close();
