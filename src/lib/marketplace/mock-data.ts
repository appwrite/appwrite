import type { LucideIcon } from 'lucide-react'
import {
  BarChart3,
  CreditCard,
  HardDrive,
  MessageSquare,
  Shield,
  Sparkles,
  Wrench,
} from 'lucide-react'

export type MarketplaceAppCategory =
  | 'auth'
  | 'storage'
  | 'analytics'
  | 'payments'
  | 'ai'
  | 'devtools'
  | 'messaging'

export type MarketplaceAppStatus = 'published' | 'draft' | 'pending'

export type MarketplaceAppCreator = {
  name: string
  role?: string
}

export type MarketplaceApp = {
  $id: string
  name: string
  slug: string
  description: string
  shortDescription: string
  category: MarketplaceAppCategory
  author: string
  creators: MarketplaceAppCreator[]
  installs: number
  rating: number
  featured: boolean
  /** Published by Appwrite. */
  isOfficial: boolean
  /** Reviewed and trusted by Appwrite. */
  isVerified: boolean
  isOwned: boolean
  status: MarketplaceAppStatus
  tags: string[]
  $createdAt: string
}

export const MARKETPLACE_CATEGORY_LABELS: Record<
  MarketplaceAppCategory,
  string
> = {
  auth: 'Authentication',
  storage: 'Storage',
  analytics: 'Analytics',
  payments: 'Payments',
  ai: 'AI & ML',
  devtools: 'Developer tools',
  messaging: 'Messaging',
}

export const MARKETPLACE_CATEGORY_ICONS: Record<
  MarketplaceAppCategory,
  LucideIcon
> = {
  auth: Shield,
  storage: HardDrive,
  analytics: BarChart3,
  payments: CreditCard,
  ai: Sparkles,
  devtools: Wrench,
  messaging: MessageSquare,
}

/** Creators by app id (mock until API ships). */
export const MARKETPLACE_CREATORS_BY_ID: Record<
  string,
  MarketplaceAppCreator[]
> = {
  'app-stripe-billing': [
    { name: 'Eldad Fux', role: 'Lead' },
    { name: 'Torsten Dahlstrom', role: 'Engineering' },
  ],
  'app-auth0-bridge': [
    { name: 'Happy Quinn', role: 'Creator' },
    { name: 'Paige Dineen', role: 'Maintainer' },
  ],
  'app-s3-mirror': [
    { name: 'Christy Jacob', role: 'Product' },
    { name: 'Matej Bačo', role: 'Engineering' },
  ],
  'app-posthog': [
    { name: 'James Hawkins', role: 'Product' },
    { name: 'Tim Glomb', role: 'Integrations' },
  ],
  'app-openai-assistant': [
    { name: 'Darshan Pandya', role: 'Engineering' },
    { name: 'Christy Jacob', role: 'Product' },
  ],
  'app-twilio-sms': [
    { name: 'Megan O\'Brien', role: 'Partnerships' },
    { name: 'Toby Curtis', role: 'SDK' },
  ],
  'app-github-deploy': [
    { name: 'Ray Spiewack', role: 'Creator' },
    { name: 'Patricia Logan', role: 'Maintainer' },
  ],
  'app-sendgrid': [
    { name: 'Drew Baker', role: 'Integrations' },
    { name: 'Florence Tipton', role: 'Engineering' },
  ],
  'app-clerk-sync': [{ name: 'Ralph Dineen', role: 'Creator' }],
  'app-datadog': [
    { name: 'Sylvester Dodd', role: 'Product' },
    { name: 'Mark Collins', role: 'Engineering' },
  ],
  'app-tables-backup': [
    { name: 'Matej Bačo', role: 'Engineering' },
    { name: 'Darshan Pandya', role: 'Engineering' },
  ],
  'app-magic-mfa': [
    { name: 'Eldad Fux', role: 'Lead' },
    { name: 'Christy Jacob', role: 'Product' },
  ],
  'app-edge-cdn': [
    { name: 'Torsten Dahlstrom', role: 'Engineering' },
    { name: 'Matej Bačo', role: 'Engineering' },
  ],
  'app-functions-cron': [
    { name: 'Darshan Pandya', role: 'Lead' },
    { name: 'Christy Jacob', role: 'Product' },
  ],
  'app-slack-notify': [
    { name: 'Cabe Gallo', role: 'Partnerships' },
    { name: 'Megan O\'Brien', role: 'Integrations' },
  ],
  'app-vercel-deploy': [
    { name: 'Guillermo Rauch', role: 'Product' },
    { name: 'Lee Robinson', role: 'Developer experience' },
  ],
  'app-r2-sync': [
    { name: 'Happy Quinn', role: 'Integrations' },
    { name: 'Ray Spiewack', role: 'Engineering' },
  ],
  'app-paddle-billing': [
    { name: 'Katherine Cooper', role: 'Product' },
    { name: 'Toby Curtis', role: 'Engineering' },
  ],
  'app-linear-sync': [
    { name: 'Karri Saarinen', role: 'Product' },
    { name: 'Tuomas Artman', role: 'Engineering' },
  ],
  'app-anthropic-kit': [
    { name: 'Dario Amodei', role: 'Product' },
    { name: 'Daniela Amodei', role: 'Partnerships' },
  ],
  'app-acme-webhooks': [
    { name: "Walter O'Brien", role: 'Lead' },
    { name: 'Paige Dineen', role: 'Engineering' },
  ],
  'app-acme-onboarding': [{ name: "Walter O'Brien", role: 'Creator' }],
}

export function formatMarketplaceCreators(
  creators: MarketplaceAppCreator[],
): string {
  if (creators.length === 0) return '—'
  if (creators.length === 1) return creators[0].name
  if (creators.length === 2) {
    return `${creators[0].name} and ${creators[1].name}`
  }
  return `${creators[0].name} and ${creators.length - 1} others`
}

type MarketplaceAppWithoutCreators = Omit<MarketplaceApp, 'creators'>

function withMarketplaceCreators(
  app: MarketplaceAppWithoutCreators,
): MarketplaceApp {
  return {
    ...app,
    creators:
      MARKETPLACE_CREATORS_BY_ID[app.$id] ??
      (app.author === 'Your organization'
        ? [{ name: 'Your team' }]
        : [{ name: app.author, role: 'Publisher' }]),
  }
}

/** Catalog apps available in the marketplace (mock until API ships). */
const MOCK_MARKETPLACE_CATALOG_RAW: MarketplaceAppWithoutCreators[] = [
  {
    $id: 'app-stripe-billing',
    name: 'Stripe Billing',
    slug: 'stripe-billing',
    description:
      'Accept subscriptions and one-time payments with Stripe. Sync customers, invoices, and webhooks into your Appwrite project.',
    shortDescription: 'Subscriptions and payments with Stripe',
    category: 'payments',
    author: 'Appwrite',
    installs: 12400,
    rating: 4.8,
    featured: true,
    isOfficial: true,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['payments', 'billing', 'stripe'],
    $createdAt: '2024-06-12T10:00:00.000Z',
  },
  {
    $id: 'app-auth0-bridge',
    name: 'Auth0 Bridge',
    slug: 'auth0-bridge',
    description:
      'Connect Auth0 tenants to Appwrite Auth. Map roles, sync users on login, and enforce SSO across your apps.',
    shortDescription: 'SSO and user sync with Auth0',
    category: 'auth',
    author: 'Community',
    installs: 8200,
    rating: 4.6,
    featured: true,
    isOfficial: false,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['auth', 'sso', 'oauth'],
    $createdAt: '2024-05-01T14:30:00.000Z',
  },
  {
    $id: 'app-s3-mirror',
    name: 'S3 Mirror',
    slug: 's3-mirror',
    description:
      'Mirror Appwrite Storage buckets to Amazon S3. Configure lifecycle rules and cross-region replication from the console.',
    shortDescription: 'Replicate buckets to Amazon S3',
    category: 'storage',
    author: 'Appwrite',
    installs: 6100,
    rating: 4.5,
    featured: true,
    isOfficial: true,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['storage', 'aws', 'backup'],
    $createdAt: '2024-04-18T09:15:00.000Z',
  },
  {
    $id: 'app-posthog',
    name: 'PostHog Analytics',
    slug: 'posthog-analytics',
    description:
      'Forward function execution and auth events to PostHog. Build funnels and retention charts without custom instrumentation.',
    shortDescription: 'Product analytics via PostHog',
    category: 'analytics',
    author: 'PostHog',
    installs: 5400,
    rating: 4.7,
    featured: false,
    isOfficial: false,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['analytics', 'events'],
    $createdAt: '2024-03-22T11:00:00.000Z',
  },
  {
    $id: 'app-openai-assistant',
    name: 'OpenAI Assistant Kit',
    slug: 'openai-assistant-kit',
    description:
      'Deploy chat assistants backed by OpenAI. Store conversation threads in Tables DB and stream responses from Functions.',
    shortDescription: 'Chat assistants with OpenAI',
    category: 'ai',
    author: 'Appwrite',
    installs: 9800,
    rating: 4.9,
    featured: false,
    isOfficial: true,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['ai', 'openai', 'chat'],
    $createdAt: '2024-07-01T08:00:00.000Z',
  },
  {
    $id: 'app-twilio-sms',
    name: 'Twilio SMS',
    slug: 'twilio-sms',
    description:
      'Send SMS through Twilio from Messaging topics. Templates, delivery receipts, and rate limiting included.',
    shortDescription: 'SMS delivery through Twilio',
    category: 'messaging',
    author: 'Twilio',
    installs: 4300,
    rating: 4.4,
    featured: false,
    isOfficial: false,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['sms', 'messaging'],
    $createdAt: '2024-02-10T16:45:00.000Z',
  },
  {
    $id: 'app-github-deploy',
    name: 'GitHub Deploy Hooks',
    slug: 'github-deploy-hooks',
    description:
      'Trigger function and site deployments from GitHub Actions. Validate signatures and map branches to environments.',
    shortDescription: 'CI deploy hooks for GitHub',
    category: 'devtools',
    author: 'Community',
    installs: 11200,
    rating: 4.8,
    featured: false,
    isOfficial: false,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['ci', 'github', 'deploy'],
    $createdAt: '2024-01-05T12:00:00.000Z',
  },
  {
    $id: 'app-sendgrid',
    name: 'SendGrid Email',
    slug: 'sendgrid-email',
    description:
      'Route Messaging email topics through SendGrid. DKIM setup wizard and bounce handling built in.',
    shortDescription: 'Transactional email via SendGrid',
    category: 'messaging',
    author: 'SendGrid',
    installs: 7600,
    rating: 4.5,
    featured: false,
    isOfficial: false,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['email', 'messaging'],
    $createdAt: '2023-12-01T10:30:00.000Z',
  },
  {
    $id: 'app-clerk-sync',
    name: 'Clerk Sync',
    slug: 'clerk-sync',
    description:
      'Keep Clerk users in sync with Appwrite Auth. Webhook handlers and conflict resolution policies included.',
    shortDescription: 'User sync with Clerk',
    category: 'auth',
    author: 'Community',
    installs: 3900,
    rating: 4.3,
    featured: false,
    isOfficial: false,
    isVerified: false,
    isOwned: false,
    status: 'published',
    tags: ['auth', 'clerk'],
    $createdAt: '2024-08-14T13:20:00.000Z',
  },
  {
    $id: 'app-datadog',
    name: 'Datadog Observability',
    slug: 'datadog-observability',
    description:
      'Export function logs and execution metrics to Datadog. Pre-built dashboards for latency and error rates.',
    shortDescription: 'Logs and metrics in Datadog',
    category: 'analytics',
    author: 'Datadog',
    installs: 2800,
    rating: 4.6,
    featured: false,
    isOfficial: false,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['observability', 'logs'],
    $createdAt: '2024-09-02T07:00:00.000Z',
  },
  {
    $id: 'app-tables-backup',
    name: 'Tables DB Backup',
    slug: 'tables-db-backup',
    description:
      'Schedule automated backups for Tables DB with point-in-time restore. Export to Storage buckets or external object stores.',
    shortDescription: 'Automated Tables DB backups',
    category: 'storage',
    author: 'Appwrite',
    installs: 7200,
    rating: 4.7,
    featured: false,
    isOfficial: true,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['backup', 'tables', 'storage'],
    $createdAt: '2024-10-15T09:00:00.000Z',
  },
  {
    $id: 'app-magic-mfa',
    name: 'Magic MFA',
    slug: 'magic-mfa',
    description:
      'Add passkeys, TOTP, and SMS second factors to Appwrite Auth with enrollment flows and recovery codes out of the box.',
    shortDescription: 'Multi-factor auth for Appwrite',
    category: 'auth',
    author: 'Appwrite',
    installs: 8900,
    rating: 4.8,
    featured: true,
    isOfficial: true,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['auth', 'mfa', 'security'],
    $createdAt: '2024-11-02T11:30:00.000Z',
  },
  {
    $id: 'app-edge-cdn',
    name: 'Edge CDN Kit',
    slug: 'edge-cdn-kit',
    description:
      'Cache Storage assets and function responses at the edge. Purge by tag, path, or deployment from the console.',
    shortDescription: 'Edge caching for sites and storage',
    category: 'devtools',
    author: 'Appwrite',
    installs: 5100,
    rating: 4.6,
    featured: false,
    isOfficial: true,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['cdn', 'edge', 'cache'],
    $createdAt: '2024-11-20T08:45:00.000Z',
  },
  {
    $id: 'app-functions-cron',
    name: 'Functions Scheduler',
    slug: 'functions-scheduler',
    description:
      'Visual cron builder with timezone support, retry policies, and execution history for Appwrite Functions.',
    shortDescription: 'Advanced scheduling for functions',
    category: 'devtools',
    author: 'Appwrite',
    installs: 10300,
    rating: 4.9,
    featured: false,
    isOfficial: true,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['functions', 'cron', 'scheduler'],
    $createdAt: '2024-12-05T14:00:00.000Z',
  },
  {
    $id: 'app-slack-notify',
    name: 'Slack Notifications',
    slug: 'slack-notifications',
    description:
      'Send Auth, Database, and Function events to Slack channels. Filter by project, severity, and custom templates.',
    shortDescription: 'Slack alerts for project events',
    category: 'messaging',
    author: 'Slack',
    installs: 6700,
    rating: 4.5,
    featured: false,
    isOfficial: false,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['slack', 'notifications', 'webhooks'],
    $createdAt: '2024-08-28T16:20:00.000Z',
  },
  {
    $id: 'app-vercel-deploy',
    name: 'Vercel Deploy',
    slug: 'vercel-deploy',
    description:
      'Connect Appwrite Sites to Vercel previews and production. Sync environment variables and trigger deploys on push.',
    shortDescription: 'Deploy sites with Vercel',
    category: 'devtools',
    author: 'Vercel',
    installs: 9400,
    rating: 4.7,
    featured: true,
    isOfficial: false,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['vercel', 'sites', 'deploy'],
    $createdAt: '2024-09-18T10:15:00.000Z',
  },
  {
    $id: 'app-r2-sync',
    name: 'Cloudflare R2 Sync',
    slug: 'cloudflare-r2-sync',
    description:
      'Replicate Storage files to Cloudflare R2 buckets with incremental sync and bandwidth-friendly transfers.',
    shortDescription: 'Sync buckets to Cloudflare R2',
    category: 'storage',
    author: 'Cloudflare',
    installs: 4800,
    rating: 4.6,
    featured: false,
    isOfficial: false,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['cloudflare', 'r2', 'storage'],
    $createdAt: '2024-10-01T12:00:00.000Z',
  },
  {
    $id: 'app-paddle-billing',
    name: 'Paddle Billing',
    slug: 'paddle-billing',
    description:
      'Sell subscriptions globally with Paddle tax compliance. Map plans to Appwrite teams and sync subscription webhooks.',
    shortDescription: 'Global subscriptions with Paddle',
    category: 'payments',
    author: 'Paddle',
    installs: 3600,
    rating: 4.4,
    featured: false,
    isOfficial: false,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['paddle', 'payments', 'billing'],
    $createdAt: '2024-07-22T09:30:00.000Z',
  },
  {
    $id: 'app-linear-sync',
    name: 'Linear Issues Sync',
    slug: 'linear-issues-sync',
    description:
      'Create Linear issues from function failures and user feedback forms. Link issues to executions and deployments.',
    shortDescription: 'Linear integration for dev teams',
    category: 'devtools',
    author: 'Linear',
    installs: 2900,
    rating: 4.5,
    featured: false,
    isOfficial: false,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['linear', 'issues', 'devtools'],
    $createdAt: '2024-10-28T15:45:00.000Z',
  },
  {
    $id: 'app-anthropic-kit',
    name: 'Anthropic Claude Kit',
    slug: 'anthropic-claude-kit',
    description:
      'Run Claude models from Functions with prompt templates, token budgeting, and Tables DB conversation storage.',
    shortDescription: 'Claude AI in your functions',
    category: 'ai',
    author: 'Anthropic',
    installs: 5500,
    rating: 4.8,
    featured: false,
    isOfficial: false,
    isVerified: true,
    isOwned: false,
    status: 'published',
    tags: ['ai', 'claude', 'anthropic'],
    $createdAt: '2025-01-08T08:00:00.000Z',
  },
]

export const MOCK_MARKETPLACE_CATALOG: MarketplaceApp[] =
  MOCK_MARKETPLACE_CATALOG_RAW.map(withMarketplaceCreators)

/** Apps published by the current organization (mock). */
const MOCK_MARKETPLACE_OWNED_RAW: MarketplaceAppWithoutCreators[] = [
  {
    $id: 'app-acme-webhooks',
    name: 'Acme Webhook Router',
    slug: 'acme-webhook-router',
    description:
      'Route Appwrite webhooks to internal services with retries, signing, and dead-letter queues.',
    shortDescription: 'Reliable webhook routing for teams',
    category: 'devtools',
    author: 'Your organization',
    installs: 42,
    rating: 4.2,
    featured: false,
    isOfficial: false,
    isVerified: false,
    isOwned: true,
    status: 'published',
    tags: ['webhooks', 'internal'],
    $createdAt: '2025-01-10T09:00:00.000Z',
  },
  {
    $id: 'app-acme-onboarding',
    name: 'Onboarding Checklist',
    slug: 'onboarding-checklist',
    description:
      'Guide new users through profile setup with a checklist stored in Tables DB and nudges via Messaging.',
    shortDescription: 'User onboarding flows',
    category: 'devtools',
    author: 'Your organization',
    installs: 18,
    rating: 4.0,
    featured: false,
    isOfficial: false,
    isVerified: false,
    isOwned: true,
    status: 'draft',
    tags: ['onboarding', 'ux'],
    $createdAt: '2025-02-28T15:30:00.000Z',
  },
]

export const MOCK_MARKETPLACE_OWNED: MarketplaceApp[] =
  MOCK_MARKETPLACE_OWNED_RAW.map(withMarketplaceCreators)

export function getMockMarketplaceApps(): MarketplaceApp[] {
  return [...MOCK_MARKETPLACE_CATALOG, ...MOCK_MARKETPLACE_OWNED]
}
