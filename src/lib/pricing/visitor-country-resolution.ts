import { isStartPlanEligibleCountry, normalizeCountryCode } from '@/lib/pricing/start-plan'

export type VisitorCountryQueryState = {
  visitorFetched: boolean
  visitorError: boolean
  localeFetched: boolean
  localeError: boolean
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
    normalizeCountryCode(sources.requestCountry) ??
    normalizeCountryCode(
      typeof sources.visitorQueryCountry === 'string'
        ? sources.visitorQueryCountry
        : null,
    ) ??
    normalizeCountryCode(sources.localeCountry) ??
    normalizeCountryCode(sources.storedCountry)
  )
}

/**
 * True once every async country source has finished and no sync request geo remains
 * pending. Sync SSR/cookie/mock signals resolve immediately.
 */
export function isVisitorCountryResolutionComplete(
  countryCode: string | null,
  requestCountry: string | null,
  queries: VisitorCountryQueryState,
): boolean {
  if (countryCode !== null) return true
  if (requestCountry !== null) return true

  const visitorDone = queries.visitorFetched || queries.visitorError
  const localeDone = queries.localeFetched || queries.localeError
  return visitorDone && localeDone
}

/** Pricing grids stay in the reserved shell until country is known or all sources fail. */
export function isPricingPlanGridReady(
  countryCode: string | null,
  resolutionComplete: boolean,
): boolean {
  return countryCode !== null || resolutionComplete
}

export function shouldShowStartPlan(
  ready: boolean,
  countryCode: string | null,
): boolean {
  return ready && isStartPlanEligibleCountry(countryCode)
}
