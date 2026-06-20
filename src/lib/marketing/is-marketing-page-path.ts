import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  MARKETING_PAGE_PATHS,
  normalizeMarketingPath,
} from '@/lib/marketing/marketing-page-paths'

/**
 * Path-based marketing page detection for client/runtime code.
 * Kept separate from marketing-page-paths.ts so vite.config can import the path
 * list without pulling in profile/runtime modules before aliases are available.
 */
export function isMarketingPagePath(pathname: string): boolean {
  const normalized = normalizeMarketingPath(pathname)

  if (!getActiveProfileFeatures().marketing) {
    return false
  }

  if (normalized === '/docs' || normalized.startsWith('/docs/')) {
    return true
  }

  if (normalized === '/changelog' || normalized.startsWith('/changelog/')) {
    return true
  }

  if (normalized === '/blog' || normalized.startsWith('/blog/')) {
    return true
  }

  if (normalized === '/threads' || normalized.startsWith('/threads/')) {
    return true
  }

  if (normalized.startsWith('/products/')) {
    return true
  }

  return (MARKETING_PAGE_PATHS as readonly string[]).includes(normalized)
}
