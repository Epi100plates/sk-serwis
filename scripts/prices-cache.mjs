/**
 * Cache treści Astro trzyma w `node_modules/.astro/data-store.json` gotowy HTML
 * z Markdownu (po rehype). Bez tego skryptu zmiana samej ceny w cenniku nie
 * unieważnia cache i stare kwoty zostają w artykułach do najbliższej zmiany
 * pliku .md. Tu usuwamy cache tylko wtedy, gdy zmienił się cennik.
 */
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

export function invalidateContentCacheIfPricesChanged() {
  const cennikPath = path.join(root, 'src', 'data', 'cennik.json');
  if (!fs.existsSync(cennikPath)) return false;

  const hash = createHash('sha1').update(fs.readFileSync(cennikPath)).digest('hex');
  const cacheDir = path.join(root, 'node_modules', '.astro');
  const marker = path.join(cacheDir, '.prices-hash');

  let previous = '';
  try {
    previous = fs.readFileSync(marker, 'utf8').trim();
  } catch {
    previous = '';
  }
  if (previous === hash) return false;

  // Astro 5 trzyma store w cacheDir (node_modules/.astro); starszy katalog
  // .astro w rootcie może zostać po wcześniejszych wersjach - czyścimy oba.
  for (const file of [path.join(cacheDir, 'data-store.json'), path.join(root, '.astro', 'data-store.json')]) {
    try {
      fs.rmSync(file, { force: true });
    } catch {
      // Brak pliku albo brak uprawnień - i tak spróbujemy zbudować dalej.
    }
  }
  try {
    fs.mkdirSync(cacheDir, { recursive: true });
    fs.writeFileSync(marker, `${hash}\n`, 'utf8');
  } catch {
    // Marker to optymalizacja; bez niego czyścimy cache przy każdym buildzie.
  }
  return true;
}
