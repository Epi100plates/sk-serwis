// Audyt wydajności i dostępności zbudowanej strony (produkcyjny build + preview).
import { chromium } from 'playwright-core';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const AXE = require.resolve('axe-core/axe.min.js');
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:4322';

const targets = ['/', '/czyszczenie-laptopa-wroclaw/', '/cennik/', '/poradnik/laptop-sie-nie-wlacza-co-zrobic/'];

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const ctx = await browser.newContext({
  viewport: { width: 1440, height: 900 },
  deviceScaleFactor: 1,
  reducedMotion: 'reduce',
  locale: 'pl-PL',
});
await ctx.addInitScript(() => {
  try {
    localStorage.setItem('sk_consent', 'denied');
  } catch {}
  window.__lcp = 0;
  window.__cls = 0;
  new PerformanceObserver((list) => {
    for (const e of list.getEntries()) window.__lcp = e.startTime;
  }).observe({ type: 'largest-contentful-paint', buffered: true });
  new PerformanceObserver((list) => {
    for (const e of list.getEntries()) if (!e.hadRecentInput) window.__cls += e.value;
  }).observe({ type: 'layout-shift', buffered: true });
});

for (const path of targets) {
  const page = await ctx.newPage();
  let bytes = 0;
  const byType = {};
  page.on('response', async (res) => {
    try {
      const body = await res.body();
      bytes += body.length;
      const type = res.headers()['content-type']?.split(';')[0] || 'other';
      byType[type] = (byType[type] || 0) + body.length;
    } catch {}
  });

  await page.goto(BASE + path, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(1200);

  const perf = await page.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0];
    const fcp = performance.getEntriesByName('first-contentful-paint')[0];
    return {
      lcp: Math.round(window.__lcp ?? 0),
      fcp: Math.round(fcp?.startTime ?? 0),
      dcl: Math.round(nav?.domContentLoadedEventEnd ?? 0),
      load: Math.round(nav?.loadEventEnd ?? 0),
      cls: Number((window.__cls ?? 0).toFixed(4)),
      resources: performance.getEntriesByType('resource').length,
    };
  });

  await page.addScriptTag({ path: AXE });
  const axe = await page.evaluate(async () => {
    const result = await window.axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
    });
    return result.violations.map((v) => ({
      id: v.id,
      impact: v.impact,
      help: v.help,
      nodes: v.nodes.length,
      target: v.nodes[0]?.target?.join(' '),
    }));
  });

  const kb = (bytes / 1024).toFixed(0);
  console.log(`\n${path}`);
  console.log(`  LCP ${perf.lcp} ms · FCP ${perf.fcp} ms · CLS ${perf.cls} · żądań ${perf.resources}`);
  console.log(`  transfer ${kb} KB`);
  const types = Object.entries(byType)
    .sort((a, b) => b[1] - a[1])
    .map(([t, b]) => `${t} ${(b / 1024).toFixed(0)} KB`)
    .join(' · ');
  console.log(`  ${types}`);
  if (axe.length) {
    console.log(`  A11y (${axe.length}):`);
    for (const v of axe) console.log(`   - [${v.impact}] ${v.id} (${v.nodes}) ${v.target || ''}`);
  } else {
    console.log('  A11y: brak naruszeń WCAG 2.1 AA (axe)');
  }
  await page.close();
}

await browser.close();
