/**
 * Buduje ścieżkę z uwzględnieniem BASE_PATH (podkatalog na hostingu testowym).
 */
export function withBase(path: string): string {
  const base = import.meta.env.BASE_URL.replace(/\/$/, '');
  if (!path.startsWith('/')) path = `/${path}`;
  return `${base}${path}`;
}

/** Adres bazowy witryny bez ukośnika na końcu (kanoniczny origin). */
export const SITE_ORIGIN = (import.meta.env.SITE ?? 'https://studio-komp.pl').replace(/\/$/, '');

/** Absolutny URL (canonical, OG, schema) — zawsze bez podkatalogu testowego. */
export function absoluteUrl(path: string): string {
  const site = SITE_ORIGIN;
  const clean = path.replace(/^\/+/, '');
  return new URL(clean, `${site.replace(/\/$/, '')}/`).toString();
}
