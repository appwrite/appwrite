import { describe, expect, it } from 'bun:test'
import {
  isPricingPlanGridReady,
  isVisitorCountryResolutionComplete,
  mergeVisitorCountryCode,
  shouldShowStartPlan,
  type VisitorCountryQueryState,
} from '@/lib/pricing/visitor-country-resolution'

const pending: VisitorCountryQueryState = {
  visitorFetched: false,
  visitorError: false,
  visitorFetching: false,
  localeSuccess: false,
  localeError: false,
  localeFetching: false,
}

describe('mergeVisitorCountryCode', () => {
  it('prefers the locale.get() country over request geo and the visitor query', () => {
    expect(
      mergeVisitorCountryCode({
        requestCountry: 'US',
        visitorQueryCountry: 'US',
        localeCountry: 'IN',
      }),
    ).toBe('IN')
  })

  it('prefers the debug mock country over every other source', () => {
    expect(
      mergeVisitorCountryCode({
        mockCountry: 'IN',
        requestCountry: 'US',
        visitorQueryCountry: 'US',
        localeCountry: 'US',
      }),
    ).toBe('IN')
  })

  it('falls back to request geo when the async queries have no country', () => {
    expect(
      mergeVisitorCountryCode({
        requestCountry: 'IN',
        visitorQueryCountry: null,
        localeCountry: null,
      }),
    ).toBe('IN')
  })
})

describe('isVisitorCountryResolutionComplete', () => {
  it('stays incomplete while locale.get() has not succeeded or failed', () => {
    expect(isVisitorCountryResolutionComplete(null, null, pending)).toBe(false)
    expect(
      isVisitorCountryResolutionComplete(null, null, {
        ...pending,
        localeFetching: true,
      }),
    ).toBe(false)
  })

  it('does not complete from request geo alone', () => {
    expect(isVisitorCountryResolutionComplete('IN', 'IN', pending)).toBe(false)
  })

  it('completes once locale.get() succeeds', () => {
    expect(
      isVisitorCountryResolutionComplete(null, null, {
        ...pending,
        localeSuccess: true,
      }),
    ).toBe(true)
  })

  it('completes immediately when a debug mock country is set', () => {
    expect(
      isVisitorCountryResolutionComplete(null, null, {
        ...pending,
        mockCountry: 'IN',
      }),
    ).toBe(true)
  })

  it('waits for the visitor query to settle after locale.get() fails', () => {
    const localeFailed = { ...pending, localeError: true }
    expect(isVisitorCountryResolutionComplete(null, null, localeFailed)).toBe(
      false,
    )
    expect(
      isVisitorCountryResolutionComplete(null, null, {
        ...localeFailed,
        visitorFetching: true,
      }),
    ).toBe(false)
    expect(
      isVisitorCountryResolutionComplete(null, null, {
        ...localeFailed,
        visitorFetched: true,
      }),
    ).toBe(true)
    expect(
      isVisitorCountryResolutionComplete(null, null, {
        ...localeFailed,
        visitorError: true,
      }),
    ).toBe(true)
  })
})

describe('pricing plan grid readiness', () => {
  it('keeps the shell until resolution completes, even with a known country', () => {
    expect(isPricingPlanGridReady(null, false)).toBe(false)
    expect(isPricingPlanGridReady('IN', false)).toBe(false)
    expect(isPricingPlanGridReady(null, true)).toBe(true)
    expect(isPricingPlanGridReady('IN', true)).toBe(true)
  })

  it('shows Start only for eligible countries after ready', () => {
    expect(shouldShowStartPlan(true, 'IN')).toBe(true)
    expect(shouldShowStartPlan(true, 'US')).toBe(false)
    expect(shouldShowStartPlan(false, 'IN')).toBe(false)
  })

  it('never paints the grid from request geo before locale.get() settles', () => {
    const countryCode = mergeVisitorCountryCode({
      requestCountry: 'US',
      visitorQueryCountry: null,
      localeCountry: null,
    })
    const resolutionComplete = isVisitorCountryResolutionComplete(
      countryCode,
      'US',
      { ...pending, visitorFetched: true },
    )
    const ready = isPricingPlanGridReady(countryCode, resolutionComplete)

    expect(ready).toBe(false)
    expect(shouldShowStartPlan(ready, countryCode)).toBe(false)
  })
})
