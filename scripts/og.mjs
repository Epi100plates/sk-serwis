// Generuje public/images/og-default.jpg (1200×630) ze strony /og-image/.
import { chromium } from 'playwright-core';
import sharp from 'sharp';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:4321';

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({
  viewport: { width: 1200, height: 630 },
  deviceScaleFactor: 1,
  reducedMotion: 'reduce',
});
const page = await context.newPage();
await page.goto(`${BASE}/og-image/`, { waitUntil: 'networkidle' });
await page.evaluate(() => document.fonts.ready);
await page.waitForTimeout(400);
const png = await page.screenshot({ type: 'png' });
await sharp(png).jpeg({ quality: 86, mozjpeg: true }).toFile('public/images/og-default.jpg');
await browser.close();
console.log('public/images/og-default.jpg zapisany');
