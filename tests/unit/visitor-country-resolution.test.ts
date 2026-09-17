import { describe, expect, it } from 'bun:test'
import {
  isPricingPlanGridReady,
  isVisitorCountryResolutionComplete,
  mergeVisitorCountryCode,
  shouldShowStartPlan,
} from '@/lib/pricing/visitor-country-resolution'

describe('mergeVisitorCountryCode', () => {
  it('prefers SSR/cookie request geo over async query data', () => {
    expect(
      mergeVisitorCountryCode({
        requestCountry: 'IN',
        visitorQueryCountry: 'US',
        localeCountry: 'US',
      }),
    ).toBe('IN')
  })

  it('falls back to locale query when request geo is missing', () => {
    expect(
      mergeVisitorCountryCode({
        requestCountry: null,
        visitorQueryCountry: null,
        localeCountry: 'IN',
      }),
    ).toBe('IN')
  })
})

describe('isVisitorCountryResolutionComplete', () => {
  const pending = {
    visitorFetched: false,
    visitorError: false,
    localeFetched: false,
    localeError: false,
  }

  it('stays incomplete when only the locale query has settled without a country', () => {
    expect(
      isVisitorCountryResolutionComplete(null, null, {
        ...pending,
        localeFetched: true,
      }),
    ).toBe(false)
  })

  it('completes once both async sources have settled without a country', () => {
    expect(
      isVisitorCountryResolutionComplete(null, null, {
        visitorFetched: true,
        visitorError: false,
        localeFetched: true,
        localeError: false,
      }),
    ).toBe(true)
  })

  it('completes immediately when request geo is present', () => {
    expect(
      isVisitorCountryResolutionComplete(null, 'IN', pending),
    ).toBe(true)
  })
})

describe('pricing plan grid readiness', () => {
  it('keeps the shell until country is known or all sources finish', () => {
    expect(isPricingPlanGridReady(null, false)).toBe(false)
    expect(isPricingPlanGridReady('IN', false)).toBe(true)
    expect(isPricingPlanGridReady(null, true)).toBe(true)
  })

  it('shows Start only for eligible countries after ready', () => {
    expect(shouldShowStartPlan(true, 'IN')).toBe(true)
    expect(shouldShowStartPlan(true, 'US')).toBe(false)
    expect(shouldShowStartPlan(false, 'IN')).toBe(false)
  })

  it('never treats a partial locale fetch as the final 3-plan grid for IN visitors', () => {
    const countryCode = mergeVisitorCountryCode({
      requestCountry: null,
      visitorQueryCountry: null,
      localeCountry: null,
    })
    const resolutionComplete = isVisitorCountryResolutionComplete(
      countryCode,
      null,
      {
        visitorFetched: false,
        visitorError: false,
        localeFetched: true,
        localeError: false,
      },
    )
    const ready = isPricingPlanGridReady(countryCode, resolutionComplete)

    expect(ready).toBe(false)
    expect(shouldShowStartPlan(ready, countryCode)).toBe(false)
  })
})
