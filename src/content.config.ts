import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

const uslugi = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/uslugi' }),
  schema: z.object({
    title: z.string(), // <title> bez nazwy firmy
    navLabel: z.string(), // krótka nazwa w menu
    description: z.string(), // meta description
    h1: z.string(),
    lead: z.string(), // lead pod H1
    teaser: z.string(), // jedno zdanie do listy usług
    group: z.enum(['Konserwacja', 'Naprawy', 'Elektronika', 'System i dane']),
    order: z.number().default(50),
    featured: z.boolean().default(false),
    tile: z.boolean().default(false), // kafelek w sekcji „Co najczęściej naprawiamy”
    tileTitle: z.string().optional(), // puste = navLabel
    tileText: z.string().optional(), // opis procesu naprawy na kafelku (20-35 słów)
    priceFrom: z.string().optional(),
    priceNote: z.string().optional(),
    duration: z.string().optional(),
    image: z.string().optional(), // zdjęcie/ilustracja do sekcji „Co naprawiamy najczęściej”
    imageAlt: z.string().optional(),
    includes: z.array(z.string()).default([]),
    problems: z.array(z.string()).default([]),
    faq: z
      .array(z.object({ question: z.string(), answer: z.string() }))
      .default([]),
    related: z.array(z.string()).default([]),
    updated: z.coerce.date().optional(),
    draft: z.boolean().default(false),
  }),
});

const blog = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/blog' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    pubDate: z.coerce.date(),
    updatedDate: z.coerce.date().optional(),
    category: z.enum([
      'Naprawa',
      'Konserwacja',
      'Wydajność',
      'Dane i system',
      'Zakup i modernizacja',
      'Poradnik',
    ]),
    service: z.string().optional(), // slug usługi, do której prowadzi artykuł
    readingTime: z.number().optional(),
    faq: z
      .array(z.object({ question: z.string(), answer: z.string() }))
      .default([]),
    draft: z.boolean().default(false),
  }),
});

// Podstrony marek: /serwis-asus-wroclaw/ itd.
const marki = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/marki' }),
  schema: z.object({
    brand: z.string(),
    title: z.string(),
    description: z.string(),
    h1: z.string(),
    lead: z.string(),
    problems: z.array(z.string()).default([]),
    repairs: z.array(z.string()).default([]),
    models: z.array(z.string()).default([]),
    services: z.array(z.string()).default([]),
    faq: z
      .array(z.object({ question: z.string(), answer: z.string() }))
      .default([]),
    image: z.string().optional(),
    imageAlt: z.string().optional(),
    order: z.number().default(50),
    review: z.enum(['pending', 'approved']).default('pending'),
    updated: z.coerce.date().optional(),
    draft: z.boolean().default(false),
  }),
});

// Dowolne podstrony tworzone z panelu (CMS): /<slug>/
const strony = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/strony' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    h1: z.string(),
    lead: z.string().optional(),
    navLabel: z.string().optional(),
    showInNav: z.boolean().default(false),
    order: z.number().default(50),
    noindex: z.boolean().default(false),
    updated: z.coerce.date().optional(),
    draft: z.boolean().default(false),
  }),
});

export const collections = { uslugi, blog, marki, strony };
