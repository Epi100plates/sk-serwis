/**
 * Rehype plugin: zamienia tokeny `{{cena:KLUCZ}}` w treści Markdown na ceny
 * z `src/data/cennik.json`. Dzięki temu ceny w artykułach i podstronach usług
 * zmieniają się razem z cennikiem - nie są wpisane na sztywno w tekst.
 *
 * Tokeny w blokach kodu (`code`, `inlineCode`) zostają nietknięte - to celowo,
 * bo tam mają być pokazane dosłownie.
 */
import { applyPriceTokens } from '../src/lib/prices.ts';

const TOKEN_MARK = '{{cena:';

function walk(node, replace, parent = null) {
  if (!node || typeof node !== 'object') return;
  if (node.type === 'text' && typeof node.value === 'string' && node.value.includes(TOKEN_MARK)) {
    if (!parent || (parent.type !== 'code' && parent.type !== 'inlineCode')) {
      replace(node);
    }
  }
  if (Array.isArray(node.children)) {
    for (const child of node.children) walk(child, replace, node);
  }
}

export default function rehypePrices() {
  return (tree) => {
    walk(tree, (node) => {
      node.value = applyPriceTokens(node.value);
    });
  };
}
