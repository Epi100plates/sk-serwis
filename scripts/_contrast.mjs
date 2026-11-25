import { chromium } from 'playwright-core';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const AXE = require.resolve('axe-core/axe.min.js');
const browser = await chromium.launch({ executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', headless: true });
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
await ctx.addInitScript(() => { try { localStorage.setItem('sk_consent','denied'); } catch {} });
const page = await ctx.newPage();
await page.goto('http://127.0.0.1:4322/', { waitUntil: 'networkidle' });
await page.addScriptTag({ path: AXE });
const res = await page.evaluate(async () => {
  const r = await window.axe.run(document, { runOnly: { type: 'rule', values: ['color-contrast'] } });
  const out = [];
  for (const v of r.violations) for (const n of v.nodes) out.push({ target: n.target.join(' '), html: n.html.slice(0, 120), msg: n.any[0]?.message });
  return out;
});
console.log(JSON.stringify(res, null, 1));
await browser.close();
