// QA trybu ciemnego: zrzuty + kontrast + przepełnienie layoutu.
// Użycie: node scripts/theme-qa.mjs [--theme=dark|light|both] [--pages=/,/cennik/] [--out=shots/theme]
import { chromium } from 'playwright-core';
import { mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:4321';

const arg = (name, fallback) => {
  const hit = process.argv.find((a) => a.startsWith(`--${name}=`));
  return hit ? hit.slice(name.length + 3) : fallback;
};

const OUT = process.env.SHOTS_DIR || arg('out', 'shots/theme');

// Żądania, których brak w dev/sandboxie nie jest błędem strony:
// endpointy PHP działają tylko na hoście, kafle map to zewnętrzny iframe.
const IGNORED_URLS = [/\/api\/(form-token|zgloszenie)/, /google\.com\/maps/, /maps\.google/];
const ignoredUrl = (url) => IGNORED_URLS.some((re) => re.test(url));

const themes = (() => {
  const value = arg('theme', 'both');
  return value === 'both' ? ['light', 'dark'] : [value];
})();

const DEFAULT_PAGES = [
  '/',
  '/cennik/',
  '/uslugi/',
  '/poradnik/',
  '/realizacje/',
  '/kontakt/',
  '/opinie/',
  '/o-serwisie/',
  '/naprawa-laptopow-wroclaw/',
  '/serwis-lenovo-wroclaw/',
  '/polityka-prywatnosci/',
];

const pages = arg('pages', '') ? arg('pages', '').split(',').map((p) => p.trim()) : DEFAULT_PAGES;
const viewports = [
  { name: 'desktop', width: 1440, height: 900, scale: 1 },
  { name: 'mobile', width: 390, height: 844, scale: 2 },
];

mkdirSync(OUT, { recursive: true });
const axeSource = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const report = [];

for (const theme of themes) {
  for (const path of pages) {
    for (const vp of viewports) {
      const context = await browser.newContext({
        viewport: { width: vp.width, height: vp.height },
        deviceScaleFactor: vp.scale,
        isMobile: vp.name === 'mobile',
        hasTouch: vp.name === 'mobile',
        locale: 'pl-PL',
        reducedMotion: 'reduce',
        colorScheme: theme,
      });
      await context.addInitScript(
        ([selected]) => {
          try {
            localStorage.setItem('sk_consent', 'denied');
            localStorage.setItem('sk-theme', selected);
          } catch {
            /* ignore */
          }
        },
        [theme]
      );
      const page = await context.newPage();
      const errors = [];
      page.on('console', (msg) => {
        // 404 zasobów raportujemy z odpowiedzi (mamy wtedy URL), nie z konsoli
        if (msg.type() === 'error' && !/Failed to load resource/.test(msg.text())) errors.push(msg.text());
      });
      page.on('pageerror', (err) => errors.push(String(err)));
      page.on('requestfailed', (req) => {
        if (!ignoredUrl(req.url())) errors.push(`request failed: ${req.url()}`);
      });
      page.on('response', (res) => {
        if (res.status() >= 400 && !ignoredUrl(res.url())) errors.push(`${res.status()} ${res.url()}`);
      });

      await page.goto(BASE + path, { waitUntil: 'load', timeout: 60000 });
      await page.waitForTimeout(400);
      await page.evaluate(() => document.fonts.ready);
      // HMR potrafi podmienić kontekst w trakcie (inne procesy edytują pliki) - do trzech prób
      for (let attempt = 0; attempt < 3; attempt++) {
        try {
          await page.evaluate(async () => {
            const step = window.innerHeight * 0.8;
            for (let y = 0; y < document.body.scrollHeight; y += step) {
              window.scrollTo(0, y);
              await new Promise((r) => setTimeout(r, 30));
            }
            window.scrollTo(0, 0);
          });
          break;
        } catch (error) {
          if (attempt === 2) throw error;
          await page.waitForTimeout(500);
        }
      }
      await page.waitForTimeout(250);

      const layout = await page.evaluate(() => {
        const doc = document.documentElement;
        const overflow = doc.scrollWidth - doc.clientWidth;
        const offenders = [];
        if (overflow > 0) {
          for (const el of document.querySelectorAll('body *')) {
            const r = el.getBoundingClientRect();
            if (r.right > doc.clientWidth + 1 || r.left < -1) {
              if (getComputedStyle(el).position === 'fixed') continue;
              offenders.push(`${el.tagName.toLowerCase()}.${String(el.className).slice(0, 60)}`);
            }
          }
        }
        return {
          appliedTheme: doc.dataset.theme || null,
          pageBg: getComputedStyle(document.body).backgroundColor,
          overflow,
          offenders: offenders.slice(0, 6),
        };
      });

      await page.addScriptTag({ content: axeSource });
      const axe = await page.evaluate(async () => {
        const result = await window.axe.run(document, {
          runOnly: { type: 'rule', values: ['color-contrast'] },
        });
        return result.violations.map((v) => ({
          id: v.id,
          impact: v.impact,
          nodes: v.nodes.slice(0, 12).map((n) => ({
            target: n.target.join(' '),
            summary: n.failureSummary?.split('\n').slice(0, 3).join(' ') ?? '',
          })),
          count: v.nodes.length,
        }));
      });

      const name = `${path.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home'}-${vp.name}-${theme}`;
      await page.screenshot({ path: `${OUT}/${name}-fold.png`, animations: 'disabled' });
      await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: true, animations: 'disabled' });

      report.push({ path, theme, viewport: vp.name, name, ...layout, errors, contrast: axe });
      await context.close();
    }
  }
}

await browser.close();

let problems = 0;
for (const r of report) {
  const contrastCount = r.contrast.reduce((sum, v) => sum + v.count, 0);
  problems += r.overflow > 0 ? 1 : 0;
  problems += contrastCount;
  problems += r.errors.length;
  const flags = [];
  if (r.appliedTheme !== r.theme) flags.push(`motyw=${r.appliedTheme}`);
  if (r.overflow > 0) flags.push(`overflow=${r.overflow} ${r.offenders.join(', ')}`);
  if (contrastCount) flags.push(`kontrast: ${contrastCount}`);
  if (r.errors.length) flags.push(`błędy: ${r.errors.slice(0, 3).join(' | ')}`);
  console.log(`${r.name.padEnd(52)} bg=${r.pageBg} ${flags.length ? '✗ ' + flags.join('; ') : '✓'}`);
  for (const v of r.contrast) {
    for (const node of v.nodes.slice(0, 4)) {
      console.log(`    ${node.target}: ${node.summary.replace(/\s+/g, ' ').slice(0, 180)}`);
    }
  }
}

console.log(`\nZrzuty: ${OUT} | problemy: ${problems}`);
process.exitCode = problems ? 1 : 0;
