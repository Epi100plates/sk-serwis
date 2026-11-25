// Testy bezpieczeństwa panelu na żywej instalacji (bez testów destrukcyjnych).
// Wymaga PANEL_USER/PANEL_PASS. Nie zapisuje hasła.
import { chromium } from 'playwright-core';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.SITE_URL || 'https://sk.mati-it.pl';
const USER = process.env.PANEL_USER || '';
const PASS = process.env.PANEL_PASS || '';
const out = {};

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await context.newPage();

await page.goto(BASE + '/panel/', { waitUntil: 'domcontentloaded' });
await page.fill('input[name=username]', USER);
await page.fill('input[name=password]', PASS);
await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('button[type=submit]')]);

// 1. POST bez tokenu CSRF
out.csrf = await page.evaluate(async () => {
  const r = await fetch('/panel/publish', { method: 'POST', body: new URLSearchParams({ action: 'request' }), credentials: 'same-origin' });
  return { status: r.status, text: (await r.text()).slice(0, 80) };
});

// 2. Próba path traversal (po zalogowaniu)
out.traversal = await page.evaluate(async () => {
  const r = await fetch('/panel/editor/blog/..%2f..%2f..%2fetc%2fpasswd', { credentials: 'same-origin' });
  return { status: r.status, text: (await r.text()).slice(0, 60) };
});

// 3. Upload pliku wykonywalnego (udawany obraz)
await page.goto(BASE + '/panel/media', { waitUntil: 'domcontentloaded' });
out.uploadPhp = await page.evaluate(async () => {
  const token = document.querySelector('input[name=_csrf]')?.value || '';
  const fd = new FormData();
  fd.append('_csrf', token);
  fd.append('do', 'upload');
  fd.append('title', 'qa-security');
  fd.append('file', new Blob(['<?php echo "pwn"; ?>'], { type: 'image/png' }), 'evil.php');
  const r = await fetch('/panel/media', { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'manual' });
  return { status: r.status };
});
await page.goto(BASE + '/panel/media', { waitUntil: 'domcontentloaded' });
const mediaHtml = await page.content();
out.uploadPhpRejected = /Niedozwolony typ pliku|Nie udało się dodać/i.test(mediaHtml);

// 4. Upload poprawnego pliku PNG (pozytywny test) + sprzątanie
out.uploadPng = await page.evaluate(async () => {
  const token = document.querySelector('input[name=_csrf]')?.value || '';
  const blob = await (await fetch('/icon-192.png')).blob();
  const fd = new FormData();
  fd.append('_csrf', token);
  fd.append('do', 'upload');
  fd.append('title', 'qa-security obraz');
  fd.append('alt', 'test');
  fd.append('category', 'QA');
  fd.append('file', blob, 'qa-security.png');
  const r = await fetch('/panel/media', { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'manual' });
  return { status: r.status };
});
await page.goto(BASE + '/panel/media', { waitUntil: 'domcontentloaded' });
const mediaHtml2 = await page.content();
out.uploadPngAccepted = mediaHtml2.includes('qa-security obraz');
out.mediaCleanup = await page.evaluate(async () => {
  const forms = [...document.querySelectorAll('form')];
  for (const f of forms) {
    if (f.querySelector('input[name=do]')?.value === 'delete') {
      const row = f.closest('.media-card') || f.closest('div');
      if (row && row.textContent.includes('qa-security obraz')) {
        const data = new FormData(f);
        const r = await fetch('/panel/media', { method: 'POST', body: data, credentials: 'same-origin', redirect: 'manual' });
        return { status: r.status };
      }
    }
  }
  return { skipped: true };
});

// 5. Limit prób logowania (osobny login, nie admin)
const ctx2 = await browser.newContext();
const p2 = await ctx2.newPage();
const attempts = [];
for (let i = 0; i < 6; i++) {
  await p2.goto(BASE + '/panel/login', { waitUntil: 'domcontentloaded' });
  const token = await p2.evaluate(() => document.querySelector('input[name=_csrf]')?.value || '');
  const r = await p2.evaluate(async ({ token }) => {
    const fd = new FormData();
    fd.append('_csrf', token);
    fd.append('username', 'qa-throttle-test');
    fd.append('password', 'bledne-haslo-' + Math.random());
    const resp = await fetch('/panel/login', { method: 'POST', body: fd, credentials: 'same-origin' });
    return { status: resp.status, text: (await resp.text()).slice(0, 400) };
  }, { token });
  attempts.push(r.text.includes('Zbyt wiele prób') ? 'throttled' : 'attempt');
  if (attempts[attempts.length - 1] === 'throttled') break;
}
out.loginThrottle = attempts;
await ctx2.close();

console.log(JSON.stringify(out, null, 2));
await browser.close();
