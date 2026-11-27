import cennik from '../data/cennik.json';

/**
 * Cennik jako jedno źródło cen dla całej strony.
 *
 * Pozycje w `src/data/cennik.json` mają stabilny `key`. W treściach używamy
 * tokenów `{{cena:KLUCZ}}`, a przy renderowaniu zamieniamy je na cenę z cennika.
 * Cena nieuzupełniona (wielokropek albo pusta) nigdy nie trafia na stronę jako
 * kwota - pokazujemy "wycena po diagnozie".
 */

export const PRICE_PLACEHOLDER_TEXT = 'wycena po diagnozie';

export interface CennikItem {
  key: string;
  name: string;
  price: string;
  note?: string;
}

export interface CennikGroup {
  title: string;
  items: CennikItem[];
}

const TOKEN_RE = /\{\{cena:([a-z0-9-]+)\}\}/gi;

const items: CennikItem[] = (cennik.groups as CennikGroup[]).flatMap((group) => group.items);
const itemsByKey = new Map(items.map((item) => [item.key, item]));

/** Czy wartość ceny jest placeholderem (wielokropek, myślnik, pusta)? */
export function isPricePlaceholder(value: unknown): boolean {
  if (value === null || value === undefined) return true;
  const text = String(value).trim();
  if (text === '') return true;
  // Wielokropek ("… zł") albo sama jednostka/myślnik - brak konkretnej kwoty.
  if (text.includes('…')) return true;
  return /^(?:zł|[.\-\s]+)$/i.test(text);
}

/** Surowe saldo z cennika (może być placeholderem). */
export function rawPrice(key: string): string {
  return itemsByKey.get(key)?.price ?? '';
}

/**
 * Cena do wyświetlenia. Placeholder zamienia na "wycena po diagnozie",
 * tokeny rozwiązuje, więc można ją bezpiecznie podawać wszędzie.
 */
export function displayPrice(value: string | null | undefined): string {
  if (value === null || value === undefined) return PRICE_PLACEHOLDER_TEXT;
  const resolved = applyPriceTokens(String(value)).trim();
  if (isPricePlaceholder(resolved)) return PRICE_PLACEHOLDER_TEXT;
  return resolved;
}

/** Cena pozycji cennika po kluczu, gotowa do wyświetlenia. */
export function price(key: string): string {
  const item = itemsByKey.get(key);
  if (!item) return PRICE_PLACEHOLDER_TEXT;
  return displayPrice(item.price);
}

/** Dopłata za ekspres jako liczba (do miejsc, które liczą, np. schemat). */
export function expressSurcharge(): number {
  const match = price('ekspres').match(/\d+/);
  return match ? Number(match[0]) : 0;
}

/** Zamienia tokeny `{{cena:KLUCZ}}` w dowolnym tekście na ceny z cennika. */
export function applyPriceTokens(text: string | null | undefined): string {
  if (text === null || text === undefined) return '';
  return String(text).replace(TOKEN_RE, (_match, key: string) => price(key));
}

/** Lista kluczy - używana przez skrypt kontrolny. */
export function priceKeys(): string[] {
  return items.map((item) => item.key);
}
