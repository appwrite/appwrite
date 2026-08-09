import { isMarketingPagePath } from '@/lib/marketing/is-marketing-page-path'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  type MarketingPageRouteStaticData,
} from '@/lib/marketing/route-static-data'

const CONSOLE_AREA_PREFIXES = new Set([
  'projects',
  'organizations',
  'account',
  'blocks',
  'init',
  'generator',
  'assistant',
  'agent',
])

function isConsoleAreaPath(pathname: string): boolean {
  const firstSegment = pathname.split('/').filter(Boolean)[0]
  return firstSegment ? CONSOLE_AREA_PREFIXES.has(firstSegment) : false
}

function isConsoleAuthRouteMatch(
  matches: Array<{ routeId?: string }>,
): boolean {
  return matches.some(
    (match) =>
      match.routeId === '/_auth' || match.routeId?.startsWith('/_auth/'),
  )
}

function isExcludedMarketingSiteLayoutPath(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  if (normalized === '/docs' || normalized.startsWith('/docs/')) return true
  if (normalized === '/generator' || normalized.startsWith('/generator/')) {
    return true
  }
  if (normalized === '/debug' || normalized.startsWith('/debug/')) return true
  if (normalized === '/reset') return true
  if (normalized === '/access') return true
  return false
}

export type MarketingRouteShellStaticData = MarketingPageRouteStaticData & {
  showFooter?: boolean
  expandedFooter?: boolean
  headerBanner?: 'init-org-promo'
}

export type MarketingRouteShellOptions = {
  showFooter: boolean
  expandedFooter: boolean
  headerBanner?: 'init-org-promo'
}

function isMarketingRouteShellStaticData(
  value: unknown,
): value is MarketingRouteShellStaticData {
  if (!value || typeof value !== 'object') return false
  return (
    (value as MarketingRouteShellStaticData).pageType ===
    MARKETING_PAGE_ROUTE_STATIC_DATA.pageType
  )
}

export function resolveMarketingRouteShellOptions(
  matches: Array<{ staticData?: unknown }>,
): MarketingRouteShellOptions | null {
  for (let index = matches.length - 1; index >= 0; index -= 1) {
    const staticData = matches[index]?.staticData
    if (!isMarketingRouteShellStaticData(staticData)) continue

    return {
      showFooter: staticData.showFooter ?? true,
      expandedFooter: staticData.expandedFooter ?? true,
      headerBanner: staticData.headerBanner,
    }
  }

  return null
}

export function shouldUseMarketingSiteLayout({
  marketingEnabled,
  pathname,
  matches,
}: {
  marketingEnabled: boolean
  pathname: string
  matches: Array<{ staticData?: unknown }>
}): boolean {
  if (!marketingEnabled) return false
  if (isConsoleAuthRouteMatch(matches)) return false
  if (isExcludedMarketingSiteLayoutPath(pathname)) return false

  if (resolveMarketingRouteShellOptions(matches) !== null) {
    return true
  }

  return isMarketingPagePath(pathname) || !isConsoleAreaPath(pathname)
}
