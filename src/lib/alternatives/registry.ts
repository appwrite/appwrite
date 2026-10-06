import type { AlternativeId, AlternativeMeta } from '@/lib/alternatives/types'

export const ALTERNATIVE_IDS = [
  'supabase',
  'firebase',
  'vercel',
  'netlify',
  'neon',
  'auth0',
  'convex',
  'cloudinary',
  'clerk',
  'amplify',
  'planetscale',
] as const satisfies readonly AlternativeId[]

/** Month the competitor facts on these pages were last checked against public sources. */
export const ALTERNATIVES_VERIFIED_ON = 'October 2026'

export const ALTERNATIVE_REGISTRY: Record<AlternativeId, AlternativeMeta> = {
  supabase: {
    id: 'supabase',
    name: 'Supabase',
    category: 'Postgres development platform',
    metaTitle: 'Appwrite vs Supabase: an open-source Supabase alternative',
    metaDescription:
      'Compare Appwrite and Supabase. Both are open source. Appwrite adds web hosting, messaging, simple permissions, and functions in 13+ runtimes on one platform.',
    summary: 'Hosting, messaging, and permissions without SQL policies.',
    tone: 'purple',
    secondaryTone: 'mint',
  },
  firebase: {
    id: 'firebase',
    name: 'Firebase',
    category: 'App development platform',
    metaTitle: 'Appwrite vs Firebase: an open-source Firebase alternative',
    metaDescription:
      'Compare Appwrite and Firebase. The same all-in-one backend, open source and self-hostable, with budget caps, readable permissions, and a free migration tool.',
    summary: 'Open source, self-hostable, and a free migration path.',
    tone: 'orange',
    secondaryTone: 'pink',
  },
  vercel: {
    id: 'vercel',
    name: 'Vercel',
    category: 'Frontend cloud',
    metaTitle: 'Appwrite vs Vercel: a Vercel alternative with the backend built in',
    metaDescription:
      'Compare Appwrite Sites and Vercel. Host Next.js, Nuxt, SvelteKit, and more next to first-party auth, databases, storage, and functions. No per-seat pricing.',
    summary: 'Frontend hosting with the backend in the same project.',
    tone: 'pink',
    secondaryTone: 'mint',
    focusProduct: 'sites',
  },
  netlify: {
    id: 'netlify',
    name: 'Netlify',
    category: 'Web hosting platform',
    metaTitle: 'Appwrite vs Netlify: hosting with a complete backend in the same project',
    metaDescription:
      'Compare Appwrite Sites and Netlify. Deploy from Git next to first-party auth, databases, storage, functions, realtime, and messaging, with 2TB of bandwidth and no deploy credits.',
    summary: 'Your site and its backend, in one project.',
    tone: 'mint',
    secondaryTone: 'orange',
    focusProduct: 'sites',
  },
  neon: {
    id: 'neon',
    name: 'Neon',
    category: 'Serverless Postgres',
    metaTitle: 'Appwrite vs Neon: managed Postgres plus a complete backend',
    metaDescription:
      'Compare Appwrite and Neon. Managed PostgreSQL with HA replicas and PITR, plus auth, storage, functions, realtime, messaging, and hosting in one open-source platform.',
    summary: 'Postgres plus the whole backend around it.',
    tone: 'purple',
    secondaryTone: 'orange',
    focusProduct: 'postgres',
  },
  auth0: {
    id: 'auth0',
    name: 'Auth0',
    category: 'Identity platform',
    metaTitle: 'Appwrite Auth vs Auth0: an open-source Auth0 alternative',
    metaDescription:
      'Compare Appwrite Auth and Auth0. 75K monthly active users free, 200K on Pro, MFA and password policies included, open source and self-hostable.',
    summary: '3x the free monthly active users and no growth penalty.',
    tone: 'pink',
    secondaryTone: 'purple',
    focusProduct: 'auth',
  },
  convex: {
    id: 'convex',
    name: 'Convex',
    category: 'Reactive TypeScript backend',
    metaTitle: 'Appwrite vs Convex: an open-source Convex alternative',
    metaDescription:
      'Compare Appwrite and Convex. Unlike Convex, Appwrite is fully open source, with first-party auth, realtime on every service, functions in 13+ runtimes, and built-in hosting.',
    summary: 'Actually open source, in any language.',
    tone: 'orange',
    secondaryTone: 'purple',
  },
  cloudinary: {
    id: 'cloudinary',
    name: 'Cloudinary',
    category: 'Media platform',
    metaTitle: 'Appwrite vs Cloudinary: a Cloudinary alternative for app media',
    metaDescription:
      'Compare Appwrite Storage and Cloudinary. Image transformations billed per origin image, 5GB uploads, file permissions, and a full open-source backend, with Appwrite Videos coming soon.',
    summary: 'Pay per origin image, not per variant.',
    tone: 'mint',
    secondaryTone: 'pink',
    focusProduct: 'storage',
  },
  clerk: {
    id: 'clerk',
    name: 'Clerk',
    category: 'User management and authentication',
    metaTitle: 'Appwrite Auth vs Clerk: an open-source Clerk alternative',
    metaDescription:
      'Compare Appwrite Auth and Clerk. 200K monthly active users on Pro, then $3 per 1,000, MFA on every plan, and your users stored next to your data in one open-source backend.',
    summary: 'Users and data in one place, no webhooks to sync.',
    tone: 'purple',
    secondaryTone: 'pink',
    focusProduct: 'auth',
  },
  amplify: {
    id: 'amplify',
    name: 'AWS Amplify',
    category: 'Full-stack framework on AWS',
    metaTitle: 'Appwrite vs AWS Amplify: an open-source Amplify alternative',
    metaDescription:
      'Compare Appwrite and AWS Amplify. One open-source backend with auth, databases, storage, functions, messaging, and hosting, one Console, and one bill instead of a stack of AWS services.',
    summary: 'One project instead of a stack of AWS services.',
    tone: 'orange',
    secondaryTone: 'mint',
  },
  planetscale: {
    id: 'planetscale',
    name: 'PlanetScale',
    category: 'Managed MySQL and Postgres',
    metaTitle: 'Appwrite vs PlanetScale: managed PostgreSQL and MySQL plus a complete backend',
    metaDescription:
      'Compare Appwrite and PlanetScale. Managed PostgreSQL and MySQL with replicas and PITR, three more database models, a free plan, and auth, storage, functions, and hosting in one open-source project.',
    summary: 'Postgres and MySQL, plus the backend around them.',
    tone: 'mint',
    secondaryTone: 'purple',
    focusProduct: 'postgres',
  },
}

export function isAlternativeId(value: string): value is AlternativeId {
  return (ALTERNATIVE_IDS as readonly string[]).includes(value)
}

export function getAlternativePath(id: AlternativeId): `/alternative-to/${AlternativeId}` {
  return `/alternative-to/${id}`
}
