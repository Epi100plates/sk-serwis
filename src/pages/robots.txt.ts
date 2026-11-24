import type { APIRoute } from 'astro';

// robots.txt generowany przy buildzie — adres sitemapy zależy od SITE_URL,
// więc ten sam kod obsługuje studio-komp.pl i sk.mati-it.pl.
export const GET: APIRoute = ({ site }) => {
  const origin = (site ?? new URL('https://studio-komp.pl')).href.replace(/\/$/, '');
  const body = `User-agent: *
Allow: /

# Strony techniczne i kampanijne
Disallow: /lp/
Disallow: /edycja/
Disallow: /dziekujemy/
Disallow: /logo-export/
Disallow: /og-image/

Sitemap: ${origin}/sitemap-index.xml
`;

  return new Response(body, {
    headers: { 'Content-Type': 'text/plain; charset=utf-8' },
  });
};
