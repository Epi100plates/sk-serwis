#!/usr/bin/env node
/**
 * Kontrola cen: `src/data/cennik.json` to jedno źródło cen dla całej strony.
 *
 * Skrypt sprawdza:
 *  1) każda pozycja cennika ma unikalny, stabilny `key`,
 *  2) każdy token `{{cena:key}}` wskazuje na istniejący klucz,
 *  3) znane kwoty z cennika nie są wpisane na sztywno poza cennikiem,
 *  4) placeholder "… zł" występuje tylko w cenniku,
 *  5) jeśli istnieje `dist/`, nie ma w nim "… zł" ani surowych tokenów.
 *
 * Uruchomienie: node scripts/check-prices.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const cennikPath = path.join(root, 'src/data/cennik.json');
const cennik = JSON.parse(fs.readFileSync(cennikPath, 'utf8'));

const errors = [];
const warnings = [];
const notes = [];

// Pliki celowo pominięte: duplikat artykułu obsługiwany przez agenta SEO;
// cennik (źródło) i pliki generowane.
const skippedFiles = new Set([
  'src/data/cennik.json',
  'src/content/blog/ile-kostuje-naprawa-laptopa.md',
]);
const skippedDirs = new Set(['node_modules', 'dist', 'dist-preview', '.astro', '.git', 'docs', 'panel', 'public', 'deploy', 'shots']);

// --- 1. Klucze w cenniku ---------------------------------------------------
const items = [];
for (const group of cennik.groups ?? []) {
  for (const item of group.items ?? []) {
    items.push(item);
  }
}
if (!items.length) errors.push('cennik.json nie ma żadnych pozycji.');

const seen = new Map();
for (const item of items) {
  const key = String(item.key ?? '').trim();
  const label = `"${item.name ?? '?'}"`;
  if (!key) {
    errors.push(`${label}: brak klucza "key".`);
    continue;
  }
  if (!/^[a-z0-9-]+$/.test(key)) {
    errors.push(`${label}: klucz "${key}" może zawierać tylko małe litery, cyfry i myślniki.`);
  }
  if (seen.has(key)) {
    errors.push(`zduplikowany klucz "${key}" (pozycje: ${seen.get(key)}, ${label}).`);
  } else {
    seen.set(key, label);
  }
}
const keys = new Set([...seen.keys()]);
const placeholders = items.filter((item) => !/\d/.test(String(item.price ?? '')));
notes.push(`cennik: ${items.length} pozycji, ${keys.size} kluczy, ${placeholders.length} bez ceny (placeholder).`);

// --- 2/3/4. Skanowanie źródeł ---------------------------------------------
const TOKEN_RE = /\{\{cena:([a-z0-9-]+)\}\}/g;
const sourceExt = new Set(['.md', '.json', '.astro', '.ts', '.mjs', '.tsx', '.js']);
const tokenRefs = new Map();

function walk(absDir, out = []) {
  for (const name of fs.readdirSync(absDir)) {
    if (skippedDirs.has(name)) continue;
    const abs = path.join(absDir, name);
    const rel = path.relative(root, abs).replaceAll('\\', '/');
    const stat = fs.statSync(abs);
    if (stat.isDirectory()) walk(abs, out);
    else if (sourceExt.has(path.extname(name)) && !skippedFiles.has(rel)) out.push(rel);
  }
  return out;
}

const sourceFiles = walk(path.join(root, 'src'));
// Dodatkowe pliki konfiguracyjne, w których nie powinno być cen.
for (const rel of ['astro.config.mjs']) {
  if (fs.existsSync(path.join(root, rel))) sourceFiles.push(rel);
}

for (const rel of sourceFiles) {
  const text = fs.readFileSync(path.join(root, rel), 'utf8');
  for (const match of text.matchAll(TOKEN_RE)) {
    const key = match[1];
    if (!keys.has(key)) {
      errors.push(`${rel}: token {{cena:${key}}} wskazuje na nieistniejący klucz.`);
    }
    tokenRefs.set(key, (tokenRefs.get(key) ?? 0) + 1);
  }
  if (rel !== 'src/lib/prices.ts' && text.includes('… zł')) {
    errors.push(`${rel}: surowy placeholder "… zł" poza cennikiem (na stronie ma być "wycena po diagnozie").`);
  }
}

// Znane kwoty z cennika nie mogą być wpisane na sztywno w treści.
// "0 zł" pomijamy: to zasada diagnostyki opisana też w site.json (poza zakresem
// tej zmiany; site.json jest edytowany przez inny zespół).
const hardcodedPrices = [];
for (const item of items) {
  const value = String(item.price ?? '').trim();
  if (!/\d/.test(value)) continue;
  if (!hardcodedPrices.includes(value)) hardcodedPrices.push(value);
}
const zeroPriceSkipped = hardcodedPrices.includes('0 zł');
const checkedPrices = hardcodedPrices.filter((v) => v !== '0 zł');

function pricePattern(value) {
  const escaped = value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\s+/g, '\\s+');
  // Nie łap fragmentu większej liczby ("1500 zł") ani drugiej strony widełek
  // ("250 zł" wewnątrz "150-250 zł").
  return new RegExp(`(?<![\\d\\-–])${escaped}(?![\\d])`, 'g');
}

for (const rel of sourceFiles) {
  const text = fs.readFileSync(path.join(root, rel), 'utf8');
  for (const value of checkedPrices) {
    const re = pricePattern(value);
    for (const match of text.matchAll(re)) {
      const line = text.slice(0, match.index).split(/\r?\n/).length;
      errors.push(`${rel}:${line}: wpisana na sztywno cena "${value}" - użyj {{cena:key}}.`);
    }
  }
}

notes.push(
  `sprawdzono ${sourceFiles.length} plików źródłowych; tokeny: ${tokenRefs.size} kluczy (${[...tokenRefs.values()].reduce((a, b) => a + b, 0)} wystąpień).`
);
notes.push(`kwoty pilnowane jako "na sztywno": ${checkedPrices.join(', ') || '(brak)'}.`);
if (zeroPriceSkipped) {
  warnings.push('"0 zł" (diagnostyka) jest nadal powtórzone w site.json / komponentach - patrz raport.');
}
const unusedKeys = [...keys].filter((key) => !tokenRefs.has(key));
if (unusedKeys.length) notes.push(`klucze bez tokenów (OK, np. używane przez PriceTable): ${unusedKeys.join(', ')}.`);

// --- 5. Kontrola zbudowanego dist/ ----------------------------------------
const distDir = path.join(root, 'dist');
if (fs.existsSync(distDir)) {
  let htmlFiles = 0;
  const distErrors = [];
  const stack = [distDir];
  while (stack.length) {
    const dir = stack.pop();
    for (const name of fs.readdirSync(dir)) {
      const abs = path.join(dir, name);
      const stat = fs.statSync(abs);
      if (stat.isDirectory()) stack.push(abs);
      else if (name.endsWith('.html')) {
        htmlFiles += 1;
        const html = fs.readFileSync(abs, 'utf8');
        const rel = path.relative(root, abs).replaceAll('\\', '/');
        if (html.includes('… zł')) distErrors.push(`${rel}: "… zł" w HTML.`);
        if (html.includes('{{cena:')) distErrors.push(`${rel}: surowy token {{cena:...}} w HTML.`);
      }
    }
  }
  if (distErrors.length) errors.push(...distErrors.slice(0, 20));
  notes.push(`dist: sprawdzono ${htmlFiles} plików HTML.`);
} else {
  notes.push('dist: brak katalogu (build jeszcze nie uruchomiony).');
}

// --- Raport ---------------------------------------------------------------
for (const note of notes) console.log(`  ${note}`);
for (const warning of warnings) console.log(`  UWAGA: ${warning}`);
if (errors.length) {
  console.error(`\nBŁĘDY (${errors.length}):`);
  for (const error of errors) console.error(`  - ${error}`);
  process.exit(1);
}
console.log('\ncheck-prices: OK');
