export const MARKETING_PAGE_ROUTE_STATIC_DATA = {
  pageType: 'marketing',
} as const

export type MarketingPageRouteStaticData =
  typeof MARKETING_PAGE_ROUTE_STATIC_DATA

export function isMarketingRouteMatch(match: {
  staticData?: unknown
}): boolean {
  const data = match.staticData as { pageType?: string } | undefined
  return data?.pageType === MARKETING_PAGE_ROUTE_STATIC_DATA.pageType
}
