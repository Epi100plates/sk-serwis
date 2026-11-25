// Pomiar strony na telefonie: liczba ekranow, sekcje, mapa, CMP.
// Uruchomienie: node scripts/measure.mjs [url]
import { chromium } from 'playwright-core';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:4321';
const URL = process.argv[2] || '/';

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({
  viewport: { width: 390, height: 844 },
  deviceScaleFactor: 2,
  isMobile: true,
  hasTouch: true,
  locale: 'pl-PL',
  reducedMotion: 'reduce',
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
const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));
page.on('console', (m) => {
  if (m.type() === 'error') errors.push(m.text());
});
await page.goto(BASE + URL, { waitUntil: 'networkidle' });
await page.evaluate(() => document.fonts.ready);
await page.waitForTimeout(800);

const info = await page.evaluate(() => {
  const doc = document.documentElement;
  const viewport = window.innerHeight;
  const height = doc.scrollHeight;
  const sections = [...document.querySelectorAll('main > section, main > div > section')].map((s) => {
    const r = s.getBoundingClientRect();
    return {
      cls: (s.className || '').toString().split(' ')[0],
      id: s.id || '',
      top: Math.round(r.top + window.scrollY),
      height: Math.round(r.height),
    };
  });
  const iframe = document.querySelector('.map-card__iframe');
  const cookie = document.querySelector('[data-cookie-bar]');
  return {
    viewport,
    height,
    screens: +(height / viewport).toFixed(1),
    sections,
    mapIframe: iframe ? iframe.getAttribute('src') : null,
    cookieVisible: cookie ? !cookie.hidden : false,
    h1: document.querySelector('h1')?.textContent?.trim() ?? '',
    stats: [...document.querySelectorAll('.hero__fact')].map((e) => e.textContent.trim().replace(/\s+/g, ' ')),
  };
});

console.log(JSON.stringify({ url: URL, ...info, errors }, null, 2));
await browser.close();
