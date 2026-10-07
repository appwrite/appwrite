import type { AlternativeId } from '@/lib/alternatives/types'

/** Appwrite products shown in the "everything in one platform" breakdown. Ids match `PRODUCT_NAV_REGISTRY`. */
export type PlatformProductId =
  | 'auth'
  | 'databases'
  | 'postgres'
  | 'storage'
  | 'functions'
  | 'realtime'
  | 'messaging'
  | 'sites'
  | 'domains'
  | 'firewall'

export type PlatformCoverage = 'yes' | 'partial' | 'no'

export const PLATFORM_PRODUCTS: { id: PlatformProductId; name: string; blurb: string }[] = [
  { id: 'auth', name: 'Auth', blurb: 'Sign-in, MFA, and teams' },
  { id: 'databases', name: 'Databases', blurb: 'Tables, documents, and vectors' },
  { id: 'postgres', name: 'PostgreSQL', blurb: 'Managed Postgres and MySQL' },
  { id: 'storage', name: 'Storage', blurb: 'Files and image transforms' },
  { id: 'functions', name: 'Functions', blurb: '13+ language runtimes' },
  { id: 'realtime', name: 'Realtime', blurb: 'Events from every service' },
  { id: 'messaging', name: 'Messaging', blurb: 'Email, SMS, and push' },
  { id: 'sites', name: 'Sites', blurb: 'Static and SSR hosting' },
  { id: 'domains', name: 'Domains', blurb: 'Buy and manage domains' },
  { id: 'firewall', name: 'Firewall', blurb: 'Rules for your traffic' },
]

/**
 * Whether each competitor offers a first-party equivalent of an Appwrite product.
 * `partial` means a narrower product (for example a single function language), a beta,
 * or a marketplace partner instead of a first-party service.
 */
export const PLATFORM_COVERAGE: Record<AlternativeId, Record<PlatformProductId, PlatformCoverage>> = {
  supabase: {
    auth: 'yes',
    databases: 'yes',
    postgres: 'yes',
    storage: 'yes',
    functions: 'partial',
    realtime: 'yes',
    messaging: 'no',
    sites: 'no',
    domains: 'no',
    firewall: 'no',
  },
  firebase: {
    auth: 'yes',
    databases: 'yes',
    postgres: 'no',
    storage: 'yes',
    functions: 'partial',
    realtime: 'yes',
    messaging: 'partial',
    sites: 'yes',
    domains: 'no',
    firewall: 'no',
  },
  vercel: {
    auth: 'no',
    databases: 'partial',
    postgres: 'partial',
    storage: 'partial',
    functions: 'yes',
    realtime: 'no',
    messaging: 'no',
    sites: 'yes',
    domains: 'yes',
    firewall: 'yes',
  },
  netlify: {
    auth: 'partial',
    databases: 'partial',
    postgres: 'yes',
    storage: 'partial',
    functions: 'partial',
    realtime: 'no',
    messaging: 'no',
    sites: 'yes',
    domains: 'yes',
    firewall: 'partial',
  },
  neon: {
    auth: 'yes',
    databases: 'partial',
    postgres: 'yes',
    storage: 'yes',
    functions: 'partial',
    realtime: 'no',
    messaging: 'no',
    sites: 'no',
    domains: 'no',
    firewall: 'no',
  },
  auth0: {
    auth: 'yes',
    databases: 'no',
    postgres: 'no',
    storage: 'no',
    functions: 'no',
    realtime: 'no',
    messaging: 'no',
    sites: 'no',
    domains: 'no',
    firewall: 'no',
  },
  convex: {
    auth: 'partial',
    databases: 'yes',
    postgres: 'no',
    storage: 'yes',
    functions: 'partial',
    realtime: 'yes',
    messaging: 'no',
    sites: 'no',
    domains: 'no',
    firewall: 'no',
  },
  cloudinary: {
    auth: 'no',
    databases: 'no',
    postgres: 'no',
    storage: 'yes',
    functions: 'no',
    realtime: 'no',
    messaging: 'no',
    sites: 'no',
    domains: 'no',
    firewall: 'no',
  },
  clerk: {
    auth: 'yes',
    databases: 'no',
    postgres: 'no',
    storage: 'no',
    functions: 'no',
    realtime: 'no',
    messaging: 'no',
    sites: 'no',
    domains: 'no',
    firewall: 'no',
  },
  amplify: {
    auth: 'yes',
    databases: 'yes',
    postgres: 'partial',
    storage: 'yes',
    functions: 'partial',
    realtime: 'yes',
    messaging: 'partial',
    sites: 'yes',
    domains: 'partial',
    firewall: 'yes',
  },
  planetscale: {
    auth: 'no',
    databases: 'no',
    postgres: 'yes',
    storage: 'no',
    functions: 'no',
    realtime: 'no',
    messaging: 'no',
    sites: 'no',
    domains: 'no',
    firewall: 'no',
  },
  // Analytics tools: no backend products. PostHog's data warehouse and hog
  // functions are analytics pipelines, not app databases or compute.
  posthog: {
    auth: 'no',
    databases: 'no',
    postgres: 'no',
    storage: 'no',
    functions: 'no',
    realtime: 'no',
    messaging: 'no',
    sites: 'no',
    domains: 'no',
    firewall: 'no',
  },
  plausible: {
    auth: 'no',
    databases: 'no',
    postgres: 'no',
    storage: 'no',
    functions: 'no',
    realtime: 'no',
    messaging: 'no',
    sites: 'no',
    domains: 'no',
    firewall: 'no',
  },
}

/** How each competitor ships its source, shown next to Appwrite being open source. */
export const COMPETITOR_SOURCE: Record<AlternativeId, { status: string; detail: string }> = {
  supabase: {
    status: 'Self-hosting left to you',
    detail: 'Self-hosted Supabase is community supported and runs one project per instance.',
  },
  firebase: {
    status: 'Proprietary',
    detail: 'Closed source and cloud only. Only the client SDKs and emulators are open.',
  },
  vercel: {
    status: 'Proprietary',
    detail: 'The platform is closed source and runs only on Vercel.',
  },
  netlify: {
    status: 'Proprietary',
    detail: 'The platform is closed source and runs only on Netlify.',
  },
  neon: {
    status: 'Not self-hostable as a platform',
    detail: 'The storage engine is public, but Auth, Functions, and the rest of the platform are not offered for self-hosting.',
  },
  auth0: {
    status: 'Proprietary',
    detail: 'Closed source. Even Private Cloud deployments are operated by Okta.',
  },
  convex: {
    status: 'Not open source',
    detail: 'Source-available under the Functional Source License, which is not an open source license.',
  },
  cloudinary: {
    status: 'Proprietary',
    detail: 'Closed source and cloud only.',
  },
  clerk: {
    status: 'Proprietary',
    detail: 'The SDKs are public, but the service that stores your users is closed source and runs only on Clerk.',
  },
  amplify: {
    status: 'Runs only on AWS',
    detail: 'The Amplify libraries are open source, but every backend service behind them runs only on AWS.',
  },
  planetscale: {
    status: 'Platform not self-hostable',
    detail: 'Vitess is open source, but the PlanetScale platform, branching, and Postgres service run only on PlanetScale.',
  },
  posthog: {
    status: 'Open source',
    detail: 'The core is MIT licensed and can be self-hosted for smaller deployments.',
  },
  plausible: {
    status: 'Open source',
    detail: 'Community Edition is AGPL licensed and self-hostable, with fewer features than the cloud service.',
  },
}
