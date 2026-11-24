// Kontrola jakości zbudowanej strony: linki, metadane, duplikaty, obrazy.
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

const DIST = resolve('dist');
const pages = [];

function walk(dir) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    const st = statSync(full);
    if (st.isDirectory()) walk(full);
    else if (entry.endsWith('.html')) pages.push(full);
  }
}
walk(DIST);

const rows = [];
const links = new Map();
const titles = new Map();
const descriptions = new Map();
const issues = [];

for (const file of pages) {
  const html = readFileSync(file, 'utf8');
  const rel = '/' + relative(DIST, file).replace(/\\/g, '/').replace(/index\.html$/, '');
  const title = html.match(/<title>(.*?)<\/title>/s)?.[1] ?? '';
  const desc = html.match(/<meta name="description" content="(.*?)"/s)?.[1] ?? '';
  const canonical = html.match(/<link rel="canonical" href="(.*?)"/)?.[1] ?? '';
  const h1s = [...html.matchAll(/<h1[^>]*>(.*?)<\/h1>/gs)].map((m) => m[1].replace(/<[^>]+>/g, '').trim());
  const robots = html.match(/<meta name="robots" content="(.*?)"/)?.[1] ?? '';
  const lang = html.match(/<html lang="(.*?)"/)?.[1] ?? '';

  rows.push({ rel, title, desc, canonical, h1: h1s[0] ?? '', h1Count: h1s.length, robots, lang });

  if (!title) issues.push(`${rel}: brak <title>`);
  if (!desc) issues.push(`${rel}: brak meta description`);
  if (desc.length > 165) issues.push(`${rel}: description ${desc.length} znaków (>165)`);
  if (h1s.length !== 1) issues.push(`${rel}: liczba H1 = ${h1s.length}`);
  if (!canonical) issues.push(`${rel}: brak canonical`);
  if (lang !== 'pl') issues.push(`${rel}: lang="${lang}"`);
  if (title) titles.set(title, [...(titles.get(title) ?? []), rel]);
  if (desc) descriptions.set(desc, [...(descriptions.get(desc) ?? []), rel]);

  const imgs = [...html.matchAll(/<img\b[^>]*>/g)].map((m) => m[0]);
  for (const img of imgs) {
    if (!/\balt=/.test(img)) issues.push(`${rel}: <img> bez alt`);
  }

  for (const m of html.matchAll(/href="(\/[^"#?]*)/g)) {
    const target = m[1];
    if (target.startsWith('/_astro') || target.startsWith('/images') || target.startsWith('/brand') || target.startsWith('/admin')) continue;
    links.set(rel, [...(links.get(rel) ?? []), target]);
  }
}

function exists(target) {
  const clean = target.split('?')[0].split('#')[0];
  const p = join(DIST, clean);
  if (existsSync(p) && statSync(p).isFile()) return true;
  if (existsSync(join(p, 'index.html'))) return true;
  if (clean.endsWith('/') && existsSync(join(DIST, clean, 'index.html'))) return true;
  return false;
}

let broken = 0;
for (const [from, targets] of links) {
  for (const t of targets) {
    if (!exists(t)) {
      broken++;
      if (broken <= 40) issues.push(`${from} → ${t} (brak pliku)`);
    }
  }
}

const dupTitles = [...titles.entries()].filter(([, list]) => list.length > 1);
const dupDescs = [...descriptions.entries()].filter(([, list]) => list.length > 1);

console.log(`Strony HTML: ${pages.length}`);
console.log(`Unikalne title: ${titles.size}, unikalne description: ${descriptions.size}`);
console.log(`Zepsute linki wewnętrzne: ${broken}`);
console.log(`Problemy: ${issues.length}`);
for (const i of issues.slice(0, 60)) console.log(' -', i);
if (dupTitles.length) {
  console.log('\nDuplikaty title:');
  for (const [t, list] of dupTitles) console.log(` - ${t} → ${list.join(', ')}`);
}
if (dupDescs.length) {
  console.log('\nDuplikaty description:');
  for (const [d, list] of dupDescs) console.log(` - ${d.slice(0, 70)}… → ${list.join(', ')}`);
}

const noindex = rows.filter((r) => r.robots.includes('noindex')).map((r) => r.rel);
console.log(`\nNoindex (${noindex.length}): ${noindex.join(', ')}`);

const shortest = rows
  .filter((r) => !r.robots.includes('noindex'))
  .sort((a, b) => a.desc.length - b.desc.length)
  .slice(0, 5)
  .map((r) => `${r.rel} (${r.desc.length})`);
console.log(`\nNajkrótsze description: ${shortest.join(', ')}`);
