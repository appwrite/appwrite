/** Browser-safe marketing URL list (shared with build-time prerender config). */
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
  '/domains',
  '/integrations',
  '/llms/txt',
  '/llms-full/txt',
] as const

export function normalizeMarketingPath(pathname: string): string {
  return pathname.replace(/\/+$/, '') || '/'
}
