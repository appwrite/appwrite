import { describe, expect, it } from 'bun:test'
import {
  isNonPayingConsoleCustomer,
  isStartPromoBannerCountry,
} from '@/lib/console-banners/start-promo-audience'

describe('isStartPromoBannerCountry', () => {
  it('matches every Start-eligible ISO code', () => {
    expect(isStartPromoBannerCountry('IN')).toBe(true)
    expect(isStartPromoBannerCountry('np')).toBe(true)
    expect(isStartPromoBannerCountry('NG')).toBe(true)
    expect(isStartPromoBannerCountry('pk')).toBe(true)
  })

  it('rejects other countries', () => {
    expect(isStartPromoBannerCountry('US')).toBe(false)
    expect(isStartPromoBannerCountry(null)).toBe(false)
  })
})

describe('isNonPayingConsoleCustomer', () => {
  it('treats free-tier-only organizations as non-paying', () => {
    expect(
      isNonPayingConsoleCustomer([
        { billingPlan: 'tier-0', billingPlanId: 'tier-0' },
      ]),
    ).toBe(true)
  })

  it('hides the banner when any organization is on a paid plan', () => {
    expect(
      isNonPayingConsoleCustomer([
        { billingPlan: 'tier-0', billingPlanId: 'tier-0' },
        { billingPlan: 'tier-1-1', billingPlanId: 'tier-1-1' },
      ]),
    ).toBe(false)
  })
})
