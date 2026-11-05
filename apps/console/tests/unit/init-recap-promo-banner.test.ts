import { describe, expect, it } from 'bun:test'
import {
  getConsoleBannerById,
  getConsoleBannerScheduleStatus,
  INIT_RECAP_PROMO_BANNER_ID,
} from '@/lib/console-banners/catalog'
import {
  isInitRecapPromoPath,
  shouldHideInitRecapForHeaderPromo,
} from '@/lib/console-banners/init-recap-promo-path'

describe('Init recap promo banner', () => {
  const banner = getConsoleBannerById(INIT_RECAP_PROMO_BANNER_ID)!

  it('is scheduled through the end of the week of 8 Sep 2026', () => {
    expect(
      getConsoleBannerScheduleStatus(banner, new Date('2026-09-08T12:00:00Z')),
    ).toBe('active')
    expect(
      getConsoleBannerScheduleStatus(
        banner,
        new Date('2026-09-13T23:59:59.999Z'),
      ),
    ).toBe('active')
    expect(
      getConsoleBannerScheduleStatus(banner, new Date('2026-09-14T00:00:00Z')),
    ).toBe('expired')
  })

  it('starts after Init week ends', () => {
    expect(
      getConsoleBannerScheduleStatus(banner, new Date('2026-09-04T23:59:59Z')),
    ).toBe('upcoming')
    expect(
      getConsoleBannerScheduleStatus(banner, new Date('2026-09-05T00:00:00Z')),
    ).toBe('active')
  })
})

describe('isInitRecapPromoPath', () => {
  it('allows console project, org, and account routes', () => {
    expect(isInitRecapPromoPath('/projects/abc/overview')).toBe(true)
    expect(isInitRecapPromoPath('/organizations/org/overview')).toBe(true)
    expect(isInitRecapPromoPath('/account')).toBe(true)
  })

  it('hides on Init, auth, and wizard routes', () => {
    expect(isInitRecapPromoPath('/init')).toBe(false)
    expect(isInitRecapPromoPath('/init/ticket-123')).toBe(false)
    expect(isInitRecapPromoPath('/sign-in')).toBe(false)
    expect(isInitRecapPromoPath('/projects/abc/databases/create')).toBe(false)
    expect(isInitRecapPromoPath('/projects/abc/apps/add')).toBe(false)
  })
})

describe('shouldHideInitRecapForHeaderPromo', () => {
  it('hides the recap on every console route while a header promo is visible', () => {
    expect(
      shouldHideInitRecapForHeaderPromo('/projects/abc/overview', true),
    ).toBe(true)
    expect(
      shouldHideInitRecapForHeaderPromo('/organizations/org/overview', true),
    ).toBe(true)
    expect(shouldHideInitRecapForHeaderPromo('/account', true)).toBe(true)
  })

  it('keeps the recap when no header promo is visible', () => {
    expect(
      shouldHideInitRecapForHeaderPromo('/projects/abc/overview', false),
    ).toBe(false)
    expect(
      shouldHideInitRecapForHeaderPromo('/organizations/org/overview', false),
    ).toBe(false)
  })
})
