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
    priceFrom: z.string().optional(),
    priceNote: z.string().optional(),
    duration: z.string().optional(),
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

export const collections = { uslugi, blog };
