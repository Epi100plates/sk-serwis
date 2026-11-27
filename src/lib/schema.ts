import site from '../data/site.json';
import { absoluteUrl, SITE_ORIGIN } from './url';
import { applyPriceTokens } from './prices';

type Crumb = { label: string; path?: string };

export function breadcrumbSchema(items: Crumb[]) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((item, i) => ({
      '@type': 'ListItem',
      position: i + 1,
      name: applyPriceTokens(item.label),
      ...(item.path ? { item: absoluteUrl(item.path) } : {}),
    })),
  };
}

export function faqSchema(items: { q: string; a: string }[]) {
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: items.map((item) => ({
      '@type': 'Question',
      name: applyPriceTokens(item.q),
      acceptedAnswer: {
        '@type': 'Answer',
        text: applyPriceTokens(item.a),
      },
    })),
  };
}

export function serviceSchema(opts: {
  name: string;
  description: string;
  path: string;
  priceFrom?: string;
  serviceType?: string;
}) {
  const resolvedPriceFrom = opts.priceFrom ? applyPriceTokens(opts.priceFrom) : undefined;
  const numericPrice = resolvedPriceFrom?.match(/(\d+)/)?.[1];
  return {
    '@context': 'https://schema.org',
    '@type': 'Service',
    name: applyPriceTokens(opts.name),
    description: applyPriceTokens(opts.description),
    serviceType: applyPriceTokens(opts.serviceType ?? opts.name),
    url: absoluteUrl(opts.path),
    areaServed: { '@type': 'City', name: 'Wrocław' },
    provider: {
      '@type': 'LocalBusiness',
      '@id': `${SITE_ORIGIN}/#serwis`,
      name: 'Studio-Komp',
      telephone: site.phoneTel,
      address: {
        '@type': 'PostalAddress',
        streetAddress: site.address.street,
        postalCode: site.address.postalCode,
        addressLocality: site.address.city,
        addressCountry: 'PL',
      },
    },
    ...(numericPrice
      ? {
          offers: {
            '@type': 'Offer',
            priceCurrency: 'PLN',
            price: numericPrice,
            availability: 'https://schema.org/InStock',
            url: absoluteUrl(opts.path),
          },
        }
      : {}),
  };
}

export function articleSchema(opts: {
  title: string;
  description: string;
  path: string;
  published: string;
  modified?: string;
  image?: string;
}) {
  return {
    '@context': 'https://schema.org',
    '@type': 'Article',
    headline: applyPriceTokens(opts.title),
    description: applyPriceTokens(opts.description),
    url: absoluteUrl(opts.path),
    datePublished: opts.published,
    dateModified: opts.modified ?? opts.published,
    inLanguage: 'pl-PL',
    ...(opts.image ? { image: absoluteUrl(opts.image) } : {}),
    author: {
      '@type': 'Organization',
      name: 'Studio-Komp',
      url: `${SITE_ORIGIN}/`,
    },
    publisher: {
      '@type': 'Organization',
      name: 'Studio-Komp',
      url: `${SITE_ORIGIN}/`,
    },
    mainEntityOfPage: {
      '@type': 'WebPage',
      '@id': absoluteUrl(opts.path),
    },
  };
}
