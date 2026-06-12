import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { isMarketingPrerenderPath } from '@/lib/marketing/prerender-paths'

export function normalizeMarketingPath(pathname: string): string {
  return pathname.replace(/\/+$/, '') || '/'
}

/**
 * Path-based marketing page detection. Keeps /docs public even when the
 * marketing profile flag is off; other marketing URLs require marketing routes.
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

  return isMarketingPrerenderPath(pathname)
}
