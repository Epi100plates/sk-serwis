// Eksportuje logo jako PNG 3x (przez przeglądarkę, z prawdziwą typografią marki).
import { chromium } from 'playwright-core';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:4321';

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({ deviceScaleFactor: 3, reducedMotion: 'reduce' });
const page = await context.newPage();
await page.goto(`${BASE}/logo-export/`, { waitUntil: 'networkidle' });
await page.evaluate(() => document.fonts.ready);
await page.waitForTimeout(300);

for (const [id, file] of [
  ['logo-dark', 'studio-komp-logo-dark@3x.png'],
  ['logo-light', 'studio-komp-logo-light@3x.png'],
  ['mark-dark', 'studio-komp-mark@3x.png'],
]) {
  const el = await page.$(`#${id}`);
  if (!el) continue;
  await el.screenshot({ path: `public/brand/${file}`, omitBackground: false });
  console.log('zapisano', file);
}

await browser.close();
