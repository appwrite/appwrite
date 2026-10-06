import { ALTERNATIVE_IDS, getAlternativePath } from '../alternatives/registry'

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
  '/education',
  '/for-agents',
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
  '/products/firewall',
  ...ALTERNATIVE_PAGE_PATHS,
  '/domains',
  '/integrations',
  '/llms/txt',
  '/llms-full/txt',
] as const

export function normalizeMarketingPath(pathname: string): string {
  return pathname.replace(/\/+$/, '') || '/'
}
