// Test zapisów w panelu na żywo: strona (CMS), przekierowanie, media,
// ustawienia (bez zmiany wartości) + weryfikacja po stronie serwera.
// Uruchomienie: SITE_URL=... PANEL_USER=... PANEL_PASS=... node scripts/qa-panel-write.mjs
import { chromium } from 'playwright-core';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.SITE_URL || 'https://sk.mati-it.pl';
const USER = process.env.PANEL_USER || '';
const PASS = process.env.PANEL_PASS || '';
if (!USER || !PASS) {
  console.error('Brak PANEL_USER / PANEL_PASS');
  process.exit(1);
}

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'pl-PL' });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push('pageerror: ' + String(e)));
page.on('console', (m) => {
  if (m.type() === 'error') errors.push('console: ' + m.text());
});

const result = { steps: {}, errors };

await page.goto(BASE + '/panel/', { waitUntil: 'domcontentloaded' });
await page.fill('input[name=username]', USER);
await page.fill('input[name=password]', PASS);
await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('button[type=submit]')]);

// Uniwersalny POST z CSRF pobranym z dowolnej strony panelu
async function panelPost(path, fields, files = null) {
  const token = await page.evaluate(() => document.querySelector('input[name=_csrf]')?.value || '');
  if (!token) throw new Error('Brak tokenu CSRF na stronie ' + page.url());
  const result = await page.evaluate(
    async ({ path, fields, token, files }) => {
      const fd = new FormData();
      fd.append('_csrf', token);
      for (const [k, v] of Object.entries(fields)) fd.append(k, v);
      if (files) {
        for (const f of files) {
          const blob = await (await fetch(f.url)).blob();
          fd.append(f.name, blob, f.filename);
        }
      }
      const res = await fetch(path, { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'manual' });
      return { status: res.status, location: res.headers.get('location'), text: (await res.text()).slice(0, 300) };
    },
    { path, fields, token, files }
  );
  return result;
}

// --- 1. Utworzenie strony przez CMS ---
await page.goto(BASE + '/panel/editor/strony', { waitUntil: 'domcontentloaded' });
const slug = 'qa-test-panel-' + Date.now().toString(36);
const createRes = await panelPost('/panel/editor/strony', {
  slug,
  title: 'Strona testowa QA',
  description: 'Testowa podstrona utworzona przez automatyczny test panelu. Do usunięcia.',
  h1: 'Strona testowa QA',
  lead: 'To jest test panelu — strona zostanie usunięta.',
  navLabel: '',
  order: '50',
  updated: new Date().toISOString().slice(0, 10),
  body: '## Test\n\nTreść testowa utworzona przez QA panelu.',
});
result.steps.createPage = { slug, ...createRes };

await page.goto(BASE + '/panel/content/strony', { waitUntil: 'domcontentloaded' });
result.steps.pageListed = (await page.content()).includes(slug);

// --- 2. Przekierowanie ---
await page.goto(BASE + '/panel/redirects', { waitUntil: 'domcontentloaded' });
result.steps.redirect = await panelPost('/panel/redirects', {
  action: 'save',
  id: '0',
  old_path: '/qa-test-przekierowanie/',
  target_url: '/cennik/',
  status_code: '301',
  enabled: '1',
  note: 'Test QA — do usunięcia',
});

await page.goto(BASE + '/panel/redirects', { waitUntil: 'domcontentloaded' });
result.steps.redirectListed = (await page.content()).includes('/qa-test-przekierowanie/');

// --- 3. Media: upload pliku ---
await page.goto(BASE + '/panel/media', { waitUntil: 'domcontentloaded' });
result.steps.media = await panelPost('/panel/media', {
  action: 'upload',
  title: 'QA test obraz',
  alt: 'Obrazek testowy QA',
  description: 'Plik testowy do usunięcia',
  category: 'QA',
}, [{ name: 'file', filename: 'qa-test.png', url: BASE + '/icon-192.png' }]);

await page.goto(BASE + '/panel/media', { waitUntil: 'domcontentloaded' });
result.steps.mediaListed = (await page.content()).includes('QA test obraz');

// --- 4. Ustawienia: zapis bez zmiany wartości (footer description) ---
await page.goto(BASE + '/panel/settings?tab=menu', { waitUntil: 'domcontentloaded' });
const footerDesc = await page.inputValue('textarea[name=footerDescription]');
const settingsForm = await page.evaluate(() => {
  const form = document.querySelector('form');
  const data = {};
  form.querySelectorAll('input[name], select[name], textarea[name]').forEach((el) => {
    if (el.type === 'checkbox') {
      if (el.checked) data[el.name] = el.value || '1';
      return;
    }
    data[el.name] = el.value;
  });
  return data;
});
result.steps.settings = await panelPost('/panel/settings?tab=menu', settingsForm);
result.steps.footerDesc = footerDesc.slice(0, 60);

// --- 5. Sprzątanie: usuń stronę, przekierowanie, plik ---
await page.goto(BASE + '/panel/content/strony', { waitUntil: 'domcontentloaded' });
result.steps.deletePage = await panelPost('/panel/content/strony', { action: 'delete', id: slug });

await page.goto(BASE + '/panel/redirects', { waitUntil: 'domcontentloaded' });
const delRedirectForm = await page.evaluate(() => {
  const forms = [...document.querySelectorAll('form')];
  for (const f of forms) {
    if (f.querySelector('input[name=action]')?.value === 'delete') {
      const row = f.closest('tr');
      if (row && row.textContent.includes('/qa-test-przekierowanie/')) {
        const data = {};
        f.querySelectorAll('input[name]').forEach((el) => (data[el.name] = el.value));
        return data;
      }
    }
  }
  return null;
});
if (delRedirectForm) {
  result.steps.deleteRedirect = await panelPost('/panel/redirects', delRedirectForm);
} else {
  result.steps.deleteRedirect = { skipped: true };
}

result.errors = errors;
console.log(JSON.stringify(result, null, 2));
await browser.close();
