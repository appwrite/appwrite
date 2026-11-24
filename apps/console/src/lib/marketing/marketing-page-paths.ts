import { ALTERNATIVE_IDS, getAlternativePath } from '../alternatives/registry'
import { SECRET_CAMPAIGN_PATHS } from '../campaigns/secret/registry'

/** Browser-safe marketing URL list (shared with build-time prerender config). */
const ALTERNATIVE_PAGE_PATHS = ALTERNATIVE_IDS.map((id) =>
  getAlternativePath(id),
)

export const MARKETING_PAGE_PATHS = [
  '/home',
  '/pricing',
  '/privacy',
  '/terms',
  '/cookies',
  '/baa',
  '/company',
  '/startups',
  '/agencies',
  '/innovation-teams',
  '/education',
  '/for-agents',
  '/zero',
  '/partners',
  '/enterprise',
  '/affiliates',
  '/community',
  '/changelog',
  '/blog',
  '/assets',
  '/products/auth',
  '/products/databases',
  '/products/postgres',
  '/products/storage',
  '/products/functions',
  '/products/messaging',
  '/products/realtime',
  '/products/sites',
  '/products/analytics',
  '/products/firewall',
  ...ALTERNATIVE_PAGE_PATHS,
  ...SECRET_CAMPAIGN_PATHS,
  '/domains',
  '/integrations',
  '/llms/txt',
  '/llms-full/txt',
] as const

/**
 * Marketing pages behind a launch feature flag. They render on demand, so the
 * flag decides per request, and stay out of prerendering and the sitemap until
 * launch, when they leave this list.
 */
export const UNLAUNCHED_MARKETING_PATHS: readonly string[] = ['/zero']

export function normalizeMarketingPath(pathname: string): string {
  return pathname.replace(/\/+$/, '') || '/'
}
