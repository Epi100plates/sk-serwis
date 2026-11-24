// Sprawdza działającą stronę: wszystkie URL-e z sitemapy + linki wewnętrzne.
// Użycie: node scripts/check-live.mjs [https://sk.mati-it.pl]
const BASE = (process.argv[2] || process.env.LIVE_URL || 'https://sk.mati-it.pl').replace(/\/$/, '');

const fail = [];
let checked = 0;
const seen = new Set();

async function get(url, method = 'GET') {
  const res = await fetch(url, { method, redirect: 'manual', headers: { 'user-agent': 'studio-komp-live-check' } });
  return res;
}

// 1. Sitemapa → lista stron
const sm = await get(`${BASE}/sitemap-index.xml`);
const idx = await sm.text();
const sitemaps = [...idx.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);

const pages = [];
for (const smUrl of sitemaps) {
  const r = await get(smUrl);
  if (r.status !== 200) {
    fail.push(`sitemap ${smUrl} -> ${r.status}`);
    continue;
  }
  const xml = await r.text();
  for (const m of xml.matchAll(/<loc>([^<]+)<\/loc>/g)) pages.push(m[1]);
}

console.log(`Sitemapa: ${pages.length} adresów (${BASE})`);

const linkTargets = new Set();

for (const url of pages) {
  const res = await get(url);
  checked++;
  if (res.status !== 200) {
    fail.push(`${url} -> ${res.status}`);
    continue;
  }
  const html = await res.text();
  const path = url.replace(BASE, '') || '/';

  // canonical musi wskazywać ten sam adres
  const canon = html.match(/<link rel="canonical" href="([^"]+)"/)?.[1];
  if (!canon) fail.push(`${path}: brak canonical`);
  else if (canon !== url && canon !== url + '/') fail.push(`${path}: canonical ${canon} != ${url}`);

  // H1
  if (!/<h1[\s>]/.test(html)) fail.push(`${path}: brak H1`);

  // linki wewnętrzne
  for (const m of html.matchAll(/href="(\/[^"#?]*)/g)) {
    const href = m[1].replace(/\/+$/, '/');
    if (href.startsWith('/_astro/') || href.startsWith('/images/')) continue;
    if (href.endsWith('.xml') || href.endsWith('.txt')) continue;
    linkTargets.add(href);
  }
}

console.log(`Strony: ${checked}, linki wewnętrzne (unikalne): ${linkTargets.size}`);

// 2. Linki wewnętrzne — HEAD/GET
for (const href of linkTargets) {
  if (seen.has(href)) continue;
  seen.add(href);
  const url = BASE + href;
  let res = await get(url, 'GET');
  if (res.status >= 300 && res.status < 400) res = await get(url);
  if (res.status !== 200) fail.push(`link ${href} -> ${res.status}`);
}

// 3. Przekierowania ze starej strony
const redirects = [
  ['/cennik.html', '/cennik/'],
  ['/o-firmie.html', '/o-serwisie/'],
  ['/galeria.html', '/realizacje/'],
  ['/kontakt.html', '/kontakt/'],
  ['/aktualnosci/', '/poradnik/'],
  ['/googleads', '/lp/naprawa-laptopow/'],
  ['/mapa/', '/kontakt/'],
];
for (const [from, to] of redirects) {
  const res = await get(BASE + from);
  const loc = res.headers.get('location') || '';
  if (res.status !== 301 || !loc.endsWith(to)) fail.push(`redirect ${from}: ${res.status} -> ${loc}`);
}

// 4. 404
const nf = await get(`${BASE}/nie-ma-takiej-strony/`);
if (nf.status !== 404) fail.push(`404 -> ${nf.status}`);

console.log('');
if (fail.length) {
  console.log(`PROBLEMY (${fail.length}):`);
  for (const f of fail) console.log('  - ' + f);
  process.exitCode = 1;
} else {
  console.log('OK — wszystkie sprawdzenia przeszły.');
}
