import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// BASE_PATH lets us build a preview copy under a subdirectory of the current
// hosting (e.g. https://studio-komp.pl/nowa/) without touching DNS.
const base = process.env.BASE_PATH || '/';

// SITE_URL pozwala zbudować kopię pod innym adresem (np. staging na
// sk.mati-it.pl), żeby canonical, sitemap i Open Graph wskazywały na ten adres.
// Domyślnie zostaje docelowa domena Studio-Komp.
const site = process.env.SITE_URL || 'https://studio-komp.pl';

export default defineConfig({
  site,
  base,
  trailingSlash: 'always',
  build: {
    format: 'directory',
    inlineStylesheets: 'auto',
  },
  prefetch: {
    prefetchAll: true,
    defaultStrategy: 'viewport',
  },
  devToolbar: {
    enabled: false,
  },
  integrations: [
    sitemap({
      filter: (page) =>
        !page.includes('/lp/') &&
        !page.includes('/edycja') &&
        !page.includes('/polityka-prywatnosci/') &&
        !page.includes('/og-image') &&
        !page.includes('/logo-export') &&
        !page.includes('/dziekujemy') &&
        !page.includes('/404'),
      changefreq: 'weekly',
      priority: 0.7,
    }),
  ],
  vite: {
    build: {
      cssMinify: 'lightningcss',
    },
  },
});
