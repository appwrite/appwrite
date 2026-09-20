import { isStartPlanEligibleCountry, normalizeCountryCode } from '@/lib/pricing/start-plan'

export type VisitorCountryQueryState = {
  visitorFetched: boolean
  visitorError: boolean
  visitorFetching: boolean
  localeSuccess: boolean
  localeError: boolean
  localeFetching: boolean
  mockCountry?: string | null
}

export function mergeVisitorCountryCode(sources: {
  mockCountry?: string | null
  requestCountry?: string | null
  visitorQueryCountry?: string | null
  localeCountry?: string | null
  storedCountry?: string | null
}): string | null {
  return (
    normalizeCountryCode(sources.mockCountry) ??
    normalizeCountryCode(sources.localeCountry) ??
    normalizeCountryCode(
      typeof sources.visitorQueryCountry === 'string'
        ? sources.visitorQueryCountry
        : null,
    ) ??
    normalizeCountryCode(sources.requestCountry) ??
    normalizeCountryCode(sources.storedCountry)
  )
}

/**
 * Pricing waits for a successful locale.get(). `isFetched` / `isError` are not
 * enough: retry is off globally, so an aborted or failed locale call would
 * paint the 3-plan grid from CDN/cookie geo, then the parallel visitor-country
 * locale.get() would swap in the Indian 4-plan grid.
 */
export function isVisitorCountryResolutionComplete(
  _countryCode: string | null,
  _requestCountry: string | null,
  queries: VisitorCountryQueryState,
): boolean {
  if (normalizeCountryCode(queries.mockCountry)) return true
  if (queries.localeSuccess) return true
  if (queries.localeFetching) return false
  if (!queries.localeError) return false
  if (queries.visitorFetching) return false
  return queries.visitorFetched || queries.visitorError
}

/** Empty reserved shell until locale.get() (or debug mock) has settled. */
export function isPricingPlanGridReady(
  _countryCode: string | null,
  resolutionComplete: boolean,
): boolean {
  return resolutionComplete
}

export function shouldShowStartPlan(
  ready: boolean,
  countryCode: string | null,
): boolean {
  return ready && isStartPlanEligibleCountry(countryCode)
}
