// Zamienia absolutne odnosniki ("/...") w zbudowanej stronie na wzgledne,
// dzieki czemu ten sam build dziala w katalogu glownym domeny i w podkatalogu
// (np. https://epii.pl/sk/), bez przebudowywania z BASE_PATH.
//
// - HTML: href, src, poster, srcset, action (poza 404.html, ktory musi miec
//   sciezki absolutne, bo jest serwowany dla dowolnego blednego adresu).
// - CSS: url(/...).
// - URL-e absolutne (https://...), protokolowe (//...), anchor (#...) i
//   mailto:/tel: pozostaja bez zmian.
//
// Uzycie: node scripts/relativize.mjs [--dry]
import { readFileSync, writeFileSync } from 'node:fs';
import { readdirSync, statSync } from 'node:fs';
import { join, relative, dirname, basename, sep } from 'node:path';

const DIST = 'dist';
const DRY = process.argv.includes('--dry');
const ATTRS = ['href', 'src', 'poster', 'action'];

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) walk(full, out);
    else out.push(full);
  }
  return out;
}

function depthOf(file) {
  return relative(DIST, dirname(file)).split(sep).filter(Boolean).length;
}

// '/' -> './' na poziomie 0, '../' na poziomie 1 itd.
function prefixFor(file) {
  const depth = depthOf(file);
  return depth > 0 ? '../'.repeat(depth) : './';
}

function rewriteSrcset(value, prefix) {
  return value
    .split(',')
    .map((part) => {
      const token = part.trim();
      if (!token) return part;
      const [url, ...rest] = token.split(/\s+/);
      const next = url.startsWith('/') && !url.startsWith('//') ? prefix + url.slice(1) : url;
      return [next, ...rest].join(' ');
    })
    .join(', ');
}

const stats = { html: 0, css: 0, refs: 0, skipped404: 0 };
const files = walk(DIST);

for (const file of files) {
  if (file.endsWith('.html')) {
    if (basename(file) === '404.html') {
      stats.skipped404 += 1;
      continue;
    }
    const prefix = prefixFor(file);
    const original = readFileSync(file, 'utf8');
    let html = original;
    let count = 0;

    for (const attr of ATTRS) {
      const re = new RegExp(`(\\s${attr}=")\\/(?![\\/])`, 'g');
      html = html.replace(re, (_, start) => {
        count += 1;
        return start + prefix;
      });
    }

    html = html.replace(/(\ssrcset=")([^"]*)"/g, (_, start, value) => {
      const next = rewriteSrcset(value, prefix);
      if (next !== value) count += 1;
      return `${start}${next}"`;
    });

    if (html !== original) {
      stats.html += 1;
      stats.refs += count;
      if (!DRY) writeFileSync(file, html);
      else console.log(`  ${relative(DIST, file)}: ${count} odnosnikow`);
    }
    continue;
  }

  if (file.endsWith('.css')) {
    const prefix = prefixFor(file);
    const original = readFileSync(file, 'utf8');
    let count = 0;
    const next = original.replace(/url\((\/(?!\/)[^)"']*)\)/g, (_, url) => {
      count += 1;
      return `url(${prefix}${url.slice(1)})`;
    });
    if (count > 0) {
      stats.css += 1;
      stats.refs += count;
      if (!DRY) writeFileSync(file, next);
      else console.log(`  ${relative(DIST, file)}: ${count} url()`);
    }
  }
}

console.log(
  `${DRY ? '[dry run] ' : ''}HTML: ${stats.html} plikow, CSS: ${stats.css} plikow, odnosnikow: ${stats.refs}` +
    (stats.skipped404 ? `, 404.html pominieto` : ''),
);
