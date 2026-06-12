import { getActiveProfileFeatures } from '@/lib/console-profiles'

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
  '/partners',
  '/enterprise',
  '/community',
  '/changelog',
  '/assets',
  '/llms/txt',
  '/llms-full/txt',
] as const

export function normalizeMarketingPath(pathname: string): string {
  return pathname.replace(/\/+$/, '') || '/'
}

/**
 * Path-based marketing page detection for client/runtime code.
 * Does not import Node-only build modules.
 */
export function isMarketingPagePath(pathname: string): boolean {
  const normalized = normalizeMarketingPath(pathname)

  if (normalized === '/docs' || normalized.startsWith('/docs/')) {
    return true
  }

  if (!getActiveProfileFeatures().marketing) {
    return false
  }

  if (normalized === '/changelog' || normalized.startsWith('/changelog/')) {
    return true
  }

  return (MARKETING_PAGE_PATHS as readonly string[]).includes(normalized)
}
