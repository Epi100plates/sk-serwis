// Test na żywej stronie: CMP, Consent Mode, mapa, zdarzenia (phone_click,
// directions_click, pageview) i brak tagów bez zgody.
// Uruchomienie: SITE_URL=https://sk.mati-it.pl node scripts/qa-events.mjs
import { chromium } from 'playwright-core';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.SITE_URL || 'https://sk.mati-it.pl';
const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({
  viewport: { width: 1440, height: 900 },
  locale: 'pl-PL',
  userAgent:
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
});
const page = await context.newPage();

const beacons = [];
const external = [];
const errors = [];
page.on('request', (req) => {
  const url = req.url();
  if (url.includes('/api/e')) beacons.push({ method: req.method(), body: req.postData() });
  if (/googletagmanager|google-analytics|clarity\.ms/.test(url)) external.push(url);
});
page.on('pageerror', (e) => errors.push(String(e)));
page.on('console', (m) => {
  if (m.type() === 'error') errors.push(m.text());
});

const out = {};
await page.goto(BASE + '/', { waitUntil: 'networkidle' });
await page.evaluate(() => document.fonts.ready);
await page.waitForTimeout(500);

out.consentBefore = await page.evaluate(() => localStorage.getItem('sk_consent_v2'));
out.barVisible = await page.isVisible('[data-cookie-bar]');
out.mapIframe = await page.getAttribute('.map-card__iframe', 'src').catch(() => null);
out.headerBg = await page.evaluate(() => getComputedStyle(document.querySelector('.site-header')).backgroundColor);
out.h1 = await page.textContent('h1');
out.externalBeforeConsent = [...external];

// Akceptacja wszystkich
await page.click('[data-cookie-accept]');
await page.waitForTimeout(600);
out.consentAfter = await page.evaluate(() => localStorage.getItem('sk_consent_v2'));
out.barVisibleAfter = await page.isVisible('[data-cookie-bar]');

// Kliknięcie telefonu (bez nawigacji tel:)
await page.evaluate(() => {
  const link = document.querySelector('.header-call');
  link?.addEventListener('click', (e) => e.preventDefault(), { once: true });
  link?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
});
await page.waitForTimeout(400);

// Kliknięcie "Wyznacz trasę" (bez otwierania nowej karty)
await page.evaluate(() => {
  const link = [...document.querySelectorAll('a')].find((a) => (a.textContent || '').includes('Wyznacz trasę'));
  link?.addEventListener('click', (e) => e.preventDefault(), { once: true });
  link?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
});
await page.waitForTimeout(500);

// Odsłona podstrony (pageview po zgodzie)
await page.goto(BASE + '/cennik/', { waitUntil: 'networkidle' });
await page.waitForTimeout(700);

out.beaconCount = beacons.length;
out.beaconNames = beacons
  .map((b) => {
    try { return JSON.parse(b.body || '{}'); } catch { return null; }
  })
  .filter(Boolean)
  .map((d) => d.name || d.type);
out.externalAfterConsent = [...external];
out.errors = errors;

console.log(JSON.stringify(out, null, 2));
await browser.close();
