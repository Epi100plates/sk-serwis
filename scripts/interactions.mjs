// Zrzuty stanów interaktywnych: menu usług (desktop), menu mobilne, akordeon FAQ.
import { chromium } from 'playwright-core';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:4321';
const browser = await chromium.launch({ executablePath: CHROME, headless: true });

// Desktop — rozwinięte menu usług
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  await ctx.addInitScript(() => localStorage.setItem('sk_consent', 'denied'));
  const page = await ctx.newPage();
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  await page.click('[data-nav-toggle]');
  await page.waitForTimeout(300);
  await page.screenshot({ path: 'shots/nav-desktop.png' });
  await ctx.close();
}

// Mobile — otwarte menu
{
  const ctx = await browser.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    reducedMotion: 'reduce',
  });
  await ctx.addInitScript(() => localStorage.setItem('sk_consent', 'denied'));
  const page = await ctx.newPage();
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  await page.click('[data-burger]');
  await page.waitForTimeout(300);
  await page.screenshot({ path: 'shots/menu-mobile.png' });
  await ctx.close();
}

// FAQ — otwarte pytanie
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  await ctx.addInitScript(() => localStorage.setItem('sk_consent', 'denied'));
  const page = await ctx.newPage();
  await page.goto(BASE + '/cennik/', { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  const first = page.locator('.faq details summary').first();
  await first.scrollIntoViewIfNeeded();
  await first.click();
  await page.waitForTimeout(300);
  await page.screenshot({ path: 'shots/faq-open.png' });
  await ctx.close();
}

await browser.close();
console.log('gotowe');
