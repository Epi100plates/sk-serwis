#!/usr/bin/env node
/**
 * Kontrola SEO po lokalnym buildzie: `node scripts/check-seo.mjs`.
 *
 * Sprawdza to, co latwo przeoczyc przy edycji tresci:
 *  1) duplikaty <title> i meta description w dist/,
 *  2) dokladnie jeden <h1> na strone,
 *  3) canonical i OG na kazdej stronie,
 *  4) brak obrazow bez atrybutu alt,
 *  5) nowe podstrony istnieja, starego duplikatu nie ma i nie ma do niego linkow,
 *  6) nowe adresy sa w sitemapie, starego nie ma,
 *  7) 301 na poprawny artykul jest w public/_redirects i public/.htaccess
 *     (oraz w kopiach w dist/),
 *  8) brak surowych tokenow {{cena:...}} w HTML,
 *  9) kazda nowa strona ma link z innej strony.
 *
 * Wymaga wczesniejszego `pnpm build`. Kod wyjscia 1 = bledy.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const dist = path.join(root, 'dist');

const errors = [];
const warnings = [];
const notes = [];

const NEW_PAGES = [
  'serwis-komputerowy-wroclaw/index.html',
  'naprawa-laptopow-gamingowych-wroclaw/index.html',
  'poradnik/ile-kosztuje-naprawa-plyty-glownej-laptopa/index.html',
  'poradnik/laptop-nie-laduje-baterii-co-sprawdzic/index.html',
];
const WRONG_SLUG = '/poradnik/ile-kostuje-naprawa-laptopa/';
const CORRECT_SLUG = '/poradnik/ile-kosztuje-naprawa-laptopa/';

if (!fs.existsSync(dist)) {
  console.error('Brak katalogu dist/ - uruchom najpierw `pnpm build`.');
  process.exit(1);
}

function walk(dir, out = []) {
  for (const name of fs.readdirSync(dir)) {
    const abs = path.join(dir, name);
    const stat = fs.statSync(abs);
    if (stat.isDirectory()) walk(abs, out);
    else if (name.endsWith('.html')) out.push(abs);
  }
  return out;
}

const htmlFiles = walk(dist).filter((abs) => {
  const rel = path.relative(dist, abs).replaceAll('\\', '/');
  // Strony techniczne do generowania obrazow, celowo poza SEO i sitemapa.
  return !rel.startsWith('og-image/') && !rel.startsWith('logo-export/');
});
const pages = new Map();
const titleMap = new Map();
const descMap = new Map();

function addTo(map, key, rel) {
  if (!key) return;
  if (!map.has(key)) map.set(key, []);
  map.get(key).push(rel);
}

for (const abs of htmlFiles) {
  const rel = path.relative(dist, abs).replaceAll('\\', '/');
  const html = fs.readFileSync(abs, 'utf8');
  const title = (html.match(/<title>([\s\S]*?)<\/title>/) ?? [])[1] ?? '';
  const description = (html.match(/<meta name="description" content="([^"]*)"/) ?? [])[1] ?? '';
  const canonical = (html.match(/<link rel="canonical" href="([^"]*)"/) ?? [])[1] ?? '';
  const h1Count = (html.match(/<h1[\s>]/g) ?? []).length;
  const ogTitle = html.includes('property="og:title"');
  const ogUrl = html.includes('property="og:url"');
  const imgTags = html.match(/<img\b[^>]*>/g) ?? [];
  // Brak alt = brak atrybutu. Pusty alt (`alt` albo `alt=""`) jest poprawny
  // dla obrazow dekoracyjnych, wiec go nie zglaszamy.
  const missingAlt = imgTags.filter((tag) => !/\salt(?:=|\s|\/?>)/.test(tag)).length;
  const rawToken = html.includes('{{cena:');

  pages.set(rel, { title, description, canonical, h1Count, ogTitle, ogUrl, missingAlt, rawToken });
  addTo(titleMap, title, rel);
  addTo(descMap, description, rel);
}

// 1. Duplikaty title i description
for (const [title, files] of titleMap) {
  if (files.length > 1) errors.push(`zduplikowany <title> "${title}" w: ${files.join(', ')}`);
}
for (const [desc, files] of descMap) {
  if (files.length > 1) errors.push(`zduplikowana meta description (${desc.slice(0, 60)}...) w: ${files.join(', ')}`);
}

// 2-4. Podstawowe znaczniki na kazdej stronie
for (const [rel, page] of pages) {
  if (page.h1Count !== 1) errors.push(`${rel}: ${page.h1Count} elementow <h1> (ma byc dokladnie 1).`);
  if (!page.canonical) errors.push(`${rel}: brak <link rel="canonical">.`);
  if (!page.ogTitle || !page.ogUrl) errors.push(`${rel}: brak og:title lub og:url.`);
  if (page.rawToken) errors.push(`${rel}: surowy token {{cena:...}} w HTML.`);
  if (page.missingAlt > 0) {
    const message = `${rel}: ${page.missingAlt} obraz(ow) bez atrybutu alt.`;
    if (NEW_PAGES.includes(rel)) errors.push(message);
    else warnings.push(message);
  }
}
notes.push(`dist: sprawdzono ${pages.size} stron HTML.`);

// 5. Nowe strony i stary duplikat
for (const rel of NEW_PAGES) {
  if (!pages.has(rel)) errors.push(`brak nowej strony w dist: ${rel}`);
}
if (pages.has('poradnik/ile-kostuje-naprawa-laptopa/index.html')) {
  errors.push('stary duplikat artykulu nadal istnieje w dist: poradnik/ile-kostuje-naprawa-laptopa/');
}

// Linki do starego duplikatu nie moga nigdzie zostac.
const wrongReferences = [...htmlFiles].filter((abs) => fs.readFileSync(abs, 'utf8').includes(WRONG_SLUG));
for (const abs of wrongReferences) {
  errors.push(`${path.relative(dist, abs).replaceAll('\\', '/')}: link do starego duplikatu ${WRONG_SLUG}`);
}

// Artykul ma byc dokladnie raz na liscie poradnika.
const poradnikList = pages.get('poradnik/index.html');
if (!poradnikList) {
  errors.push('brak dist/poradnik/index.html');
} else {
  const listHtml = fs.readFileSync(path.join(dist, 'poradnik/index.html'), 'utf8');
  const correctLinks = (listHtml.match(new RegExp(CORRECT_SLUG.replace(/[/]/g, '\\/'), 'g')) ?? []).length;
  if (correctLinks !== 1) errors.push(`/poradnik/ linkuje do poprawnego artykulu ${correctLinks} razy (ma byc 1).`);
}

// 6. Sitemap
const sitemapPath = path.join(dist, 'sitemap-0.xml');
if (!fs.existsSync(sitemapPath)) {
  errors.push('brak dist/sitemap-0.xml');
} else {
  const sitemap = fs.readFileSync(sitemapPath, 'utf8');
  const locs = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
  for (const rel of NEW_PAGES) {
    const url = rel.replace(/index\.html$/, '');
    if (!locs.some((loc) => loc.endsWith(url))) errors.push(`brak w sitemapie: /${url}`);
  }
  if (locs.some((loc) => loc.includes(WRONG_SLUG))) errors.push(`stary duplikat jest w sitemapie: ${WRONG_SLUG}`);
  notes.push(`sitemap: ${locs.length} adresow.`);
}

// 7. Przekierowanie 301 w plikach generowanych z panelu
const redirectFiles = ['public/_redirects', 'dist/_redirects'];
for (const rel of redirectFiles) {
  const abs = path.join(root, rel);
  if (!fs.existsSync(abs)) {
    errors.push(`brak pliku ${rel}`);
    continue;
  }
  const text = fs.readFileSync(abs, 'utf8');
  const ok = text.split(/\r?\n/).some((line) => {
    const parts = line.trim().split(/\s+/);
    return parts[0] === WRONG_SLUG && parts[1] === CORRECT_SLUG && parts[2] === '301';
  });
  if (!ok) errors.push(`${rel}: brak reguly 301 ${WRONG_SLUG} -> ${CORRECT_SLUG}`);
}

const htaccessFiles = ['public/.htaccess', 'dist/.htaccess'];
for (const rel of htaccessFiles) {
  const abs = path.join(root, rel);
  if (!fs.existsSync(abs)) {
    errors.push(`brak pliku ${rel}`);
    continue;
  }
  const text = fs.readFileSync(abs, 'utf8');
  const ok = text
    .split(/\r?\n/)
    .some((line) => line.includes(WRONG_SLUG.replace(/^\//, '^')) && line.includes(CORRECT_SLUG) && line.includes('[R=301,L]'));
  if (!ok) errors.push(`${rel}: brak reguly RewriteRule 301 ${WRONG_SLUG} -> ${CORRECT_SLUG}`);
}

// 8. Kazda nowa strona musi byc linkowana z innej strony.
for (const rel of NEW_PAGES) {
  const url = '/' + rel.replace(/index\.html$/, '');
  const sources = [];
  for (const [sourceRel, page] of pages) {
    if (sourceRel === rel) continue;
    const html = fs.readFileSync(path.join(dist, sourceRel), 'utf8');
    if (html.includes(`href="${url}"`) || html.includes(`href="${url.replace(/\/$/, '')}"`)) sources.push(sourceRel);
  }
  if (sources.length === 0) errors.push(`${url}: brak linku z zadnej innej strony.`);
  else notes.push(`${url}: linkowany z ${sources.length} stron (np. ${sources[0]}).`);
}

// Raport
for (const note of notes) console.log(`  ${note}`);
for (const warning of warnings) console.log(`  UWAGA: ${warning}`);
if (errors.length) {
  console.error(`\nBLEDY (${errors.length}):`);
  for (const error of errors) console.error(`  - ${error}`);
  process.exit(1);
}
console.log('\ncheck-seo: OK');
