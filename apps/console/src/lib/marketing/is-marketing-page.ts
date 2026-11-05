export {
  MARKETING_PAGE_PATHS,
  normalizeMarketingPath,
} from '@/lib/marketing/marketing-page-paths'
import { isMarketingPagePath } from '@/lib/marketing/is-marketing-page-path'
import { isMarketingRouteMatch } from '@/lib/marketing/route-static-data'

export { isMarketingPagePath } from '@/lib/marketing/is-marketing-page-path'
export {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  isMarketingRouteMatch,
} from '@/lib/marketing/route-static-data'

type MarketingRouteInput = {
  pathname?: string
  matches?: Array<{ staticData?: unknown }>
}

/**
 * Central runtime marketing-page check.
 *
 * Prefer route staticData when matches are available, and fall back to pathname
 * rules for code that runs outside router context (redirects, 404s, panes, etc.).
 */
export function isMarketingPage({
  pathname,
  matches,
}: MarketingRouteInput): boolean {
  return (
    matches?.some(isMarketingRouteMatch) ||
    (pathname ? isMarketingPagePath(pathname) : false)
  )
}
