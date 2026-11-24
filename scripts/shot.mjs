// Zrzuty ekranu + pomiary layoutu przez Chrome (playwright-core, bez pobierania przeglądarki).
import { chromium } from 'playwright-core';
import { mkdirSync } from 'node:fs';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:4321';
const pages = process.argv.slice(2);
const targets = pages.length
  ? pages.map((p) => ({ path: p, name: p.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home' }))
  : [{ path: '/', name: 'home' }];

const OUT = process.env.SHOTS_DIR || 'shots';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({ executablePath: CHROME, headless: true });

const viewports = [
  { name: 'desktop', width: 1440, height: 900, scale: 1 },
  { name: 'mobile', width: 390, height: 844, scale: 2 },
];

for (const t of targets) {
  for (const vp of viewports) {
    const context = await browser.newContext({
      viewport: { width: vp.width, height: vp.height },
      deviceScaleFactor: vp.scale,
      isMobile: vp.name === 'mobile',
      hasTouch: vp.name === 'mobile',
      locale: 'pl-PL',
      reducedMotion: 'reduce',
    });
    await context.addInitScript(() => {
      try {
        localStorage.setItem('sk_consent', 'denied');
      } catch {
        /* ignore */
      }
    });
    const page = await context.newPage();
    await page.goto(BASE + t.path, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(600);

    // Przewiń stronę, żeby doczytały się obrazy lazy
    await page.evaluate(async () => {
      const step = window.innerHeight * 0.8;
      for (let y = 0; y < document.body.scrollHeight; y += step) {
        window.scrollTo(0, y);
        await new Promise((r) => setTimeout(r, 60));
      }
      window.scrollTo(0, 0);
    });
    await page.waitForTimeout(300);

    const metrics = await page.evaluate(() => {
      const doc = document.documentElement;
      const overflow = doc.scrollWidth - doc.clientWidth;
      const offenders = [];
      if (overflow > 0) {
        for (const el of document.querySelectorAll('body *')) {
          const r = el.getBoundingClientRect();
          if (r.right > doc.clientWidth + 1 || r.left < -1) {
            const style = getComputedStyle(el);
            if (style.position === 'fixed') continue;
            offenders.push({
              tag: el.tagName.toLowerCase(),
              cls: (el.className || '').toString().slice(0, 70),
              left: Math.round(r.left),
              right: Math.round(r.right),
              w: Math.round(r.width),
            });
          }
        }
      }
      return {
        viewport: doc.clientWidth,
        scrollWidth: doc.scrollWidth,
        overflow,
        offenders: offenders.slice(0, 8),
      };
    });

    console.log(
      `${t.name} ${vp.name}: viewport=${metrics.viewport} scrollWidth=${metrics.scrollWidth} overflow=${metrics.overflow}`
    );
    if (metrics.offenders.length) console.log('  offenders:', JSON.stringify(metrics.offenders, null, 1));

    await page.screenshot({ path: `${OUT}/${t.name}-${vp.name}-fold.png`, animations: 'disabled' });
    await page.screenshot({
      path: `${OUT}/${t.name}-${vp.name}.png`,
      fullPage: true,
      animations: 'disabled',
    });
    await context.close();
  }
}

await browser.close();
