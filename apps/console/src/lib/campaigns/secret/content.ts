import type { SecretCampaignVariant } from './registry'

/**
 * Copy for the "The secret ... is hiding from you" ad landing pages.
 * Every competitor finding links to the competitor's own docs or pricing page.
 * Checked against public pricing and documentation in October 2026.
 */

export type SecretSuspect = 'supabase' | 'firebase'

export type SecretSuspectMeta = {
  name: string
  comparisonHref: string
  comparisonLabel: string
  migrationHref: string
  migrationDetail: string
  fairPlay: string[]
}

export const SECRET_SUSPECTS: Record<SecretSuspect, SecretSuspectMeta> = {
  supabase: {
    name: 'Supabase',
    comparisonHref: '/alternative-to/supabase',
    comparisonLabel: 'Read the full Supabase comparison',
    migrationHref: '/docs/advanced/migrations/supabase',
    migrationDetail: 'Import users, databases, and files from a Supabase project.',
    fairPlay: [
      'Your team relies mostly on Postgres and does not need much else from the platform.',
      'Your AI app builder has a one-click Supabase integration your workflow depends on.',
    ],
  },
  firebase: {
    name: 'Firebase',
    comparisonHref: '/alternative-to/firebase',
    comparisonLabel: 'Read the full Firebase comparison',
    migrationHref: '/docs/advanced/migrations/firebase',
    migrationDetail:
      'Bring over users, Firestore collections, and Storage files. Existing passwords keep working.',
    fairPlay: [
      'You are deep in Google Cloud and want BigQuery, Analytics, and Crashlytics wired together.',
      'You want analytics, crash reporting, remote config, and A/B testing from one vendor.',
    ],
  },
}

export type SecretTopic =
  | 'database'
  | 'functions'
  | 'hosting'
  | 'messaging'
  | 'lockin'
  | 'billing'
  | 'sunsets'
  | 'rules'

export type SecretSource = { label: string; href: string }

export type SecretFinding = {
  /** The line that sits under the redaction bar until the exhibit is declassified. */
  claim: string
  detail: string
  source: SecretSource
}

export type SecretAnswer = { claim: string; detail: string; href: string }

export const SECRET_TOPIC_LABELS: Record<SecretTopic, string> = {
  database: 'Databases',
  functions: 'Functions',
  hosting: 'Hosting',
  messaging: 'Messaging',
  lockin: 'Lock-in',
  billing: 'Billing',
  sunsets: 'Roadmap',
  rules: 'Access rules',
}

export const SECRET_FINDINGS: Record<SecretTopic, Partial<Record<SecretSuspect, SecretFinding>>> = {
  database: {
    supabase: {
      claim: 'PostgreSQL, and only PostgreSQL',
      detail: 'Every project is a single Postgres database. Documents and vectors have to fit inside it.',
      source: { label: 'supabase.com/docs/guides/database', href: 'https://supabase.com/docs/guides/database/overview' },
    },
    firebase: {
      claim: 'Documents, unless you add Cloud SQL',
      detail:
        'Firestore is NoSQL. Relational data means SQL Connect, a separate Cloud SQL instance from $9.37/mo after a 3-month trial.',
      source: { label: 'firebase.google.com/pricing', href: 'https://firebase.google.com/pricing' },
    },
  },
  functions: {
    supabase: {
      claim: 'TypeScript, and nothing else',
      detail: 'Edge Functions run TypeScript on a Deno runtime. Python, Go, and the rest live somewhere else.',
      source: { label: 'supabase.com/docs/guides/functions', href: 'https://supabase.com/docs/guides/functions' },
    },
    firebase: {
      claim: 'No functions without Blaze',
      detail:
        'Cloud Functions are not available on the no-cost Spark plan. You need pay-as-you-go Blaze, and you get Node.js or Python.',
      source: { label: 'firebase.google.com/pricing', href: 'https://firebase.google.com/pricing' },
    },
  },
  hosting: {
    supabase: {
      claim: 'No web hosting',
      detail: 'Supabase runs your backend only. Your frontend goes on Vercel, Netlify, or another host, with another bill.',
      source: {
        label: 'github.com/orgs/supabase/discussions/47156',
        href: 'https://github.com/orgs/supabase/discussions/47156',
      },
    },
    firebase: {
      claim: 'SSR hosting needs Blaze',
      detail: 'Static Hosting works on the free plan, but App Hosting for SSR frameworks requires the Blaze plan.',
      source: { label: 'firebase.google.com/pricing', href: 'https://firebase.google.com/pricing' },
    },
  },
  messaging: {
    supabase: {
      claim: 'No messaging API',
      detail: 'Auth emails and sign-in codes are covered. Product email, SMS, and push need another service.',
      source: { label: 'supabase.com/features', href: 'https://supabase.com/features' },
    },
    firebase: {
      claim: 'Push, and only push',
      detail: 'Cloud Messaging sends push notifications. Email and SMS to your users need another provider.',
      source: {
        label: 'firebase.google.com/docs/cloud-messaging',
        href: 'https://firebase.google.com/docs/cloud-messaging',
      },
    },
  },
  lockin: {
    supabase: {
      claim: 'Self-hosting gets you one project',
      detail:
        'Self-hosted Supabase is community supported, runs a single project, and leaves out branching, managed backups, and PITR.',
      source: { label: 'supabase.com/docs/guides/self-hosting', href: 'https://supabase.com/docs/guides/self-hosting' },
    },
    firebase: {
      claim: 'Closed source, Google Cloud only',
      detail: 'Every Firebase project is a Google Cloud project. There is no way to run it on your own servers.',
      source: {
        label: 'firebase.google.com/docs/projects/learn-more',
        href: 'https://firebase.google.com/docs/projects/learn-more',
      },
    },
  },
  billing: {
    supabase: {
      claim: '250GB of egress on Pro',
      detail: 'Pro includes 100K monthly active users and 250GB of egress, then $3.25 per 1,000 users and $0.09 per GB.',
      source: { label: 'supabase.com/pricing', href: 'https://supabase.com/pricing' },
    },
    firebase: {
      claim: 'Spend caps skip your database',
      detail:
        'Spend caps are in Preview, cover four services, and leave out Firestore, Storage, and Auth. Google says they are not hard caps.',
      source: {
        label: 'firebase.google.com/docs/projects/billing/spend-caps',
        href: 'https://firebase.google.com/docs/projects/billing/spend-caps',
      },
    },
  },
  sunsets: {
    firebase: {
      claim: 'Shutdown dates on the calendar',
      detail:
        'Dynamic Links shut down in August 2025. Firebase Studio sunsets on March 22, 2027, and Extensions on March 31, 2027.',
      source: {
        label: 'firebase.google.com/docs/studio/migrating-project',
        href: 'https://firebase.google.com/docs/studio/migrating-project',
      },
    },
  },
  rules: {
    firebase: {
      claim: 'A rules language of its own',
      detail: 'Firestore and Storage access lives in Security Rules, a separate language you write, test, and deploy.',
      source: { label: 'firebase.google.com/docs/rules', href: 'https://firebase.google.com/docs/rules' },
    },
  },
}

export const SECRET_ANSWERS: Record<SecretTopic, SecretAnswer> = {
  database: {
    claim: 'Five database models in one project',
    detail:
      'TablesDB on serverless or dedicated compute, plus DocumentsDB, VectorsDB, PostgreSQL, and MySQL on dedicated compute.',
    href: '/products/databases',
  },
  functions: {
    claim: '13+ runtimes, on the Free plan too',
    detail: 'Node.js, Bun, Python, Go, Dart, PHP, Ruby, Rust, Deno, and more, with 750K executions a month for free.',
    href: '/products/functions',
  },
  hosting: {
    claim: 'Sites, next to your backend',
    detail: 'Static and SSR hosting with Git deploys and previews on every plan, Free included.',
    href: '/products/sites',
  },
  messaging: {
    claim: 'Email, SMS, and push from one API',
    detail: 'Appwrite Messaging connects 12 providers, with topics, targeting, and scheduling built in.',
    href: '/products/messaging',
  },
  lockin: {
    claim: 'Open source, run it anywhere',
    detail:
      'Self-host with one Docker command and run many projects with the same APIs, SDKs, and Console as Appwrite Cloud.',
    href: '/docs/advanced/self-hosting',
  },
  billing: {
    claim: '2TB bandwidth and one budget cap',
    detail:
      'Pro is $25/mo with 200K monthly active users, 2TB bandwidth, and an organization-wide budget cap.',
    href: '/pricing',
  },
  sunsets: {
    claim: 'The code is yours to keep',
    detail: 'Appwrite is open source, so the backend you build on stays yours to run on Appwrite Cloud or your own servers.',
    href: '/docs/advanced/self-hosting',
  },
  rules: {
    claim: 'Permissions you can read',
    detail: 'Role strings on tables, rows, buckets, and files, set from the Console or SDK and enforced on every API.',
    href: '/docs/advanced/security/permissions',
  },
}

/** `answer` overrides the shared Appwrite answer when one suspect needs a sharper reply. */
export type SecretExhibit = { topic: SecretTopic; headline: string; answer?: SecretAnswer }

export type SecretVerdictValue = true | false | 'partial' | string
export type SecretVerdictCell = { value: SecretVerdictValue; note?: string }

export type SecretVerdictRow = {
  label: string
  appwrite: SecretVerdictCell
  supabase: SecretVerdictCell
  firebase: SecretVerdictCell
}

export const SECRET_VERDICT_ROWS: SecretVerdictRow[] = [
  {
    label: 'Open source',
    appwrite: { value: true },
    supabase: { value: true },
    firebase: { value: false },
  },
  {
    label: 'Self-host many projects',
    appwrite: { value: true, note: 'One Docker install' },
    supabase: { value: false, note: 'One project per instance' },
    firebase: { value: false, note: 'No self-hosting' },
  },
  {
    label: 'Database models',
    appwrite: { value: '5', note: 'Tables, documents, vectors, PostgreSQL, MySQL' },
    supabase: { value: '1', note: 'PostgreSQL' },
    firebase: { value: 'Documents', note: 'SQL through Cloud SQL, billed separately' },
  },
  {
    label: 'Function runtimes',
    appwrite: { value: '13+', note: 'Free plan included' },
    supabase: { value: 'TypeScript', note: 'Deno runtime' },
    firebase: { value: 'Node.js and Python', note: 'Blaze plan only' },
  },
  {
    label: 'Web hosting',
    appwrite: { value: true, note: 'Static and SSR on every plan' },
    supabase: { value: false },
    firebase: { value: 'partial', note: 'SSR requires Blaze' },
  },
  {
    label: 'Email, SMS, and push',
    appwrite: { value: true },
    supabase: { value: false },
    firebase: { value: 'partial', note: 'Push only' },
  },
  {
    label: 'Budget cap',
    appwrite: { value: true, note: 'Organization-wide, on Pro' },
    supabase: { value: true, note: 'Spend cap on Pro' },
    firebase: { value: 'partial', note: 'Preview, four services' },
  },
  {
    label: 'Paid plan',
    appwrite: { value: '$25/mo', note: '200K MAU, 2TB bandwidth' },
    supabase: { value: '$25/mo', note: '100K MAU, 250GB egress' },
    firebase: { value: 'Pay as you go', note: 'Blaze, billed by usage' },
  },
]

export type SecretVariantContent = {
  suspects: SecretSuspect[]
  metaTitle: string
  metaDescription: string
  /** Contains `{suspects}`, where the colored names render. */
  heroTitle: string
  exhibits: SecretExhibit[]
  reveal: { title: string; description: string }
}

export const SECRET_VARIANT_CONTENT: Record<SecretCampaignVariant, SecretVariantContent> = {
  'supabase-and-firebase': {
    suspects: ['supabase', 'firebase'],
    metaTitle: 'The secret Supabase and Firebase are hiding from you',
    metaDescription:
      'The fine print on databases, functions, hosting, and billing, with links to their own docs. Then meet the open-source cloud that does it all, and make your own call.',
    heroTitle: 'The secret {suspects} are hiding from you',
    exhibits: [
      { topic: 'database', headline: 'Pick a database. Just one.' },
      { topic: 'functions', headline: 'Your code speaks their language.' },
      { topic: 'hosting', headline: 'Your frontend lives somewhere else.' },
      { topic: 'messaging', headline: 'Reaching your users takes another vendor.' },
      { topic: 'lockin', headline: 'Leaving is harder than joining.' },
      { topic: 'billing', headline: 'The fine print on your bill.' },
    ],
    reveal: {
      title: 'You never had to pick between them',
      description:
        'Appwrite is the open-source cloud that brings it all together: auth, five database models, storage, functions in 13+ runtimes, realtime, messaging, and web hosting. Run it on Appwrite Cloud or on your own servers.',
    },
  },
  supabase: {
    suspects: ['supabase'],
    metaTitle: 'The secret Supabase is hiding from you',
    metaDescription:
      'The fine print on Supabase databases, functions, hosting, and pricing, with links to its own docs. Then meet the open-source cloud that does more, and make your own call.',
    heroTitle: 'The secret {suspects} is hiding from you',
    exhibits: [
      { topic: 'database', headline: 'Pick a database. Just one.' },
      { topic: 'hosting', headline: 'Your frontend lives somewhere else.' },
      { topic: 'functions', headline: 'Your code speaks their language.' },
      { topic: 'lockin', headline: 'Self-hosting is the lite version.' },
      {
        topic: 'billing',
        headline: 'The fine print on your bill.',
        answer: {
          claim: 'Twice the users, eight times the bandwidth',
          detail: 'Appwrite Pro is also $25/mo, with 200K monthly active users, 2TB bandwidth, and $3 per 1,000 extra users.',
          href: '/pricing',
        },
      },
      { topic: 'messaging', headline: 'Reaching your users takes another vendor.' },
    ],
    reveal: {
      title: 'Keep Postgres. Get everything else',
      description:
        'Appwrite runs managed PostgreSQL next to four more database models, plus auth, storage, functions in 13+ runtimes, realtime, messaging, and web hosting. All open source, on Appwrite Cloud or your own servers.',
    },
  },
  firebase: {
    suspects: ['firebase'],
    metaTitle: 'The secret Firebase is hiding from you',
    metaDescription:
      'The fine print on Firebase billing, functions, databases, and lock-in, with links to its own docs. Then meet the open-source cloud you can own, and make your own call.',
    heroTitle: 'The secret {suspects} is hiding from you',
    exhibits: [
      { topic: 'lockin', headline: 'You can never take it with you.' },
      {
        topic: 'billing',
        headline: 'The cap is not really a cap.',
        answer: {
          claim: 'One budget cap for your whole organization',
          detail:
            'On Pro, a single cap limits automatic scaling across all your projects, with email warnings before you reach it.',
          href: '/docs/advanced/billing/pro#budget-cap',
        },
      },
      { topic: 'functions', headline: 'Writing backend code needs a billing account.' },
      { topic: 'database', headline: 'SQL is a separate product.' },
      { topic: 'sunsets', headline: 'Products come with an expiry date.' },
      { topic: 'rules', headline: 'Security takes a language of its own.' },
    ],
    reveal: {
      title: 'An all-in-one cloud you can actually own',
      description:
        'Appwrite gives you the all-in-one experience, with auth, databases, storage, functions, realtime, messaging, and hosting, except it is open source and runs on Appwrite Cloud or your own servers.',
    },
  },
}

export function getSecretVariantContent(variant: SecretCampaignVariant): SecretVariantContent {
  return SECRET_VARIANT_CONTENT[variant]
}
