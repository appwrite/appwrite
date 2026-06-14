export const MARKETING_SITE_ORIGIN = 'https://appwrite.io'

export type MarketingPagePath =
  | '/terms'
  | '/privacy'
  | '/cookies'
  | '/company'
  | '/assets'
  | '/pricing'
  | '/partners'
  | '/education'
  | '/startups'
  | '/enterprise'
  | '/community'
  | '/docs'
  | '/changelog'
  | '/domains'
  | '/home'

/**
 * Resolves a marketing page path to a relative route when marketing is enabled,
 * or to the production appwrite.io URL when marketing routes are disabled.
 */
export function getMarketingPageUrl(
  path: MarketingPagePath,
  marketingEnabled: boolean,
): string {
  return marketingEnabled ? path : `${MARKETING_SITE_ORIGIN}${path}`
}

export function isMarketingPageExternal(marketingEnabled: boolean): boolean {
  return !marketingEnabled
}
