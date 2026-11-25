// Sprawdza, ze ten sam build (dist/) zadziala:
//   1) w katalogu glownym domeny  -> https://domena.pl/
//   2) w podkatalogu              -> https://domena.pl/sk/
// Dla kazdej strony w obu montowaniach rozwiazuje wszystkie odnosniki
// (href/src/poster/srcset) i url() w CSS dokladnie tak jak przegladarka,
// po czym sprawdza, czy wskazany plik istnieje w dist/.
//
// Wymaga builda przenosnego: node scripts/relativize.mjs
// Uzycie: node scripts/check-portable.mjs
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, dirname, sep, normalize } from 'node:path';

const DIST = 'dist';
const ORIGIN = 'http://localhost';
const MOUNTS = ['/', '/sk/'];

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) walk(full, out);
    else out.push(full);
  }
  return out;
}

const allFiles = walk(DIST);
const pages = allFiles.filter((f) => f.endsWith('.html') && normalize(f) !== normalize(join(DIST, '404.html')));
const cssFiles = allFiles.filter((f) => f.endsWith('.css'));

const failures = [];
const stats = { pages: 0, refs: 0, assets: 0, rootAbsolute: 0, canonical: 0 };

function fileExists(relPath) {
  const file = normalize(join(DIST, relPath));
  if (!file.startsWith(normalize(DIST))) return false;
  try {
    return statSync(file).isFile();
  } catch {
    return false;
  }
}

// Rozwiazuje odnosnik do pliku w dist/ (jak przegladarka pod danym montowaniem).
function resolveToFile(ref, mount, basePath, pageUrl) {
  const url = new URL(ref, pageUrl);
  const pathname = decodeURIComponent(url.pathname);

  if (ref.startsWith('/') && !ref.startsWith('//') && mount !== '/') {
    failures.push(`${pathname}: odnosnik absolutny "${ref}" dziala tylko w katalogu glownym`);
    stats.rootAbsolute += 1;
    return null;
  }

  if (!pathname.startsWith(mount)) {
    failures.push(`${pathname}: poza podkatalogiem ${mount}`);
    return null;
  }

  let relPath = pathname.slice(mount.length);
  if (relPath === '' || relPath.endsWith('/')) relPath += 'index.html';

  if (fileExists(relPath)) return relPath;
  if (fileExists(relPath + '/index.html')) return relPath + '/index.html';
  if (fileExists(relPath + '.html')) return relPath + '.html';

  failures.push(`${basePath} -> ${ref}: brak pliku (${relPath})`);
  return null;
}

function collectRefs(html) {
  const refs = [];
  for (const m of html.matchAll(/(?:href|src|poster)="([^"]+)"/g)) refs.push(m[1]);
  for (const m of html.matchAll(/\ssrcset="([^"]+)"/g)) {
    for (const part of m[1].split(',')) {
      const url = part.trim().split(/\s+/)[0];
      if (url) refs.push(url);
    }
  }
  return refs;
}

function isCheckable(url) {
  return !/^(?:https?:|\/\/|#|mailto:|tel:|data:|javascript:)/i.test(url);
}

for (const mount of MOUNTS) {
  for (const page of pages) {
    const route = relative(DIST, page).split(sep).join('/');
    const pagePath = route === 'index.html' ? '' : route.replace(/\/index\.html$/, '/');
    const pageUrl = new URL(mount + pagePath, ORIGIN);
    const html = readFileSync(page, 'utf8');
    stats.pages += 1;

    const canon = html.match(/<link rel="canonical" href="([^"]+)"/)?.[1];
    if (canon) stats.canonical += 1;
    if (pagePath === '' && canon !== 'https://studio-komp.pl/') {
      failures.push(`${pageUrl.pathname}: canonical "${canon}" != https://studio-komp.pl/`);
    }

    for (const ref of collectRefs(html)) {
      if (!isCheckable(ref)) continue;
      stats.refs += 1;
      const file = resolveToFile(ref, mount, pageUrl.pathname, pageUrl);
      if (file) stats.assets += 1;
    }
  }

  // CSS: url() rozwiazywane wzgledem pliku CSS.
  for (const css of cssFiles) {
    const rel = relative(DIST, css).split(sep).join('/');
    const cssUrl = new URL(mount + rel, ORIGIN);
    const text = readFileSync(css, 'utf8');
    for (const m of text.matchAll(/url\(([^)"']+)\)/g)) {
      const ref = m[1].trim();
      if (!isCheckable(ref)) continue;
      stats.refs += 1;
      const file = resolveToFile(ref, mount, cssUrl.pathname, cssUrl);
      if (file) stats.assets += 1;
    }
  }
}

// kazda strona sprawdzona w obu montowaniach
const expected = pages.length * MOUNTS.length;
console.log(
  `Strony: ${stats.pages}/${expected}, odnosniki: ${stats.refs}, rozwiazane pliki: ${stats.assets}, ` +
    `canonical na stronach: ${stats.canonical}/${expected}, odnosniki absolutne: ${stats.rootAbsolute}`,
);

if (failures.length > 0) {
  console.log(`\nBLEDY (${failures.length}):`);
  for (const f of failures.slice(0, 30)) console.log('  ' + f);
  if (failures.length > 30) console.log(`  ... i ${failures.length - 30} wiecej`);
  process.exit(1);
}
console.log('\nOK — build dziala w katalogu glownym (/) i w podkatalogu (/sk/): wszystkie odnosniki i zasoby istnieja.');
