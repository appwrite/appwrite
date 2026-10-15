/** Premium Geo DB launch announcement (promo Learn more links). */
export const PREMIUM_GEO_PROMO_LEARN_MORE_PATH =
  '/blog/post/announcing-premium-geo-db'

/** Why copy shared by promos and Enable Premium Geo DB dialog. */
export const PREMIUM_GEO_PROMO_DESCRIPTION =
  'Richer geolocation on users and usage for better security and observability.'

/** Fields highlighted in Premium Geo DB promo surfaces. */
export const PREMIUM_GEO_PROMO_HINTS = [
  'City',
  'ISP',
  'ASN',
  'Connection type',
] as const

/**
 * Projects created before this instant are eligible for the overview promo
 * banner (existing projects before the create-project add-on shipped).
 */
export const PREMIUM_GEO_OVERVIEW_PROMO_CUTOFF_ISO = '2026-10-01T00:00:00.000Z'

export function isProjectEligibleForPremiumGeoOverviewBanner(
  createdAt: string | undefined,
): boolean {
  if (!createdAt?.trim()) return false
  const created = new Date(createdAt).getTime()
  const cutoff = new Date(PREMIUM_GEO_OVERVIEW_PROMO_CUTOFF_ISO).getTime()
  if (Number.isNaN(created) || Number.isNaN(cutoff)) return false
  return created < cutoff
}
