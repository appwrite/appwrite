export const MARKETING_PAGE_ROUTE_STATIC_DATA = {
  pageType: 'marketing',
} as const

/**
 * Keep marketing SPA navigations on already-loaded route data.
 * Child `ssr: true` routes otherwise re-run loaders (and often a server
 * round-trip) on every click, which resets header auth queries.
 */
export const marketingRouteLifetime = {
  staleTime: Number.POSITIVE_INFINITY,
  preloadStaleTime: Number.POSITIVE_INFINITY,
  shouldReload: () => false,
} as const

export type MarketingPageRouteStaticData =
  typeof MARKETING_PAGE_ROUTE_STATIC_DATA

export function isMarketingRouteMatch(match: {
  staticData?: unknown
}): boolean {
  const data = match.staticData as { pageType?: string } | undefined
  return data?.pageType === MARKETING_PAGE_ROUTE_STATIC_DATA.pageType
}
