import {
  isConsoleRedirectHopPath,
  isRootHomeMatch,
  isRootRedirectPath,
} from '@/lib/root-guest-redirect'
import { matchesMarketingPagePath } from '@/lib/marketing/is-marketing-page-path'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  type MarketingPageRouteStaticData,
} from '@/lib/marketing/route-static-data'

const CONSOLE_AREA_PREFIXES = new Set([
  'projects',
  'organizations',
  'account',
  'blocks',
  'impersonate',
  'init',
  'generator',
  'assistant',
  'agent',
  'upgrade',
])

const CONSOLE_AUTH_EXACT_PATHS = new Set([
  '/sign-in',
  '/sign-up',
  '/sign-out',
  '/recovery',
  '/join',
  '/mfa',
  '/verify-email',
  '/education/join',
])

export function isConsoleAreaPath(pathname: string): boolean {
  const firstSegment = pathname.split('/').filter(Boolean)[0]
  return firstSegment ? CONSOLE_AREA_PREFIXES.has(firstSegment) : false
}

function normalizeShellPath(pathname: string): string {
  return pathname.replace(/\/+$/, '') || '/'
}

export function isConsoleAuthPath(pathname: string): boolean {
  const normalized = normalizeShellPath(pathname)
  if (CONSOLE_AUTH_EXACT_PATHS.has(normalized)) return true
  if (normalized === '/auth' || normalized.startsWith('/auth/')) return true
  if (normalized === '/oauth2' || normalized.startsWith('/oauth2/')) return true
  return false
}

function isConsoleAuthRouteMatch(
  matches: Array<{ routeId?: string }>,
): boolean {
  return matches.some(
    (match) =>
      match.routeId === '/_auth' || match.routeId?.startsWith('/_auth/'),
  )
}

export function isExcludedMarketingSiteLayoutPath(
  pathname: string,
  { rootHome = false }: { rootHome?: boolean } = {},
): boolean {
  if (isConsoleRedirectHopPath(pathname)) {
    return !(rootHome && isRootRedirectPath(pathname))
  }
  const normalized = normalizeShellPath(pathname)
  if (isConsoleAuthPath(normalized)) return true
  if (normalized === '/generator' || normalized.startsWith('/generator/')) {
    return true
  }
  if (normalized === '/upgrade' || normalized.startsWith('/upgrade/')) {
    return true
  }
  if (normalized === '/debug' || normalized.startsWith('/debug/')) return true
  if (normalized === '/reset') return true
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
  matches: Array<{
    staticData?: unknown
    routeId?: string
    status?: string
    loaderData?: unknown
  }>
}): boolean {
  if (isConsoleAuthRouteMatch(matches)) return false
  if (
    isExcludedMarketingSiteLayoutPath(pathname, {
      rootHome: isRootHomeMatch(matches),
    })
  ) {
    return false
  }

  if (resolveMarketingRouteShellOptions(matches) !== null) {
    return true
  }

  if (!marketingEnabled) return false

  return matchesMarketingPagePath(pathname) || !isConsoleAreaPath(pathname)
}
